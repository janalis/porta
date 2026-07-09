<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\I18n\Translator;
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
 * stays consistent. General `Ide` operations it relies on (open a file, show a
 * buffer, relative path, clipboard) are injected as closures.
 */
final class TreeOperations
{
    /**
     * @param \Closure(string, bool): void $openFile        open a path (2nd arg focuses the editor)
     * @param \Closure(Buffer): void       $showBuffer      display an existing buffer
     * @param \Closure(string): string     $relativePath    project-relative form of a path
     * @param \Closure(string): void       $copyToClipboard copy text to the system clipboard
     * @param \Closure(string): void       $findInFolder    open a folder-scoped text search
     */
    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly BufferManager $buffers,
        private readonly Translator $translator,
        private readonly \Closure $openFile,
        private readonly \Closure $showBuffer,
        private readonly \Closure $relativePath,
        private readonly \Closure $copyToClipboard,
        private readonly \Closure $findInFolder,
    ) {}

    /**
     * The right-click context-menu items for a tree node.
     *
     * @param array{path: string, name: string, isDir: bool, depth: int} $node
     *
     * @return list<MenuItem>
     */
    public function contextItems(array $node): array
    {
        $items = [
            new MenuItem($this->translator->trans('New File…'), '', fn () => $this->treeNewFile($node)),
            new MenuItem($this->translator->trans('New Folder…'), '', fn () => $this->treeNewFolder($node)),
            MenuItem::separator(),
        ];
        // Folder-only: a text search scoped to the clicked directory subtree.
        if ($node['isDir']) {
            $items[] = new MenuItem($this->translator->trans('Find in Files…'), '', fn () => ($this->findInFolder)($node['path']));
            $items[] = MenuItem::separator();
        }
        $items[] = new MenuItem($this->translator->trans('Copy Path'), '', fn () => $this->copyPath($node['path'], false));
        $items[] = new MenuItem($this->translator->trans('Copy Relative Path'), '', fn () => $this->copyPath($node['path'], true));
        $items[] = MenuItem::separator();
        $items[] = new MenuItem($this->translator->trans('Rename…'), '', fn () => $this->treeRename($node));
        $items[] = new MenuItem($this->translator->trans('Delete…'), '', fn () => $this->treeDelete($node));
        $items[] = MenuItem::separator();
        if (!$node['isDir']) {
            $items[] = new MenuItem($this->translator->trans('Open'), '', fn () => ($this->openFile)($node['path'], true));
        }
        $items[] = new MenuItem($this->translator->trans('Refresh'), '', fn () => $this->tree->refresh());

        return $items;
    }

    /**
     * Directory a new tree entry is created in: the node itself, or its parent.
     *
     * @param array{path: string, name: string, isDir: bool, depth: int} $node
     */
    private function treeTargetDir(array $node): string
    {
        return $node['isDir'] ? $node['path'] : \dirname($node['path']);
    }

    /** @param array{path: string, name: string, isDir: bool, depth: int} $node */
    private function treeNewFile(array $node): void
    {
        $dir = $this->treeTargetDir($node);
        $this->promptName($this->translator->trans('New File'), '', function (string $name) use ($dir): void {
            if ('' === ($name = trim($name))) {
                return;
            }
            $path = $dir.'/'.$name;
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => ($this->relativePath)($path)]), function () use ($path): void {
                new Filesystem()->dumpFile($path, '');
                $this->tree->refresh();
                ($this->openFile)($path, true);
                $this->notifier->flash($this->translator->trans('Created %file%', ['%file%' => ($this->relativePath)($path)]));
            });
        });
    }

    /** @param array{path: string, name: string, isDir: bool, depth: int} $node */
    private function treeNewFolder(array $node): void
    {
        $dir = $this->treeTargetDir($node);
        $this->promptName($this->translator->trans('New Folder'), '', function (string $name) use ($dir): void {
            if ('' === ($name = trim($name))) {
                return;
            }
            $path = $dir.'/'.$name;
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => ($this->relativePath)($path)]), function () use ($path): void {
                new Filesystem()->mkdir($path);
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->flash($this->translator->trans('Created %file%', ['%file%' => ($this->relativePath)($path)]));
            });
        });
    }

    /** @param array{path: string, name: string, isDir: bool, depth: int} $node */
    private function treeRename(array $node): void
    {
        $old = $node['path'];
        $this->promptName($this->translator->trans('Rename'), $node['name'], function (string $name) use ($old): void {
            $name = trim($name);
            if ('' === $name || $name === basename($old)) {
                return;
            }
            $new = \dirname($old).'/'.$name;
            // Buffers are keyed by realpath(); resolve the old path the same way
            // so an open file's buffer is re-pointed at the new name.
            $oldKey = realpath($old) ?: $old;
            $this->withFs($this->translator->trans('Cannot rename %file%', ['%file%' => ($this->relativePath)($old)]), function () use ($old, $new, $name, $oldKey): void {
                new Filesystem()->rename($old, $new); // no overwrite: a name clash is an error
                if ($this->buffers->has($oldKey)) {
                    $this->buffers->close($oldKey);
                    ($this->openFile)($new, false);
                }
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->flash($this->translator->trans('Renamed to %name%', ['%name%' => $name]));
            });
        });
    }

    /** @param array{path: string, name: string, isDir: bool, depth: int} $node */
    private function treeDelete(array $node): void
    {
        $path = $node['path'];
        $this->promptName($this->translator->trans('Delete %name%? (y/N)', ['%name%' => $node['name']]), '', function (string $answer) use ($path): void {
            if (!\in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                $this->tui->setFocus($this->tree);

                return;
            }
            $key = realpath($path) ?: $path; // buffers are keyed by realpath()
            $this->withFs($this->translator->trans('Cannot delete %file%', ['%file%' => ($this->relativePath)($path)]), function () use ($path, $key): void {
                new Filesystem()->remove($path);
                if ($this->buffers->has($key)) {
                    $next = $this->buffers->close($key);
                    null !== $next ? ($this->showBuffer)($next) : $this->editor->showDocument('', new Document(''));
                }
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->flash($this->translator->trans('Deleted %file%', ['%file%' => ($this->relativePath)($path)]));
            });
        });
    }

    private function copyPath(string $path, bool $relative): void
    {
        $text = $relative ? ($this->relativePath)($path) : $path;
        ($this->copyToClipboard)($text);
        $this->notifier->flash($this->translator->trans('Copied %text%', ['%text%' => $text]));
    }

    /** Focus the editor and open its single-line prompt (tree file-op names/confirmations). */
    private function promptName(string $label, string $initial, callable $onSubmit): void
    {
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt($label, $initial, $onSubmit);
        $this->tui->requestRender();
    }

    /** Run a filesystem mutation, flashing $failMessage if it raises an I/O error. */
    private function withFs(string $failMessage, callable $op): void
    {
        try {
            $op();
        } catch (IOExceptionInterface) {
            $this->notifier->flash($failMessage);
        }
    }
}
