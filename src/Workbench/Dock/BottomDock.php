<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Container\AsIdeService;
use Porta\Event\DockTabActivationRequested;
use Porta\Event\DockTabCloseRequested;
use Porta\Event\DockTabMoveRequested;
use Porta\Event\DockToolBound;
use Porta\Event\DockToolClosed;
use Porta\Event\TerminalRevealRequested;
use Porta\Event\TerminalSessionCloseRequested;
use Porta\Terminal\TerminalSessions;
use Porta\Ui\Glyphs;
use Porta\Widget\DockPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\OverlayWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
 * tabs preserves rows/title/searching (and a Search tab's query definition)
 * AND the per-tab view state — filter text, grouping, collapse, context and
 * selection ride each tab as a frozen {@see ResultsViewState}, captured from
 * the widget before a rebind and restored after (identity-keyed, so a live
 * refresh of a parked tab cannot dislocate them). The dock also owns the
 * {@see ResultsPanelMode} routing and the focus/repaint/flash choreography.
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
#[AsIdeService]
final class BottomDock
{
    /** Null while a CONTRIBUTED tool is bound — it is not one of core's multiplexed contents. */
    private ?ResultsPanelMode $mode = ResultsPanelMode::Search;

    /**
     * The tab whose state the shared widget currently displays — the capture
     * target when bind() switches away. NOT tools->active(): in the
     * closeTab()→bind-neighbour path the active pointer has already moved, and
     * capturing "the active tab" would write the closed tab's view state onto
     * its neighbour.
     */
    private ?DockToolTab $boundTab = null;

    /** @var list<DockPanelWidget> visibility order, most recently visible last */
    private array $mru = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DockPanels $panelRegistry,
        private readonly DockTabsWidget $dockTabs,
        private readonly DockToolTabs $tools,
        private readonly TerminalSessions $sessions,
        private readonly Notifier $notifier,
        private readonly EditorArea $editorArea,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    #[AsEventListener]
    public function onTabSelected(DockTabActivationRequested $event): void
    {
        $this->reveal($event->tab, $event->key);
    }

    #[AsEventListener]
    public function onTabClosed(DockTabCloseRequested $event): void
    {
        $this->closeTab($event->tab, $event->key);
    }

    /**
     * A strip drag reorders within its own family: session tabs among
     * themselves, tool tabs among themselves (Debug is a single tab).
     */
    #[AsEventListener]
    public function onTabMoved(DockTabMoveRequested $event): void
    {
        match ($event->family) {
            DockTab::Terminal => $this->sessions->move($event->from, $event->to),
            DockTab::Results => $this->tools->move($event->from, $event->to),
            // A single-tab panel has nothing to reorder within.
            DockTab::Debug, null => null,
        };
    }

    /** Register the dock lifecycle hooks on the three panels and the tab strip; called once from WidgetHooks. */
    public function wire(): void
    {
        foreach ($this->panels() as $panel) {
            $panel->onOpen(fn () => $this->activate($panel));
            $panel->onClose(fn () => $this->restoreAfter($panel));
        }
        // Esc is a soft hide — the tool stays alive one tab away.
        $this->terminalPanel->onDismiss($this->hide(...));
        $this->resultsPanel->onDismiss($this->hide(...));
        foreach ($this->panelRegistry->panels as $entry) {
            $entry['panel']->onDismiss($this->hide(...));
        }
        $this->dockTabs->setProvider($this->aliveTabs(...));
        // The strip's active lid goes heavy while its panel holds the keys.
        $this->dockTabs->setFocusProbe(fn (): bool => null !== $this->tui->getFocus() && $this->visiblePanel() === $this->tui->getFocus());
    }

    /**
     * The strip rows — the single level of bottom-pane tabs: one entry per
     * terminal session, one per alive tool tab (off the models, so tabs stay
     * listed even while their panel widget is closed), then Debug. Only the
     * entry on display carries a status. Empty (the strip disappears) while
     * the dock is hidden.
     *
     * @return list<array{tab: ?DockTab, key: string, label: string, active: bool, status: string}>
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
        foreach ($this->sessions->sessions as $session) {
            $active = $terminalShown && $session === $activeSession;
            $tabs[] = [
                'tab' => DockTab::Terminal,
                'key' => $session->id,
                'label' => $session->title.($session->isRunning() || null === $session->process ? '' : ' '.Glyphs::SESSION_STOPPED),
                'active' => $active,
                'status' => $active ? $this->terminalPanel->statusText() : '',
            ];
        }
        $resultsShown = $visible === $this->resultsPanel;
        foreach ($this->tools->tabs as $tool) {
            $active = $resultsShown && $tool === $this->tools->active();
            $tabs[] = [
                'tab' => DockTab::Results,
                'key' => (string) $tool->id,
                'label' => $tool->title,
                'active' => $active,
                'status' => $active ? $this->resultsPanel->statusText() : '',
            ];
        }
        foreach ($this->panelRegistry->panels as $entry) {
            if (!$entry['panel']->isOpen()) {
                continue;
            }
            $tabs[] = [
                'tab' => $entry['tab'],
                'key' => $entry['id'],
                'label' => $entry['label'](),
                'active' => $visible === $entry['panel'],
                'status' => $entry['panel']->statusText(),
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
    public function reveal(?DockTab $tab, string $key = ''): void
    {
        if (DockTab::Terminal === $tab) {
            // Activate the clicked session, then re-open through the
            // controller so its buffer binds into the panel.
            $session = $this->sessions->find($key);
            if (null !== $session) {
                $this->sessions->makeActive($session);
            }
            $this->events?->dispatch(new TerminalRevealRequested());

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
        $panel = $this->panelRegistry->find($tab, $key);
        if (null === $panel || !$panel->isOpen()) {
            return;
        }
        if ($panel->isVisible()) {
            $this->focusPanel($panel);

            return;
        }
        $this->activate($panel);
        $panel->reveal();
        $this->focusPanel($panel);
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

    /** The × of the tab on display, from the keyboard (Ctrl+Shift+F4). */
    public function closeActive(): void
    {
        foreach ($this->aliveTabs() as $entry) {
            if (!$entry['active']) {
                continue;
            }
            $this->closeTab($entry['tab'], $entry['key']);

            return;
        }
    }

    /** The strip's ×: close the tab for real — a session tab stops its process too. */
    public function closeTab(?DockTab $tab, string $key = ''): void
    {
        if (DockTab::Terminal === $tab) {
            foreach ($this->sessions->sessions as $index => $session) {
                if ($session->id !== $key) {
                    continue;
                }

                $this->events?->dispatch(new TerminalSessionCloseRequested($index));
                break;
            }

            return;
        }
        if (DockTab::Results !== $tab) {
            $this->panelRegistry->find($tab, $key)?->close();

            return;
        }
        $tool = '' === $key ? $this->tools->active() : $this->tools->findById((int) $key);
        if (null === $tool) {
            return;
        }
        $wasBound = $this->activeTool() === $tool;
        $neighbour = $this->tools->close($tool);
        $this->events?->dispatch(new DockToolClosed($tool));
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
    public function visiblePanel(): ?DockPanelWidget
    {
        foreach ($this->panels() as $panel) {
            if ($panel->isVisible()) {
                return $panel;
            }
        }

        return null;
    }

    /** Drive the shared bottom-panel height (all three panels track one size). */
    public function setPanelRows(int $rows): void
    {
        foreach ($this->panels() as $panel) {
            $panel->setVisibleRows($rows);
        }
    }

    /** Which content the results panel is currently serving (drives query/jump routing); null under a contributed tool. */
    public function mode(): ?ResultsPanelMode
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
     * @param list<ResultRow> $rows
     */
    public function showList(ResultsPanelMode $mode, string $title, array $rows, ?ResultsPanelFields $fields = null): DockToolTab
    {
        $tab = $this->tools->openOrReuse($mode, $title, $fields ?? ResultsPanelFields::resultsOnly());
        $tab->rows = $rows;
        $tab->searching = false;
        $this->show($tab);

        return $tab;
    }

    /**
     * Open (or reuse, keyed by the tool's id) a CONTRIBUTED tool's tab, bind it
     * and focus it. The title is the tool's own and is only used when the tab
     * is created — the id is what identifies it.
     *
     * @param list<ResultRow> $rows
     */
    public function showTool(DockToolHandlers $handlers, string $title, array $rows): DockToolTab
    {
        $tab = $this->tools->openOrReuse(null, $title, ResultsPanelFields::resultsOnly(), $handlers);
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

    /** A contributed tool's tab, or null once the user has ×'d it — the staleness guard for a publisher that holds only an id. */
    public function findTool(string $id): ?DockToolTab
    {
        return $this->tools->findByToolId($id);
    }

    /** Whether one of core's own list tools is currently on the strip. */
    public function hasList(ResultsPanelMode $mode): bool
    {
        return null !== $this->tools->findByMode($mode);
    }

    /**
     * Replace a core list tool's rows in place — no open, no reveal, no focus
     * move, so a background refresh can never yank the user around. A silent
     * no-op when the tab is gone, which is also why $rows is a callable: rows
     * nobody can see are not worth building.
     *
     * @param callable(): list<ResultRow> $rows
     */
    public function refreshList(ResultsPanelMode $mode, callable $rows): void
    {
        $tab = $this->tools->findByMode($mode); // constant-title tools: at most one tab
        if (null === $tab) {
            return;
        }
        $tab->rows = $rows();
        $this->refreshIfBound($tab);
    }

    /**
     * A core list tool's rows, but only while that tool is the one ON DISPLAY —
     * [] otherwise. The display condition is the point: it is what makes a
     * per-frame reader cost nothing for a tool nobody is looking at.
     *
     * @return list<ResultRow>
     */
    public function displayedRows(ResultsPanelMode $mode): array
    {
        $tab = $this->tools->findByMode($mode);

        return null === $tab || $tab !== $this->activeTool() ? [] : $tab->rows;
    }

    /**
     * Select the row at (path, line) in the list tool on display. A parked
     * tab's selection lives in its frozen {@see ResultsViewState}, not in the
     * widget, so writing it there would be writing to a snapshot the next
     * switch overwrites — and a selection nobody can see is not worth keeping.
     */
    public function selectInList(ResultsPanelMode $mode, string $path, int $line): void
    {
        if ([] !== $this->displayedRows($mode)) {
            $this->resultsPanel->selectAt($path, $line);
        }
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
        $this->resultsPanel->refreshResults($tab->rows);
        if ($tab->searching) {
            $this->resultsPanel->setSearching();
        }
        $this->tui->requestRender();
    }

    /** Make $tab the displayed tool: freeze the outgoing tab's view state, open (reset) + rows, restore $tab's own. */
    private function bind(DockToolTab $tab): void
    {
        // Capture unconditionally while the outgoing tab is alive — a same-tab
        // rebind (tool re-invocation, hidden-dock re-reveal) wipes the widget
        // through open() too, so its live state must round-trip as well.
        if (null !== $this->boundTab && $this->tools->contains($this->boundTab)) {
            $this->boundTab->viewState = $this->resultsPanel->captureViewState();
        }
        $this->mode = $tab->mode;
        $this->tools->makeActive($tab);
        $this->resultsPanel->open($tab->title, $tab->fields);
        $this->resultsPanel->setResults($tab->rows);
        if (null !== $tab->viewState) {
            $this->resultsPanel->restoreViewState($tab->viewState);
        }
        if ($tab->searching) {
            $this->resultsPanel->setSearching();
        }
        $this->boundTab = $tab;
        // At the tail, after the tab is recorded: a listener that re-binds this
        // same tab then finds nothing to do instead of recursing.
        $this->events?->dispatch(new DockToolBound($tab));
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

    /** @return list<DockPanelWidget> */
    private function panels(): array
    {
        return [$this->terminalPanel, $this->resultsPanel, ...\array_map(static fn (array $e): DockPanelWidget => $e['panel'], $this->panelRegistry->panels)];
    }

    /** A panel opened (or re-opened): it takes the dock slot; the others park behind it. */
    private function activate(DockPanelWidget $panel): void
    {
        foreach ($this->panels() as $other) {
            if ($other === $panel) {
                continue;
            }

            $other->conceal();
        }
        $this->mru = [...\array_values(\array_filter($this->mru, static fn (AbstractWidget $seen): bool => $seen !== $panel)), $panel];
    }

    /**
     * A panel closed: reveal the most recently visible remaining tab, return
     * focus. Every entry still in the order is alive — a close always prunes
     * itself here through its own hook.
     */
    private function restoreAfter(DockPanelWidget $panel): void
    {
        $this->mru = \array_values(\array_filter($this->mru, static fn (AbstractWidget $seen): bool => $seen !== $panel));
        $last = $this->mru[\count($this->mru) - 1] ?? null;
        $last?->reveal();
        $this->returnFocusFrom($panel);
    }
}
