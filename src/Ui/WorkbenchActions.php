<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\LspLog;
use Porta\Ui\Dock\BottomDock;
use Porta\Ui\Dock\ResultsPanelMode;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The workbench-level actions behind the global chords: quitting (with the
 * unsaved-changes double-press guard), the app menu, the command palette,
 * the notification log, and keyboard tab reordering.
 */
final class WorkbenchActions
{
    private float $lastQuitPress = 0.0;

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Tui $tui,
        private readonly MenuBarWidget $menuBar,
        private readonly BottomDock $panels,
        private readonly NotificationLog $notificationLog,
        private readonly LspLog $lspLog,
    ) {}

    /** Ctrl+Q: quit, unless dirty buffers need a confirming second press within 3s. */
    public function quit(): void
    {
        $dirty = $this->buffers->dirtyCount();
        if ($dirty > 0 && (microtime(true) - $this->lastQuitPress) > 3.0) {
            $this->lastQuitPress = microtime(true);
            $noun = 1 === $dirty
                ? $this->translator->trans('1 file has')
                : $this->translator->trans('%count% files have', ['%count%' => $dirty]);
            $this->notifier->flash($this->translator->trans('Unsaved changes — %noun% edits. Ctrl+Q again to quit, Ctrl+S to save', ['%noun%' => $noun]), 3.0);

            return;
        }
        $this->tui->stop();
    }

    public function openMenu(): void
    {
        $this->menuBar->open(0);
        $this->tui->setFocus($this->menuBar);
        $this->tui->requestRender();
    }

    /**
     * Show the notification log (newest first) as a dock tool, so an expired
     * status-bar flash can still be read; new flashes append live via
     * {@see DockLiveRefreshListener}.
     */
    public function showNotificationLog(): void
    {
        $this->panels->showList(ResultsPanelMode::Notifications, $this->translator->trans('Notifications'), LogRows::build($this->notificationLog->entries()));
    }

    /**
     * Show the captured language-server output (newest first) as a dock tool
     * — the only way to see why a server misbehaves without leaving the IDE.
     * Opens fine empty and then live-tails via {@see DockLiveRefreshListener}.
     */
    public function showLspLog(): void
    {
        $this->panels->showList(ResultsPanelMode::LspLog, $this->translator->trans('LSP Server Log'), LogRows::build($this->lspLog->entries()));
    }

    /** Move the active tab $offset positions in the strip (keyboard reordering). */
    public function moveTab(int $offset): void
    {
        if ($this->buffers->moveActive($offset)) {
            $this->tui->requestRender();
        }
    }
}
