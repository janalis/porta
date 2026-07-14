<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\I18n\Translator;
use Porta\Lsp\LspLog;
use Porta\Widget\CodeEditorWidget;
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
        private readonly BufferService $bufferService,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Tui $tui,
        private readonly MenuBarWidget $menuBar,
        private readonly PanelManager $panels,
        private readonly CommandPalette $commandPalette,
        private readonly CommandRegistry $commands,
        private readonly NotificationLog $notificationLog,
        private readonly LspLog $lspLog,
        private readonly CodeEditorWidget $editor,
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

    public function openCommandPalette(): void
    {
        $this->panels->setMode(ResultsPanelMode::Action);
        $this->commandPalette->open($this->commands->paletteCommands());
    }

    /**
     * Show the notification log (newest first) in the editor's list overlay, so
     * an expired status-bar flash can still be read.
     */
    public function showNotificationLog(): void
    {
        $entries = $this->notificationLog->entries();
        if ([] === $entries) {
            $this->notifier->flash($this->translator->trans('No notifications yet'));

            return;
        }
        if (!$this->bufferService->ensureTextEditorActive()) {
            return;
        }
        $items = [];
        foreach (array_reverse($entries) as $entry) {
            $items[] = ['label' => $entry['text'], 'hint' => $entry['time']->format('H:i:s'), 'value' => $entry['text']];
        }
        $this->editor->openListOverlay($this->translator->trans('Notifications'), $items, static function (string $value): void {});
        $this->tui->requestRender();
    }

    /**
     * Show the captured language-server output (newest first) in the editor's
     * list overlay — the only way to see why a server misbehaves without
     * leaving the IDE.
     */
    public function showLspLog(): void
    {
        $entries = $this->lspLog->entries();
        if ([] === $entries) {
            $this->notifier->flash($this->translator->trans('No LSP server output yet'));

            return;
        }
        if (!$this->bufferService->ensureTextEditorActive()) {
            return;
        }
        $items = [];
        foreach (array_reverse($entries) as $entry) {
            $items[] = ['label' => $entry['text'], 'hint' => $entry['time']->format('H:i:s'), 'value' => $entry['text']];
        }
        $this->editor->openListOverlay($this->translator->trans('LSP Server Log'), $items, static function (string $value): void {});
        $this->tui->requestRender();
    }

    /** Move the active tab $offset positions in the strip (keyboard reordering). */
    public function moveTab(int $offset): void
    {
        if ($this->buffers->moveActive($offset)) {
            $this->tui->requestRender();
        }
    }
}
