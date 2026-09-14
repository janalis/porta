<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\LspLog;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Symfony\Component\Tui\Tui;

/**
 * The workbench-level actions behind the global chords: quitting (with the
 * unsaved-changes double-press guard), the app menu, the command palette,
 * the notification log, and keyboard tab reordering.
 */
final class WorkbenchActions
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly Translator $translator,
        private readonly Tui $tui,
        private readonly MenuBarWidget $menuBar,
        private readonly BottomDock $panels,
        private readonly NotificationLog $notificationLog,
        private readonly LspLog $lspLog,
        private readonly UnsavedChangesGuard $guard,
    ) {}

    /** Ctrl+Q: quit, unless dirty buffers need a confirming second press within 3s. */
    public function quit(): void
    {
        if (!$this->guard->confirmed('quit', $this->translator->trans('Ctrl+Q again to quit, Ctrl+S to save'))) {
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
