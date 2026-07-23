<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Search\ProjectSearchController;
use Porta\Search\SearchActions;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Ui\MenuItem;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Tui;

/**
 * File-tree filesystem operations invoked from the tree's right-click context
 * menu: new file/folder, rename, delete and copy (relative) path, sharing a
 * single-line name/confirm prompt and an I/O-error guard that flashes the status
 * bar. Rename/delete re-point any open buffer (keyed by realpath) so the editor
 * stays consistent.
 */
final class TreeOperations
{
    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly BufferManager $buffers,
        private readonly Translator $translator,
        private readonly BufferService $bufferService,
        private readonly Clipboard $clipboard,
        private readonly SearchActions $searchActions,
        private readonly ProjectSearchController $projectSearch,
        private readonly string $rootDir,
    ) {}

    /**
     * The right-click context-menu items for a tree node.
     *
     * @param FileTreeNode $node
     *
     * @return list<MenuItem>
     */
    public function contextItems(FileTreeNode $node): array
    {
        $items = [
            new MenuItem($this->translator->trans('New File…'), '', fn () => $this->treeNewFile($node)),
            new MenuItem($this->translator->trans('New Folder…'), '', fn () => $this->treeNewFolder($node)),
            MenuItem::separator(),
        ];
        // Folder-only: search / search-and-replace scoped to the clicked subtree.
        if ($node->isDir) {
            $items[] = new MenuItem($this->translator->trans('Find in Files…'), '', fn () => $this->searchActions->openFindInFolder($node->path));
            $items[] = new MenuItem($this->translator->trans('Replace in Files…'), '', fn () => $this->searchActions->openReplaceInFolder($node->path));
            $items[] = new MenuItem($this->translator->trans('Add to Search Scopes'), '', fn () => $this->projectSearch->addSearchScope($node->path));
            $items[] = MenuItem::separator();
        }
        $items[] = new MenuItem($this->translator->trans('Copy Path'), '', fn () => $this->copyAbsolutePath($node->path));
        $items[] = new MenuItem($this->translator->trans('Copy Relative Path'), '', fn () => $this->copyRelativePath($node->path));
        $items[] = MenuItem::separator();
        $items[] = new MenuItem($this->translator->trans('Rename…'), '', fn () => $this->treeRename($node));
        $items[] = new MenuItem($this->translator->trans('Delete…'), '', fn () => $this->treeDelete($node));
        $items[] = MenuItem::separator();
        if (!$node->isDir) {
            $items[] = new MenuItem($this->translator->trans('Open'), '', fn () => $this->bufferService->openFile($node->path, focus: OpenFocus::Editor));
        }
        $items[] = new MenuItem($this->translator->trans('Refresh'), '', fn () => $this->tree->refresh());

        return $items;
    }

    /**
     * Directory a new tree entry is created in: the node itself, or its parent.
     *
     * @param FileTreeNode $node
     */
    private function treeTargetDir(FileTreeNode $node): string
    {
        return $node->isDir ? $node->path : \dirname($node->path);
    }

    /** @param FileTreeNode $node */
    private function treeNewFile(FileTreeNode $node): void
    {
        $dir = $this->treeTargetDir($node);
        $this->promptName($this->translator->trans('New File'), '', function (string $name) use ($dir): void {
            if ('' === ($name = trim($name))) {
                return;
            }
            $path = $dir.'/'.$name;
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => $this->relativePath($path)]), function () use ($path): void {
                new Filesystem()->dumpFile($path, '');
                $this->tree->refresh();
                $this->bufferService->openFile($path, focus: OpenFocus::Editor);
                $this->notifier->success($this->translator->trans('Created %file%', ['%file%' => $this->relativePath($path)]));
            });
        });
    }

    /** @param FileTreeNode $node */
    private function treeNewFolder(FileTreeNode $node): void
    {
        $dir = $this->treeTargetDir($node);
        $this->promptName($this->translator->trans('New Folder'), '', function (string $name) use ($dir): void {
            if ('' === ($name = trim($name))) {
                return;
            }
            $path = $dir.'/'.$name;
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => $this->relativePath($path)]), function () use ($path): void {
                new Filesystem()->mkdir($path);
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->success($this->translator->trans('Created %file%', ['%file%' => $this->relativePath($path)]));
            });
        });
    }

    /** @param FileTreeNode $node */
    private function treeRename(FileTreeNode $node): void
    {
        $old = $node->path;
        $this->promptName($this->translator->trans('Rename'), $node->name, function (string $name) use ($old): void {
            $name = trim($name);
            if ('' === $name || $name === basename($old)) {
                return;
            }
            $new = \dirname($old).'/'.$name;
            // Buffers are keyed by realpath(); resolve the old path the same way
            // so an open file's buffer is re-pointed at the new name.
            $resolvedOld = realpath($old);
            $oldKey = $resolvedOld ? $resolvedOld : $old;
            $this->withFs($this->translator->trans('Cannot rename %file%', ['%file%' => $this->relativePath($old)]), function () use ($old, $new, $name, $oldKey): void {
                new Filesystem()->rename($old, $new); // no overwrite: a name clash is an error
                if ($this->buffers->has($oldKey)) {
                    $this->buffers->close($oldKey);
                    $this->bufferService->openFile($new);
                }
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->success($this->translator->trans('Renamed to %name%', ['%name%' => $name]));
            });
        });
    }

    /** @param FileTreeNode $node */
    private function treeDelete(FileTreeNode $node): void
    {
        $path = $node->path;
        $this->promptName($this->translator->trans('Delete %name%? (y/N)', ['%name%' => $node->name]), '', danger: true, onSubmit: function (string $answer) use ($path): void {
            if (!\in_array(strtolower(trim($answer)), ['y', 'yes'], strict: true)) {
                $this->tui->setFocus($this->tree);

                return;
            }
            // buffers are keyed by realpath()
            $resolved = realpath($path);
            $key = $resolved ? $resolved : $path;
            $this->withFs($this->translator->trans('Cannot delete %file%', ['%file%' => $this->relativePath($path)]), function () use ($path, $key): void {
                new Filesystem()->remove($path);
                if ($this->buffers->has($key)) {
                    $next = $this->buffers->close($key);
                    null !== $next ? $this->bufferService->showBuffer($next) : $this->editor->showDocument('', new Document(''));
                }
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->flash($this->translator->trans('Deleted %file%', ['%file%' => $this->relativePath($path)]));
            });
        });
    }

    private function copyAbsolutePath(string $path): void
    {
        $this->copyToClipboard($path);
    }

    private function copyRelativePath(string $path): void
    {
        $this->copyToClipboard($this->relativePath($path));
    }

    private function copyToClipboard(string $text): void
    {
        $this->clipboard->copy($text);
        $this->notifier->flash($this->translator->trans('Copied %text%', ['%text%' => $text]));
    }

    /** Focus the editor and open its single-line prompt (tree file-op names/confirmations). */
    private function promptName(string $label, string $initial, callable $onSubmit, bool $danger = false): void
    {
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt($label, $initial, $onSubmit, $danger);
        $this->tui->requestRender();
    }

    /** Run a filesystem mutation, flashing $failMessage if it raises an I/O error. */
    private function withFs(string $failMessage, callable $op): void
    {
        try {
            $op();
        } catch (IOExceptionInterface) {
            $this->notifier->error($failMessage);
        }
    }

    private function relativePath(string $path): string
    {
        return ProjectPaths::relative($this->rootDir, $path);
    }
}
