<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\LspLog;
use Porta\Overlay\State\ToastCenter;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Symfony\Component\Tui\Tui;

/**
 * The workbench-level actions behind the global chords: quitting (with the
 * unsaved-changes double-press guard), the app menu, the command palette,
 * the notification log, and keyboard tab reordering.
 */
final readonly class WorkbenchActions
{
    public function __construct(
        private BufferManager $buffers,
        private Translator $translator,
        private Tui $tui,
        private MenuBarWidget $menuBar,
        private BottomDock $panels,
        private NotificationLog $notificationLog,
        private LspLog $lspLog,
        private UnsavedChangesGuard $guard,
        private ToastCenter $toasts,
    ) {}

    /** Ctrl+Q: quit, unless dirty buffers need a confirming second press within 3s. */
    public function quit(): void
    {
        if (!$this->guard->confirmed('quit', $this->translator->trans('Ctrl+Q again to quit, Ctrl+S to save'))) {
            return;
        }
        $this->tui->stop();
    }

    /**
     * F10: arm the menu bar — it takes the keys with File lit and drops
     * nothing yet — or, pressed again, let it go. Global keys run before the
     * focused widget, so the bar never sees F10 itself.
     */
    public function openMenu(): void
    {
        if ($this->menuBar->isOpen()) {
            $this->menuBar->close();
            $this->tui->requestRender();

            return;
        }
        $this->menuBar->arm();
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

    /**
     * Alt+D: dismiss the newest notification — the top card — from the
     * keyboard, as its × does under the pointer. The log keeps it.
     */
    public function dismissToast(): void
    {
        $this->toasts->dismissNewest();
    }

    /** Move the active tab $offset positions in the strip (keyboard reordering). */
    public function moveTab(int $offset): void
    {
        if ($this->buffers->moveActive($offset)) {
            $this->tui->requestRender();
        }
    }
}
