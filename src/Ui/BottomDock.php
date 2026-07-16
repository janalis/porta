<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\DebugPanelWidget;
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

    public function __construct(
        private readonly Tui $tui,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly Notifier $notifier,
        private readonly EditorArea $editorArea,
    ) {}

    /** Register the dock lifecycle hooks on the three panels; called once from WidgetHooks. */
    public function wire(): void
    {
        foreach ($this->panels() as $panel) {
            $panel->onOpen(fn () => $this->activate($panel));
            $panel->onClose(fn () => $this->restoreAfter($panel));
        }
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
