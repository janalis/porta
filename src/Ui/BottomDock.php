<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\I18n\Translator;
use Porta\Terminal\TerminalSessions;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock: one panel area above the status bar shared by the
 * terminal, results and debug panels, with at most one panel visible at a
 * time. Opening any panel (its own `open(...)` — no call site changes)
 * conceals the others; closing one reveals the most recently visible tab
 * that is still alive. The dock also owns the {@see ResultsPanelMode} the
 * results panel is currently serving and the focus/repaint/flash
 * choreography every panel-opening path repeated.
 */
final class BottomDock
{
    private ResultsPanelMode $mode = ResultsPanelMode::Search;

    /** @var list<TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget> visibility order, most recently visible last */
    private array $mru = [];

    /** @var (callable(): void)|null reveals the Terminal tab by re-opening the active session (RunController) */
    private $onTerminalReveal = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly DockTabsWidget $dockTabs,
        private readonly TerminalSessions $sessions,
        private readonly Translator $translator,
        private readonly Notifier $notifier,
        private readonly EditorArea $editorArea,
    ) {}

    /** Register the dock lifecycle hooks on the three panels and the tab strip; called once from WidgetHooks. */
    public function wire(): void
    {
        foreach ($this->panels() as $panel) {
            $panel->onOpen(fn () => $this->activate($panel));
            $panel->onClose(fn () => $this->restoreAfter($panel));
        }
        // Esc is a soft hide — the tool stays alive one tab away. The
        // transient pickers (palette, go-to-symbol) close for real instead.
        $this->terminalPanel->onDismiss($this->hide(...));
        $this->debugPanel->onDismiss($this->hide(...));
        $this->resultsPanel->onDismiss(function (): void {
            if ($this->isPickerMode()) {
                $this->resultsPanel->close();

                return;
            }
            $this->hide();
        });
        $this->dockTabs->setProvider($this->aliveTabs(...));
        $this->dockTabs->onTabSelected($this->reveal(...));
        $this->dockTabs->onTabClosed($this->closeTab(...));
    }

    /** How the Terminal tab reveals itself: re-open the active session (wired to RunController). */
    public function onTerminalReveal(callable $callback): void
    {
        $this->onTerminalReveal = $callback;
    }

    /**
     * The strip rows: one entry per alive tool. Empty (the strip disappears)
     * while the dock is hidden or a transient picker owns the results panel.
     *
     * @return list<array{tab: DockTab, label: string, active: bool}>
     */
    public function aliveTabs(): array
    {
        $visible = $this->visiblePanel();
        if (null === $visible || $visible === $this->resultsPanel && $this->isPickerMode()) {
            return [];
        }

        $tabs = [];
        if (!$this->sessions->isEmpty()) {
            $tabs[] = ['tab' => DockTab::Terminal, 'label' => $this->translator->trans('Terminal'), 'active' => $visible === $this->terminalPanel];
        }
        if ($this->resultsPanel->isOpen()) {
            $tabs[] = ['tab' => DockTab::Results, 'label' => $this->resultsPanel->getTitle(), 'active' => $visible === $this->resultsPanel];
        }
        if ($this->debugPanel->isOpen()) {
            $tabs[] = ['tab' => DockTab::Debug, 'label' => $this->translator->trans('Debug'), 'active' => $visible === $this->debugPanel];
        }

        return $tabs;
    }

    /** Whether the strip is on screen — it shifts the dock's resize grab up a row. */
    public function stripVisible(): bool
    {
        return [] !== $this->aliveTabs();
    }

    /** Bring a tab's panel into the dock slot and focus it. */
    public function reveal(DockTab $tab): void
    {
        if (DockTab::Terminal === $tab) {
            // The terminal re-opens through its controller so the active
            // session's buffer binds into the panel.
            if (null !== $this->onTerminalReveal) {
                ($this->onTerminalReveal)();
            }

            return;
        }
        $panel = DockTab::Results === $tab ? $this->resultsPanel : $this->debugPanel;
        if (!$panel->isOpen() || $panel->isVisible()) {
            return;
        }
        $this->activate($panel);
        $panel->reveal();
        $this->tui->setFocus($panel);
        $this->tui->requestRender();
    }

    /** Hide the whole dock (Esc / toggling the visible tool off); every tab stays alive. */
    public function hide(): void
    {
        $visible = $this->visiblePanel();
        foreach ($this->panels() as $panel) {
            $panel->conceal();
        }
        if (null !== $visible) {
            $this->returnFocusFrom($visible);
        }
        $this->tui->requestRender();
    }

    /** Reveal the next (+1) / previous (-1) alive tab, in strip order. */
    public function cycle(int $delta): void
    {
        $order = array_values(array_filter(
            $this->panels(),
            fn (TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel): bool => $panel === $this->terminalPanel ? !$this->sessions->isEmpty() : $panel->isOpen(),
        ));
        $current = array_search($this->visiblePanel(), $order, strict: true);
        if (false === $current || \count($order) < 2) {
            return;
        }
        $count = \count($order);
        $this->reveal($this->tabOf($order[($current + $delta + $count) % $count]));
    }

    /** The strip's ×: close the tool for real — except the Terminal, whose sessions keep running. */
    public function closeTab(DockTab $tab): void
    {
        if (DockTab::Terminal === $tab) {
            $this->terminalPanel->conceal();
            $this->restoreAfter($this->terminalPanel);

            return;
        }
        $panel = DockTab::Results === $tab ? $this->resultsPanel : $this->debugPanel;
        $panel->close();
    }

    /** The panel currently owning the dock slot, or null while the dock is hidden. */
    public function visiblePanel(): TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget|null
    {
        foreach ($this->panels() as $panel) {
            if ($panel->isVisible()) {
                return $panel;
            }
        }

        return null;
    }

    public function terminalPanel(): TerminalPanelWidget
    {
        return $this->terminalPanel;
    }

    /** Drive the shared bottom-panel height (all three panels track one size). */
    public function setPanelRows(int $rows): void
    {
        foreach ($this->panels() as $panel) {
            $panel->setVisibleRows($rows);
        }
    }

    /** Which content the results panel is currently serving (drives query/jump routing). */
    public function mode(): ResultsPanelMode
    {
        return $this->mode;
    }

    /**
     * Record the active mode without touching the panel — for paths that open it
     * through another collaborator (project search, command palette).
     */
    public function setMode(ResultsPanelMode $mode): void
    {
        $this->mode = $mode;
    }

    /** Open the results panel in a live-query mode (e.g. symbol search) and focus it. */
    public function openQuery(ResultsPanelMode $mode, string $title): void
    {
        $this->mode = $mode;
        $this->resultsPanel->open($title, ResultsPanelFields::queryOnly());
        $this->tui->setFocus($this->resultsPanel);
        $this->tui->requestRender();
    }

    /**
     * Open the results panel showing a static list of rows and focus it.
     *
     * @param list<array{path: string, line: int, text: string}> $rows
     */
    public function showList(ResultsPanelMode $mode, string $title, array $rows): void
    {
        $this->mode = $mode;
        $this->resultsPanel->open($title, ResultsPanelFields::resultsOnly());
        $this->resultsPanel->setResults($rows);
        $this->tui->setFocus($this->resultsPanel);
        $this->tui->requestRender();
    }

    /** Return focus to the editor area when the results panel currently holds it, then repaint. */
    public function focusEditor(): void
    {
        $this->returnFocusFrom($this->resultsPanel);
    }

    /** Return focus to the editor area when $widget currently holds it, then repaint. */
    public function returnFocusFrom(AbstractWidget $widget): void
    {
        if ($this->tui->getFocus() === $widget) {
            $this->tui->setFocus($this->editorArea->widget());
        }
        $this->tui->requestRender();
    }

    /** Flash a transient status message and repaint. */
    public function flash(string $message): void
    {
        $this->notifier->flash($message);
    }

    /** @return list<TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget> */
    private function panels(): array
    {
        return [$this->terminalPanel, $this->resultsPanel, $this->debugPanel];
    }

    private function tabOf(TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel): DockTab
    {
        return match ($panel) {
            $this->terminalPanel => DockTab::Terminal,
            $this->resultsPanel => DockTab::Results,
            default => DockTab::Debug,
        };
    }

    /** The transient pickers served by the results panel — they bypass the dock strip. */
    private function isPickerMode(): bool
    {
        return \in_array($this->mode, [ResultsPanelMode::Action, ResultsPanelMode::Symbol], strict: true);
    }

    /** A panel opened (or re-opened): it takes the dock slot; the others park behind it. */
    private function activate(TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel): void
    {
        foreach ($this->panels() as $other) {
            if ($other === $panel) {
                continue;
            }

            $other->conceal();
        }
        $this->mru = [...array_values(array_filter($this->mru, static fn (AbstractWidget $seen): bool => $seen !== $panel)), $panel];
    }

    /**
     * A panel closed: reveal the most recently visible remaining tab, return
     * focus. Every entry still in the order is alive — a close always prunes
     * itself here through its own hook.
     */
    private function restoreAfter(TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel): void
    {
        $this->mru = array_values(array_filter($this->mru, static fn (AbstractWidget $seen): bool => $seen !== $panel));
        $last = $this->mru[\count($this->mru) - 1] ?? null;
        $last?->reveal();
        $this->returnFocusFrom($panel);
    }
}
