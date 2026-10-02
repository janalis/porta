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
use Porta\Filesystem\LocalFilesystem;
use Porta\Filesystem\ProjectFilesystem;
use Porta\I18n\Translator;
use Porta\Search\ProjectSearchController;
use Porta\Search\SearchActions;
use Porta\Support\Clipboard;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\DropConflict;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
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
        private readonly TreePathClipboard $treeClipboard,
        private readonly ProjectFilesystem $fs = new LocalFilesystem(),
    ) {}

    /**
     * The right-click context-menu items for a tree node.
     *
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
        $items[] = new MenuItem($this->translator->trans('Cut'), '', fn () => $this->treeCut($node));
        $items[] = new MenuItem($this->translator->trans('Copy'), '', fn () => $this->treeCopy($node));
        // Offered only when there is something to paste: an item that is always
        // there and usually does nothing teaches people to stop reading it.
        if (!$this->treeClipboard->isEmpty()) {
            $items[] = new MenuItem($this->translator->trans('Paste'), '', fn () => $this->treePaste($node));
        }
        $items[] = MenuItem::separator();
        \array_push($items, ...$this->copyPathItems($node->path));
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
     * Copy Path and Copy Relative Path for $path: a tree row's pair, which
     * the status bar's file part offers too.
     *
     * @return list<MenuItem>
     */
    public function copyPathItems(string $path): array
    {
        return [
            new MenuItem($this->translator->trans('Copy Path'), '', fn () => $this->copyAbsolutePath($path)),
            new MenuItem($this->translator->trans('Copy Relative Path'), '', fn () => $this->copyRelativePath($path)),
        ];
    }

    /**
     * The paths a Cut or Copy on this row carries — the same answer a drag
     * gives, so the two gestures cannot disagree about what "this row" means.
     *
     *
     * @return list<string>
     */
    private function clipboardSources(FileTreeNode $node): array
    {
        $selected = $this->tree->selectedPaths();

        return $node->isDir || !\in_array($node->path, $selected, strict: true) ? [$node->path] : $selected;
    }

    /**
     * The keyboard's way in: act on the row the tree has selected.
     *
     * The menu answers for the row that was right-clicked, which {@see
     * \Porta\Widget\FileTreeWidget::nodeAt()} has already selected by the time
     * an item runs — so both doors end up asking the same question of the same
     * row, and there is no second notion of "the current file" to drift.
     */
    public function cutSelection(): void
    {
        $node = $this->tree->selectedNode();
        if (null !== $node) {
            $this->treeCut($node);
        }
    }

    public function copySelection(): void
    {
        $node = $this->tree->selectedNode();
        if (null !== $node) {
            $this->treeCopy($node);
        }
    }

    /**
     * PhpStorm's Alt+Insert: a small picker of what to create, in the folder
     * the selected row means — the project root on a tree with nothing in it.
     */
    public function newInSelection(): void
    {
        $node = $this->tree->selectedNode() ?? new FileTreeNode($this->paths->root(), '', isDir: true, depth: 0);
        $items = [
            ['label' => $this->translator->trans('New File…'), 'hint' => '', 'value' => 'file'],
            ['label' => $this->translator->trans('New Folder…'), 'hint' => '', 'value' => 'folder'],
        ];
        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay($this->translator->trans('New'), $items, function (string $what) use ($node): void {
            'folder' === $what ? $this->treeNewFolder($node) : $this->treeNewFile($node);
        });
        $this->tui->requestRender();
    }

    /** Rename the row under the cursor, from the keyboard. */
    public function renameSelection(): void
    {
        $node = $this->tree->selectedNode();
        if (null !== $node) {
            $this->treeRename($node);
        }
    }

    /**
     * Delete what the tree has selected, from the keyboard — the same rule a
     * drag takes: the row under the cursor, or, when it is one of several
     * selected files, all of them. A folder always goes alone.
     */
    public function deleteSelection(): void
    {
        $node = $this->tree->selectedNode();
        if (null === $node) {
            return;
        }
        $selected = $this->tree->selectedPaths();
        if ($node->isDir || !\in_array($node->path, $selected, strict: true)) {
            $this->confirmDelete([$node->path]);

            return;
        }
        // The row under the cursor first, then the rest of the selection.
        $this->confirmDelete([$node->path, ...\array_values(\array_diff($selected, [$node->path]))]);
    }

    public function pasteIntoSelection(): void
    {
        $node = $this->tree->selectedNode();
        if (null !== $node) {
            $this->treePaste($node);
        }
    }

    private function treeCut(FileTreeNode $node): void
    {
        $this->treeClipboard->cut($this->clipboardSources($node));
    }

    private function treeCopy(FileTreeNode $node): void
    {
        $this->treeClipboard->copy($this->clipboardSources($node));
    }

    /**
     * Paste into the folder this row names, by asking for exactly the drop a
     * drag would have asked for.
     *
     * The point of routing it through {@see TreeDropRequested} rather than
     * calling {@see moveEach()} here is that everything hanging off the drop is
     * inherited rather than reimplemented: the collision prompt, the refusal to
     * move a folder into itself, saving dirty buffers first, re-pointing open
     * buffers, `FileRenamed` and its seven listeners, the language server's
     * didClose, and one `WorkingTreeChanged` for the whole batch.
     *
     * A cut is spent by its paste; a copy survives, so the same set can be
     * pasted into several places.
     *
     * An empty clipboard needs no guard here: both doors already refuse one —
     * the menu offers no Paste item and the keybinding is disabled — and a drop
     * of nothing is a no-op at the far end anyway.
     */
    private function treePaste(FileTreeNode $node): void
    {
        $sources = $this->treeClipboard->paths();
        $copy = $this->treeClipboard->isCopy();
        if (!$copy) {
            $this->treeClipboard->clear();
        }
        $this->events->dispatch(new TreeDropRequested($sources, $node->folder(), $copy));
    }

    private function treeNewFile(FileTreeNode $node): void
    {
        $dir = $node->folder();
        $this->promptName($this->translator->trans('New File'), '', function (string $name) use ($dir): void {
            if ('' === ($name = \trim($name))) {
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

    private function treeNewFolder(FileTreeNode $node): void
    {
        $dir = $node->folder();
        $this->promptName($this->translator->trans('New Folder'), '', function (string $name) use ($dir): void {
            if ('' === ($name = \trim($name))) {
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

    private function treeRename(FileTreeNode $node): void
    {
        $old = $node->path;
        $this->promptName($this->translator->trans('Rename'), $node->name, function (string $name) use ($old): void {
            $name = \trim($name);
            if ('' === $name || \basename($old) === $name) {
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
        $resolvedOld = \realpath($old);
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
            $newKey = ($resolvedNew = \realpath($new)) ? $resolvedNew : $new;
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

    private function treeDelete(FileTreeNode $node): void
    {
        $this->confirmDelete([$node->path]);
    }

    /**
     * One question for the whole set, then one working-tree announcement for
     * it — the batch a multi-file drop already runs as.
     *
     * @param non-empty-list<string> $paths
     */
    private function confirmDelete(array $paths): void
    {
        $question = 1 === \count($paths)
            ? $this->translator->trans('Delete %name%? (y/N)', ['%name%' => \basename($paths[0])])
            : $this->translator->trans('Delete %count% files? (y/N)', ['%count%' => \count($paths)]);
        $this->promptName($question, '', danger: true, onSubmit: function (string $answer) use ($paths): void {
            if (!\in_array(\strtolower(\trim($answer)), ['y', 'yes'], strict: true)) {
                $this->tui->setFocus($this->tree);

                return;
            }
            ++$this->batch;

            try {
                \array_map($this->deleteOne(...), $paths);
            } finally {
                --$this->batch;
                $this->announceWorkingTree();
            }
        });
    }

    private function deleteOne(string $path): void
    {
        // buffers are keyed by realpath()
        $resolved = \realpath($path);
        $key = $resolved ? $resolved : $path;
        $this->withFs($this->translator->trans('Cannot delete %file%', ['%file%' => $this->paths->relative($path)]), function () use ($path, $key): void {
            // Read before it goes: the text a recovery restores, the open
            // buffer's when there is one — unsaved edits are the user's too.
            $buffer = $this->buffers->get($key);
            $previous = null !== $buffer && !$buffer->isViewer() ? $buffer->document->getText() : Files::textOf($path);
            $this->fs->remove($path);
            // $key was resolved before the removal, which is the only time
            // it could be — the same reason the rename above resolves early.
            $this->events->dispatch(new FileDeleted($key, $previous));
            if ($this->buffers->has($key)) {
                $next = $this->buffers->close($key);
                null !== $next ? $this->bufferService->showBuffer($next) : $this->editor->showDocument('', new Document(''));
            }
            $this->tree->refresh();
            $this->tui->setFocus($this->tree);
            $this->notifier->flash($this->translator->trans('Deleted %file%', ['%file%' => $this->paths->relative($path)]));
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
        $this->dropOnto($event->sources, $event->targetDir, $event->copy, $event->conflict);
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
     * Keep-both, a drop from outside the IDE, skips the first and the third:
     * a file dropped into its own folder is how a duplicate is asked for, and
     * a taken name is answered with a free one ({@see FreeName}). The second
     * still holds — a folder mirrored into itself never finishes.
     *
     * @param list<string> $sources
     */
    private function dropOnto(array $sources, string $targetDir, bool $copy, DropConflict $conflict): void
    {
        $keepBoth = DropConflict::KeepBoth === $conflict;
        $moving = $keepBoth ? $sources : \array_values(\array_filter($sources, static fn (string $path): bool => \dirname($path) !== $targetDir));
        if ([] === $moving) {
            return;
        }
        foreach ($moving as $path) {
            if ($targetDir !== $path && !ProjectPaths::isUnder($targetDir, $path)) {
                continue;
            }

            $this->notifier->error($this->translator->trans('Cannot move %name% into itself', ['%name%' => \basename($path)]));

            return;
        }
        $taken = $keepBoth ? [] : \array_values(\array_filter($moving, static fn (string $path): bool => \file_exists($targetDir.'/'.\basename($path))));
        if ([] === $taken) {
            $this->moveEach($moving, $targetDir, $copy, conflict: $conflict);

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
            ? $this->translator->trans('%name% already exists in %dir%', ['%name%' => \basename($taken[0]), '%dir%' => $this->paths->relative($targetDir)])
            : $this->translator->trans('%count% files already exist in %dir%', ['%count%' => \count($taken), '%dir%' => $this->paths->relative($targetDir)]);

        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay($title, $items, function (string $choice) use ($moving, $targetDir, $copy): void {
            if ('overwrite' === $choice) {
                $this->moveEach($moving, $targetDir, $copy, overwrite: true);

                return;
            }
            if ('rename' === $choice) {
                $source = $moving[0];
                $this->promptName($this->translator->trans('Move as'), \basename($source), function (string $name) use ($source, $targetDir, $copy): void {
                    $name = \trim($name);
                    if ('' !== $name) {
                        $this->moveOne($source, $targetDir.'/'.$name, $copy);
                    }
                });
            }
        });
        $this->tui->requestRender();
    }

    /**
     * The free name is taken per item, inside the loop: two sources sharing a
     * basename must not both be handed the same `(2)`, and only the disk after
     * the first copy knows the second needs a `(3)`.
     *
     * @param list<string> $moving
     */
    private function moveEach(array $moving, string $targetDir, bool $copy, bool $overwrite = false, DropConflict $conflict = DropConflict::Ask): void
    {
        ++$this->batch;

        try {
            foreach ($moving as $path) {
                $target = DropConflict::KeepBoth === $conflict ? FreeName::for($path, $targetDir) : $targetDir.'/'.\basename($path);
                $this->moveOne($path, $target, $copy, $overwrite);
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
        $name = \basename($target);
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
        $this->withFs($this->translator->trans('Cannot copy %file%', ['%file%' => $this->paths->relative($source)]), function () use ($source, $target, $name, $overwrite): void {
            // The same permission the move branch honours. Without this the
            // copy ignored it and always overwrote: the drop asks before
            // clobbering a taken name, and answering `Rename…` with a name that
            // is ALSO taken slipped past the answer and destroyed the second
            // file silently. A move refuses there because `rename()` throws;
            // this makes the copy refuse the same way, through the same toast.
            //
            // The guard is the whole permission, which is why `copy()` below
            // overwrites newer files (see {@see ProjectFilesystem::copy()}):
            // past this line either the target is free, or the answer was
            // yes — and then a target NEWER than its source must still go.
            if (!$overwrite && \file_exists($target)) {
                throw new IOException(\sprintf('%s already exists', $target));
            }
            // A copy reads from DISK, so an unsaved edit would be missing from
            // the copy while staying in the original — the same surprise as
            // losing it, wearing a different face.
            $this->saveDirtyUnder(($resolved = \realpath($source)) ? $resolved : $source);
            $this->fs->copy($source, $target);
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
