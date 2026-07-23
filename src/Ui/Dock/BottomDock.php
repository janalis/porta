<?php

declare(strict_types=1);

namespace Porta\Ui\Dock;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Terminal\TerminalSessions;
use Porta\Ui\EditorArea;
use Porta\Ui\Glyphs;
use Porta\Ui\Notifier;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\OverlayWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bottom dock: one panel area above the status bar shared by the
 * terminal panel, the debug panel and the list tools, with at most one panel
 * visible at a time. Every list tool lives as a {@see DockToolTab} in the
 * {@see DockToolTabs} model — the model, not the widget, is the source of
 * truth: the single results panel widget displays exactly one tab, async
 * completions write into their tab and {@see refreshIfBound()} pushes to the
 * widget only when that tab is the one on display. Tabs coexist (opening a
 * tool never closes another) and only their strip × closes them. Switching
 * tabs preserves rows/title/searching (and a Search tab's query definition);
 * filter text, collapse, context and selection reset — the accepted v1
 * trade-off of rebinding through the widget's open(). The dock also owns the
 * {@see ResultsPanelMode} routing and the focus/repaint/flash choreography.
 */
#[AsIdeService]
final class BottomDock
{
    private ResultsPanelMode $mode = ResultsPanelMode::Search;

    /** @var list<TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget> visibility order, most recently visible last */
    private array $mru = [];

    /** @var (callable(): void)|null reveals a Terminal tab by re-opening the active session (RunController) */
    private $onTerminalReveal = null;

    /** @var (callable(int): void)|null closes the session at an index for real (RunController) */
    private $onTerminalSessionClose = null;

    /** @var list<callable(DockToolTab): void> fired when a tool tab is bound into the panel */
    private array $onToolBound = [];

    /** @var list<callable(DockToolTab): void> fired when a tool tab is closed */
    private array $onToolClosed = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly DockTabsWidget $dockTabs,
        private readonly DockToolTabs $tools,
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
        // Esc is a soft hide — the tool stays alive one tab away.
        $this->terminalPanel->onDismiss($this->hide(...));
        $this->debugPanel->onDismiss($this->hide(...));
        $this->resultsPanel->onDismiss($this->hide(...));
        $this->dockTabs->setProvider($this->aliveTabs(...));
        $this->dockTabs->onTabSelected($this->reveal(...));
        $this->dockTabs->onTabClosed($this->closeTab(...));
        // A strip drag reorders within its own family: session tabs among
        // themselves, tool tabs among themselves (Debug is a single tab).
        $this->dockTabs->onTabMoved(fn (DockTab $family, int $from, int $to) => match ($family) {
            DockTab::Terminal => $this->sessions->move($from, $to),
            DockTab::Results => $this->tools->move($from, $to),
            DockTab::Debug => null,
        });
    }

    /** How a Terminal tab reveals itself: re-open the active session (wired to RunController). */
    public function onTerminalReveal(callable $callback): void
    {
        $this->onTerminalReveal = $callback;
    }

    /** How a session tab's × closes its session for real (wired to RunController). */
    public function onTerminalSessionClose(callable $callback): void
    {
        $this->onTerminalSessionClose = $callback;
    }

    /**
     * The strip rows — the single level of bottom-pane tabs: one entry per
     * terminal session, one per alive tool tab (off the models, so tabs stay
     * listed even while their panel widget is closed), then Debug. Only the
     * entry on display carries a status. Empty (the strip disappears) while
     * the dock is hidden.
     *
     * @return list<array{tab: DockTab, key: string, label: string, active: bool, status: string}>
     */
    public function aliveTabs(): array
    {
        $visible = $this->visiblePanel();
        if (null === $visible) {
            return [];
        }

        $tabs = [];
        $terminalShown = $visible === $this->terminalPanel;
        $activeSession = $this->sessions->active();
        foreach ($this->sessions->all() as $session) {
            $active = $terminalShown && $session === $activeSession;
            $tabs[] = [
                'tab' => DockTab::Terminal,
                'key' => $session->id,
                'label' => $session->title().($session->isRunning() || null === $session->process() ? '' : ' '.Glyphs::SESSION_STOPPED),
                'active' => $active,
                'status' => $active ? $this->terminalPanel->statusText() : '',
            ];
        }
        $resultsShown = $visible === $this->resultsPanel;
        foreach ($this->tools->all() as $tool) {
            $active = $resultsShown && $tool === $this->tools->active();
            $tabs[] = [
                'tab' => DockTab::Results,
                'key' => (string) $tool->id,
                'label' => $tool->title,
                'active' => $active,
                'status' => $active ? $this->resultsPanel->statusText() : '',
            ];
        }
        if ($this->debugPanel->isOpen()) {
            $title = $this->debugPanel->getTitle();
            $tabs[] = [
                'tab' => DockTab::Debug,
                'key' => '',
                'label' => $this->translator->trans('Debug').('' === $title ? '' : ': '.$title),
                'active' => $visible === $this->debugPanel,
                'status' => $this->debugPanel->statusText(),
            ];
        }

        return $tabs;
    }

    /** Whether the strip is on screen — it shifts the dock's resize grab up a row. */
    public function stripVisible(): bool
    {
        return [] !== $this->aliveTabs();
    }

    /** Bring a tab into the dock slot and focus it ($key: a tool tab's id / a session id, '' = the active one). */
    public function reveal(DockTab $tab, string $key = ''): void
    {
        if (DockTab::Terminal === $tab) {
            // Activate the clicked session, then re-open through the
            // controller so its buffer binds into the panel.
            $session = $this->sessions->find($key);
            if (null !== $session) {
                $this->sessions->makeActive($session);
            }
            if (null !== $this->onTerminalReveal) {
                ($this->onTerminalReveal)();
            }

            return;
        }
        if (DockTab::Results === $tab) {
            $tool = '' === $key ? $this->tools->active() : $this->tools->findById((int) $key);
            if (null === $tool) {
                return;
            }
            // Already the displayed tab: rebinding it would be a no-op, but the
            // focus is the other half of what a strip click is for — clicking
            // the tab you are looking at must still bring you to it.
            $this->activeTool() === $tool && $this->resultsPanel->isVisible()
                ? $this->focusPanel($this->resultsPanel)
                : $this->show($tool);

            return;
        }
        if (!$this->debugPanel->isOpen()) {
            return;
        }
        if ($this->debugPanel->isVisible()) {
            $this->focusPanel($this->debugPanel);

            return;
        }
        $this->activate($this->debugPanel);
        $this->debugPanel->reveal();
        $this->focusPanel($this->debugPanel);
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

    /** Reveal the next (+1) / previous (-1) strip tab — tool instances included. */
    public function cycle(int $delta): void
    {
        $order = $this->aliveTabs();
        $current = null;
        foreach ($order as $i => $entry) {
            if (!$entry['active']) {
                continue;
            }

            $current = $i;
            break;
        }
        if (null === $current || \count($order) < 2) {
            return;
        }
        $next = $order[($current + $delta + \count($order)) % \count($order)];
        $this->reveal($next['tab'], $next['key']);
    }

    /** The strip's ×: close the tab for real — a session tab stops its process too. */
    public function closeTab(DockTab $tab, string $key = ''): void
    {
        if (DockTab::Terminal === $tab) {
            foreach ($this->sessions->all() as $index => $session) {
                if ($session->id !== $key) {
                    continue;
                }

                if (null !== $this->onTerminalSessionClose) {
                    ($this->onTerminalSessionClose)($index);
                }
                break;
            }

            return;
        }
        if (DockTab::Debug === $tab) {
            $this->debugPanel->close();

            return;
        }
        $tool = '' === $key ? $this->tools->active() : $this->tools->findById((int) $key);
        if (null === $tool) {
            return;
        }
        $wasBound = $this->activeTool() === $tool;
        $neighbour = $this->tools->close($tool);
        foreach ($this->onToolClosed as $callback) {
            $callback($tool);
        }
        if (!$wasBound) {
            $this->tui->requestRender(); // the strip shrinks; the display is untouched

            return;
        }
        if (null !== $neighbour) {
            $this->bind($neighbour); // no focus steal on a mouse ×
            $this->tui->requestRender();

            return;
        }
        $this->resultsPanel->close(); // last tool gone: MRU restore + focus return
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

    /**
     * Open (or reuse-and-refresh, keyed by mode+title) a list tool's tab,
     * bind it into the panel and focus it.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $rows
     */
    public function showList(ResultsPanelMode $mode, string $title, array $rows, ?ResultsPanelFields $fields = null): DockToolTab
    {
        $tab = $this->tools->openOrReuse($mode, $title, $fields ?? ResultsPanelFields::resultsOnly());
        $tab->rows = $rows;
        $tab->searching = false;
        $this->show($tab);

        return $tab;
    }

    /** Bind a tool tab into the panel, focus it and repaint. */
    public function show(DockToolTab $tab): void
    {
        $this->bind($tab);
        $this->tui->setFocus($this->resultsPanel);
        $this->tui->requestRender();
    }

    /** Take the focus to an already-revealed panel and repaint. */
    private function focusPanel(OverlayWidget $panel): void
    {
        $this->tui->setFocus($panel);
        $this->tui->requestRender();
    }

    /** The tool tab the panel currently serves (open, not necessarily visible), or null (closed panel). */
    public function activeTool(): ?DockToolTab
    {
        return $this->resultsPanel->isOpen() ? $this->tools->active() : null;
    }

    /** Whether $tab is still in the strip — the async-completion staleness guard. */
    public function isToolAlive(DockToolTab $tab): bool
    {
        return $this->tools->contains($tab);
    }

    /** Flag a tab's async work; the widget shows 'searching…' when the tab is on display. */
    public function markSearching(DockToolTab $tab): void
    {
        $tab->searching = true;
        if ($this->activeTool() === $tab) {
            $this->resultsPanel->setSearching();
        }
        $this->tui->requestRender();
    }

    /** Push a tab's (just-updated) rows to the widget — only when that tab is the one on display. */
    public function refreshIfBound(DockToolTab $tab): void
    {
        if ($this->activeTool() !== $tab) {
            return; // the model stays fresh; binding the tab later shows it
        }
        $this->resultsPanel->setResults($tab->rows);
        if ($tab->searching) {
            $this->resultsPanel->setSearching();
        }
        $this->tui->requestRender();
    }

    /** Register a hook fired whenever a tool tab is bound into the panel. */
    public function onToolBound(callable $callback): void
    {
        $this->onToolBound[] = $callback;
    }

    /** Register a hook fired whenever a tool tab is closed (its × / programmatic close). */
    public function onToolClosed(callable $callback): void
    {
        $this->onToolClosed[] = $callback;
    }

    /** Make $tab the displayed tool: mode + widget open (reset semantics) + its rows. */
    private function bind(DockToolTab $tab): void
    {
        $this->mode = $tab->mode;
        $this->tools->makeActive($tab);
        $this->resultsPanel->open($tab->title, $tab->fields);
        $this->resultsPanel->setResults($tab->rows);
        if ($tab->searching) {
            $this->resultsPanel->setSearching();
        }
        foreach ($this->onToolBound as $callback) {
            $callback($tab);
        }
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
