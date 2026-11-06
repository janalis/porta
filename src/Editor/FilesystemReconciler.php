<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\BufferReloaded;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * Reconciles the open buffers and the file tree with on-disk state, driven by
 * the coordinator's poll timer in bounded slices (via
 * {@see BufferService::pollFilesystemSlice()}; tests and explicit refreshes
 * take the synchronous full pass).
 * Split out of BufferService because it is a self-contained lifecycle — it only
 * reacts to external file changes (reload clean buffers, flash dirty/deleted
 * ones, re-decode images) and shares none of the open/close/save flow's state.
 */
final class FilesystemReconciler
{
    /** Buffers re-checked per {@see pollSlice} call. */
    private const int BUFFER_SLICE = 4;

    /** Tree directories re-checked per {@see pollSlice} call. */
    private const int TREE_SLICE = 8;

    /**
     * Buffer paths still to check this cycle — refilled from the open set when empty.
     *
     * @var list<string>
     */
    private array $pendingPaths = [];

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly EditorPanes $panes,
        private readonly FileTreeWidget $tree,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly LspSessionPool $lsp,
        private readonly EventDispatcherInterface $events,
        private readonly ProjectPaths $paths,
        private readonly PollBackoff $backoff,
    ) {}

    /** Reconcile every open buffer and the file tree with the on-disk state. */
    public function pollFilesystem(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->pollBuffer($buffer);
        }
        if ($this->tree->refreshIfChanged()) {
            $this->tui->requestRender();
        }
    }

    /**
     * The poll timer's amortized sibling of {@see pollFilesystem()}: a bounded
     * slice of buffers and tree directories per tick, so reconciliation cost
     * never lands on the event loop as one long synchronous stall (the proven
     * `.porta/freeze.log` cause). Full coverage still completes within a few
     * ticks at typical sizes; a huge tree degrades to slower DETECTION, not a
     * longer stall. Paths are re-resolved through the manager so a buffer
     * closed mid-cycle is skipped — never stat'd or flashed for.
     *
     * The bound is a COUNT, and a count is only half of it: a single `is_file()`
     * can block for twelve seconds on a loaded box, which is what 35 of the 41
     * blocked-loop backtraces in the user's freeze.log are stopped inside. So
     * {@see PollBackoff} times each slice and stands the poll down when the
     * filesystem stops answering — the freeze cannot be made shorter, but it can
     * stop happening four times a second.
     */
    public function pollSlice(): void
    {
        if (!$this->backoff->mayRun()) {
            return;
        }
        if ([] === $this->pendingPaths) {
            $this->pendingPaths = \array_map(static fn (Buffer $buffer): string => $buffer->path, $this->buffers->all());
        }
        foreach (\array_splice($this->pendingPaths, offset: 0, length: self::BUFFER_SLICE) as $path) {
            $buffer = $this->buffers->get($path);
            if (null !== $buffer) {
                $this->pollBuffer($buffer);
            }
        }
        if ($this->tree->refreshDirsSlice(self::TREE_SLICE)) {
            $this->tui->requestRender();
        }
        $this->backoff->finished();
    }

    /** Detect and act on an external change to a single buffer's file. */
    private function pollBuffer(Buffer $buffer): void
    {
        $stamp = $buffer->diskStampNow();
        if (null === $stamp || $stamp === $buffer->diskStamp) {
            if (null === $stamp && null !== $buffer->diskStamp) {
                $buffer->diskStamp = null; // flash once; keep the buffer so it can be re-saved
                $this->notifier->flash($this->translator->trans('%file% deleted on disk', ['%file%' => $this->paths->relative($buffer->path)]));
            }

            return;
        }

        // The file changed on disk. Record the new stamp either way so we react once.
        $buffer->diskStamp = $stamp;
        if ($buffer->isViewer()) {
            $this->reloadPaneBuffer($buffer);

            return;
        }
        $this->reconcileTextBuffer($buffer);
    }

    /** A text tab's file changed on disk: reload it, or settle what its edits make of it. */
    private function reconcileTextBuffer(Buffer $buffer): void
    {
        $content = $this->readTextFile($buffer->path);
        if (null === $content) {
            return; // unreadable / too large / binary now — leave the buffer untouched
        }
        if (!$buffer->isDirty()) {
            $this->reloadBuffer($buffer, $content);

            return;
        }
        $saved = $content === $buffer->document->getText();
        if ($saved) {
            // Written with the very text the buffer holds — by whoever the
            // edits came from (an agent's accepted proposal): saved, not a clash.
            $buffer->document->markSaved();
            $buffer->diskChangedUnderEdit = false;
            $this->tui->requestRender();
        }
        if (!$saved) {
            // Remember it, not just flash it. The flash is gone in seconds and
            // the stamp above now matches disk, so without this the save that
            // follows cannot tell anyone else ever wrote here.
            $buffer->diskChangedUnderEdit = true;
            $this->notifier->flash($this->translator->trans('%file% changed on disk', ['%file%' => $this->paths->relative($buffer->path)]));
        }
    }

    /**
     * A pane tab's file changed: ask that pane's loader for the new payload and
     * show it. A loader that refuses (unreadable, undecodable, no longer UTF-8)
     * leaves the last good payload on screen — blanking a pane that is showing
     * something valid is never the better answer.
     */
    private function reloadPaneBuffer(Buffer $buffer): void
    {
        $pane = $this->panes->paneFor((string) $buffer->paneId);
        $load = $this->panes->loaderFor((string) $buffer->paneId);
        if (null === $pane || null === $load) {
            return; // the pane's plugin was disabled: the tab keeps what it has
        }

        try {
            /** @var mixed $payload */
            $payload = $load($buffer->path);
        } catch (\Throwable) {
            return;
        }
        $buffer->panePayload = $payload;
        if ($buffer === $this->buffers->active()) {
            $pane->show($buffer->path, $payload);
        }
        $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->paths->relative($buffer->path)]));
    }

    /** Replace a clean buffer's content with the new on-disk text, keeping it clean. */
    private function reloadBuffer(Buffer $buffer, string $content): void
    {
        $buffer->document->reload($content);
        $buffer->document->markSaved();
        $buffer->diskChangedUnderEdit = false; // the buffer now IS the disk
        $this->lsp->notifyFullChange($buffer->path, $buffer->document); // no-op when no server is running
        $this->events->dispatch(new BufferReloaded($buffer));
        $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->paths->relative($buffer->path)]));
    }

    /** Read a small UTF-8 text file, or null if missing, unreadable, too large or binary. */
    private function readTextFile(string $path): ?string
    {
        if (!\is_file($path) || !\is_readable($path) || (int) \filesize($path) > Files::MAX_TEXT_BYTES) {
            return null;
        }
        $content = (string) \file_get_contents($path);

        return '' === $content || \mb_check_encoding($content, encoding: 'UTF-8') ? $content : null;
    }
}
