<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\BufferReloaded;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * Reconciles the open buffers and the file tree with on-disk state, driven by
 * the coordinator's 1s poll timer (via {@see BufferService::pollFilesystem()}).
 * Split out of BufferService because it is a self-contained lifecycle — it only
 * reacts to external file changes (reload clean buffers, flash dirty/deleted
 * ones, re-decode images) and shares none of the open/close/save flow's state.
 */
final class FilesystemReconciler
{
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

    /** Detect and act on an external change to a single buffer's file. */
    private function pollBuffer(Buffer $buffer): void
    {
        $stamp = $buffer->diskStampNow();
        if (null === $stamp) {
            if (null !== $buffer->diskStamp) {
                $buffer->diskStamp = null; // flash once; keep the buffer so it can be re-saved
                $this->notifier->flash($this->translator->trans('%file% deleted on disk', ['%file%' => $this->paths->relative($buffer->path)]));
            }

            return;
        }
        if ($stamp === $buffer->diskStamp) {
            return;
        }

        // The file changed on disk. Record the new stamp either way so we react once.
        $buffer->diskStamp = $stamp;
        if ($buffer->isViewer()) {
            $this->reloadPaneBuffer($buffer);

            return;
        }
        $content = $this->readTextFile($buffer->path);
        if (null === $content) {
            return; // unreadable / too large / binary now — leave the buffer untouched
        }
        if ($buffer->isDirty()) {
            $this->notifier->flash($this->translator->trans('%file% changed on disk', ['%file%' => $this->paths->relative($buffer->path)]));

            return;
        }
        $this->reloadBuffer($buffer, $content);
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
        $buffer->document->replaceAll($content);
        $buffer->document->markSaved();
        $this->lsp->notifyFullChange($buffer->path, $buffer->document); // no-op when no server is running
        $this->events->dispatch(new BufferReloaded($buffer));
        $this->notifier->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->paths->relative($buffer->path)]));
    }

    /** Read a small UTF-8 text file, or null if missing, unreadable, too large or binary. */
    private function readTextFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path) || (int) filesize($path) > (2 * 1024 * 1024)) {
            return null;
        }
        $content = (string) file_get_contents($path);

        return '' === $content || mb_check_encoding($content, encoding: 'UTF-8') ? $content : null;
    }
}
