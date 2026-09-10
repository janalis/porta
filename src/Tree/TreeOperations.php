<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\TreeDropRequested;
use Porta\Event\WorkingTreeChanged;
use Porta\I18n\Translator;
use Porta\Search\ProjectSearchController;
use Porta\Search\SearchActions;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
    /**
     * Depth of a batch of filesystem verbs announcing as one.
     *
     * @see announceWorkingTree()
     */
    private int $batch = 0;

    public function __construct(
        private readonly FileTreeWidget $tree,
        // Deliberately concrete: showDocument (the rename/delete re-point)
        // lives in no role interface, and a one-consumer role is not worth one.
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly BufferManager $buffers,
        private readonly Translator $translator,
        private readonly BufferService $bufferService,
        private readonly Clipboard $clipboard,
        private readonly SearchActions $searchActions,
        private readonly ProjectSearchController $projectSearch,
        private readonly ProjectPaths $paths,
        private readonly EventDispatcherInterface $events,
        private readonly Filesystem $fs = new Filesystem(),
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
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => $this->paths->relative($path)]), function () use ($path): void {
                $this->fs->dumpFile($path, '');
                $this->tree->refresh();
                $this->bufferService->openFile($path, focus: OpenFocus::Editor);
                $this->notifier->success($this->translator->trans('Created %file%', ['%file%' => $this->paths->relative($path)]));
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
            $this->withFs($this->translator->trans('Cannot create %file%', ['%file%' => $this->paths->relative($path)]), function () use ($path): void {
                $this->fs->mkdir($path);
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->success($this->translator->trans('Created %file%', ['%file%' => $this->paths->relative($path)]));
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
            $this->moveInto(
                $old,
                \dirname($old).'/'.$name,
                $this->translator->trans('Cannot rename %file%', ['%file%' => $this->paths->relative($old)]),
                $this->translator->trans('Renamed to %name%', ['%name%' => $name]),
            );
        });
    }

    /**
     * Move $old to $new and take everything that points at it along.
     *
     * The body {@see treeRename()} used to hold inline, extracted whole
     * because none of it is rename-specific — a drop from a drag needs exactly
     * the same sequence, and a second copy of it would be a second place to
     * get the ordering wrong.
     *
     * The ordering IS the substance: the old path is resolved, and the buffers
     * under it collected, BEFORE the move, because neither question can be
     * asked of a directory that has stopped existing.
     */
    private function moveInto(string $old, string $new, string $failure, string $success, bool $overwrite = false): void
    {
        // Buffers are keyed by realpath(); resolve the old path the same way
        // so an open file's buffer is re-pointed at the new name.
        $resolvedOld = realpath($old);
        $oldKey = $resolvedOld ? $resolvedOld : $old;
        $this->withFs($failure, function () use ($old, $new, $oldKey, $success, $overwrite): void {
            // Write BEFORE the rename, or the edits are gone: the move closes
            // each affected buffer and re-opens it from disk, and
            // BufferManager::close() has no dirty check — the guarded prompt
            // lives only in closeActiveBuffer(). Saving rather than asking is
            // the only answer that loses nothing; "discard" would throw the
            // work away and move anyway.
            $this->saveDirtyUnder($oldKey);
            // Read BEFORE the rename: a buffer's path is checked against a
            // directory that is about to stop existing.
            $inside = $this->buffersMoved($oldKey, $new);
            $this->fs->rename($old, $new, $overwrite); // a name clash is an error unless the user answered one
            if ($this->buffers->has($oldKey)) {
                $this->buffers->close($oldKey);
                $this->bufferService->openFile($new);
            }
            // Renaming a FOLDER moves every file in it, and each open one
            // is a tab still pointing into a directory that is gone: the
            // reconciler flashes "deleted on disk" for a file that merely
            // moved, and saving it writes the old folder back into
            // existence, because dumpFile() creates the directories it
            // needs.
            // Spelled out because this class outgrew mago's per-class
            // inference budget: past it every local is inferred `mixed`, and
            // the complaint lands on the CALLS rather than on the size that
            // caused it. buffersMoved() is typed; these two say so again.
            /**
             * @var string $path
             * @var string $target
             */
            foreach ($inside as $path => $target) {
                $this->buffers->close($path);
                $this->bufferService->openFile($target);
            }
            // Canonical both sides, per FileRenamed's contract: $oldKey was
            // resolved BEFORE the rename (it cannot be, after), and the new
            // path resolves now that it exists.
            $newKey = ($resolvedNew = realpath($new)) ? $resolvedNew : $new;
            $this->events->dispatch(new FileRenamed($oldKey, $newKey));
            $this->tree->refresh();
            $this->tui->setFocus($this->tree);
            $this->notifier->success($success);
        });
    }

    /**
     * Every open buffer INSIDE $from, mapped to where the rename puts it.
     *
     * Empty for a file rename without a special case: the only path under a
     * file is the file itself, and that one is handled above as a re-point
     * rather than a move.
     *
     * @return array<string, string> current path => path after the rename
     */
    /**
     * Flush every dirty buffer at $root or under it.
     *
     * $root is canonical and so are buffer paths, so this is the same
     * comparison {@see buffersMoved()} makes — one directory's worth of
     * buffers, found the one way that works for a folder.
     */
    private function saveDirtyUnder(string $root): void
    {
        foreach ($this->buffers->all() as $buffer) {
            if (!$buffer->isDirty() || $buffer->path !== $root && !ProjectPaths::isUnder($buffer->path, $root)) {
                continue;
            }

            $this->bufferService->saveBuffer($buffer);
        }
    }

    private function buffersMoved(string $from, string $to): array
    {
        $moved = [];
        foreach ($this->buffers->all() as $buffer) {
            $target = ProjectPaths::moved($buffer->path, $from, $to);
            if (null === $target || $buffer->path === $from) {
                continue; // the renamed node ITSELF is re-pointed above, not moved
            }
            $moved[$buffer->path] = $target;
        }

        return $moved;
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
            $this->withFs($this->translator->trans('Cannot delete %file%', ['%file%' => $this->paths->relative($path)]), function () use ($path, $key): void {
                $this->fs->remove($path);
                // $key was resolved before the removal, which is the only time
                // it could be — the same reason the rename above resolves early.
                $this->events->dispatch(new FileDeleted($key));
                if ($this->buffers->has($key)) {
                    $next = $this->buffers->close($key);
                    null !== $next ? $this->bufferService->showBuffer($next) : $this->editor->showDocument('', new Document(''));
                }
                $this->tree->refresh();
                $this->tui->setFocus($this->tree);
                $this->notifier->flash($this->translator->trans('Deleted %file%', ['%file%' => $this->paths->relative($path)]));
            });
        });
    }

    private function copyAbsolutePath(string $path): void
    {
        $this->copyToClipboard($path);
    }

    private function copyRelativePath(string $path): void
    {
        $this->copyToClipboard($this->paths->relative($path));
    }

    private function copyToClipboard(string $text): void
    {
        $this->clipboard->copy($text);
        $this->notifier->flash($this->translator->trans('Copied %text%', ['%text%' => $text]));
    }

    /** Focus the editor and open its single-line prompt (tree file-op names/confirmations). */
    /** The tree reports a drop; this is the one listener that performs it. */
    #[AsEventListener]
    public function onTreeDropRequested(TreeDropRequested $event): void
    {
        $this->dropOnto($event->sources, $event->targetDir, $event->copy);
    }

    /**
     * Drop $sources into $targetDir — the commit half of a tree drag.
     *
     * Order matters and each guard answers a different question:
     *
     * 1. Anything already in the target is dropped from the set. Dragging a
     *    file a few rows within its own folder is a slip, not an instruction,
     *    and a "moved" flash for a file that did not move is a lie.
     * 2. A directory cannot go inside itself or its own descendant. Nothing
     *    else in the codebase guards this, because nothing else could reach
     *    it: the rename is confined to `dirname($old)` and a language server
     *    does not aim at its own subtree.
     * 3. A name already taken is the user's decision, not ours — see
     *    {@see askThenMove()}.
     *
     * @param list<string> $sources
     */
    private function dropOnto(array $sources, string $targetDir, bool $copy = false): void
    {
        $moving = array_values(array_filter($sources, static fn (string $path): bool => \dirname($path) !== $targetDir));
        if ([] === $moving) {
            return;
        }
        foreach ($moving as $path) {
            if ($targetDir !== $path && !ProjectPaths::isUnder($targetDir, $path)) {
                continue;
            }

            $this->notifier->error($this->translator->trans('Cannot move %name% into itself', ['%name%' => basename($path)]));

            return;
        }
        $taken = array_values(array_filter($moving, fn (string $path): bool => $this->fs->exists($targetDir.'/'.basename($path))));
        if ([] === $taken) {
            $this->moveEach($moving, $targetDir, $copy);

            return;
        }

        $this->askThenMove($moving, $taken, $targetDir, $copy);
    }

    /**
     * The name is taken: ask rather than refuse, which is what the tree's own
     * rename does today and what makes a drop recoverable without an undo
     * (moves are deliberately outside `RefactoringUndo`).
     *
     * One prompt for a set, not one per file: `Rename…` has no single answer
     * for several files, and a modal apiece is worse than the collision.
     *
     * @param list<string> $moving
     * @param list<string> $taken
     */
    private function askThenMove(array $moving, array $taken, string $targetDir, bool $copy): void
    {
        $single = 1 === \count($moving) && 1 === \count($taken);
        $items = [
            ['label' => $this->translator->trans($single ? 'Overwrite' : 'Overwrite all'), 'hint' => '', 'value' => 'overwrite'],
            ...($single ? [['label' => $this->translator->trans('Rename…'), 'hint' => '', 'value' => 'rename']] : []),
            ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
        ];
        $title = $single
            ? $this->translator->trans('%name% already exists in %dir%', ['%name%' => basename($taken[0]), '%dir%' => $this->paths->relative($targetDir)])
            : $this->translator->trans('%count% files already exist in %dir%', ['%count%' => \count($taken), '%dir%' => $this->paths->relative($targetDir)]);

        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay($title, $items, function (string $choice) use ($moving, $targetDir, $copy): void {
            if ('overwrite' === $choice) {
                $this->moveEach($moving, $targetDir, $copy, overwrite: true);

                return;
            }
            if ('rename' === $choice) {
                $source = $moving[0];
                $this->promptName($this->translator->trans('Move as'), basename($source), function (string $name) use ($source, $targetDir, $copy): void {
                    $name = trim($name);
                    if ('' !== $name) {
                        $this->moveOne($source, $targetDir.'/'.$name, $copy);
                    }
                });
            }
        });
        $this->tui->requestRender();
    }

    /**
     * @param list<string> $moving
     */
    private function moveEach(array $moving, string $targetDir, bool $copy, bool $overwrite = false): void
    {
        ++$this->batch;

        try {
            foreach ($moving as $path) {
                $this->moveOne($path, $targetDir.'/'.basename($path), $copy, $overwrite);
            }
        } finally {
            --$this->batch;
            // In finally for the same reason withFs() is: a set that gave up
            // halfway still moved the files it got to.
            $this->announceWorkingTree();
        }
    }

    /**
     * A copy is not a move and does not pretend to be one: nothing moved, so
     * no `FileRenamed` is dispatched and no buffer is re-pointed. Only the
     * tree has anything to say about it.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- copy-versus-move is the one fact a drop carries; it decides which verb runs, not a second responsibility.
    private function moveOne(string $source, string $target, bool $copy, bool $overwrite = false): void
    {
        $name = basename($target);
        if (!$copy) {
            $this->moveInto(
                $source,
                $target,
                $this->translator->trans('Cannot move %file%', ['%file%' => $this->paths->relative($source)]),
                $this->translator->trans('Moved %name%', ['%name%' => $name]),
                $overwrite,
            );

            return;
        }
        $this->withFs($this->translator->trans('Cannot copy %file%', ['%file%' => $this->paths->relative($source)]), function () use ($source, $target, $name): void {
            // A copy reads from DISK, so an unsaved edit would be missing from
            // the copy while staying in the original — the same surprise as
            // losing it, wearing a different face.
            $this->saveDirtyUnder(($resolved = realpath($source)) ? $resolved : $source);
            is_dir($source) ? $this->fs->mirror($source, $target) : $this->fs->copy($source, $target, overwriteNewerFiles: true);
            $this->tree->refresh();
            $this->tui->setFocus($this->tree);
            $this->notifier->success($this->translator->trans('Copied %name%', ['%name%' => $name]));
        });
    }

    private function promptName(string $label, string $initial, callable $onSubmit, bool $danger = false): void
    {
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt($label, $initial, $onSubmit, $danger);
        $this->tui->requestRender();
    }

    /**
     * Run a filesystem mutation, flashing $failMessage if it raises an I/O
     * error — and tell version control that the working tree moved.
     *
     * All five of this class's filesystem verbs come through here: new file,
     * new folder, move, copy, delete. Every one of them changes what `git
     * status` would say, and until this line core was the one producer of
     * working-tree changes that never announced them — so a file you moved sat
     * unmarked in the tree until something unrelated, usually a save, happened
     * to trigger a refresh. {@see WorkingTreeChanged} is payload-free and
     * already carries a stash, a stage and a pull; a file appearing or moving
     * is the same news from a different verb.
     *
     * In FINALLY, not after a successful $op: a mutation that raises partway —
     * three of five files moved, then a permission error — has still changed
     * the tree, and asking git is how anyone finds out what actually landed.
     * The no-op cases never reach here; they return at their own guard.
     */
    private function withFs(string $failMessage, callable $op): void
    {
        try {
            $op();
        } catch (IOExceptionInterface) {
            $this->notifier->error($failMessage);
        } finally {
            $this->announceWorkingTree();
        }
    }

    /**
     * Say the working tree moved — unless a batch of verbs is in flight, which
     * announces once for the lot.
     *
     * A drop of twenty files is twenty calls to {@see withFs()} and one
     * gesture. The listener runs `git status` over the whole repository and
     * asks for the branch; only the first of twenty has anything new to tell
     * it, and the branch query does not coalesce the way the status does.
     *
     * A counter, not a flag: nothing nests batches today, and a flag would let
     * the first inner one to finish clear it for the rest.
     */
    private function announceWorkingTree(): void
    {
        if (0 === $this->batch) {
            $this->events->dispatch(new WorkingTreeChanged());
        }
    }
}
