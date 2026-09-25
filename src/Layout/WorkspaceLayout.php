<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\I18n\BaseDirection;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\EdgeRuleWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Owns the workspace's composite widget tree: the generic layout containers
 * and separators (duplicate-typed, so the container cannot autowire them),
 * their assembly, the Tui Z-order, and the tab-strip position surgery. The
 * editor area's children are set by the RTL-aware pane ordering, not here.
 *
 * A full-screen view's own sidebar/separator/content is NOT here — that is
 * {@see ViewRegions}, minted at runtime. This class knew the git widgets by
 * name and stacked them into `git-left` at construction, which is the one place
 * core layout named a view's contents.
 */
#[AsIdeService]
final class WorkspaceLayout
{
    public readonly ContainerWidget $main;

    private readonly ContainerWidget $editorPane;

    public readonly ContainerWidget $editorBody;

    public readonly SeparatorWidget $separator;

    /** The rules under the menu bar and above the status bar. */
    public readonly EdgeRuleWidget $topRule;

    public readonly EdgeRuleWidget $bottomRule;

    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly TabBarWidget $tabBar,
        BreadcrumbsWidget $breadcrumbs,
        CodeEditorWidget $editor,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly FindBarWidget $findBar,
        private readonly DockTabsWidget $dockTabs,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly StatusBarWidget $statusBar,
        private readonly Styles $styles,
        ScreenDensity $density,
    ) {
        // The body is a fixed inner container: breadcrumbs, then the Ctrl+F find
        // bar (below the breadcrumbs, above the content), then the content slot.
        // BufferService's content swaps only remove + re-append the viewer, so
        // the breadcrumbs and the find bar stay put across the editor↔help↔image
        // swaps regardless of where the tab strip sits around the body.
        $this->editorBody = LayoutWidgets::container('editor-body', new Style(flex: 1));
        $this->editorBody->add($breadcrumbs)->add($this->findBar)->add($editor);

        $this->editorPane = LayoutWidgets::container('editor-pane', new Style(flex: 1));
        $this->editorPane->add($tabBar)->add($this->editorBody);

        $this->separator = LayoutWidgets::separator($this->styles, 'separator');
        $this->topRule = LayoutWidgets::edgeRule($this->styles, $density, 'top-rule');
        $this->bottomRule = LayoutWidgets::edgeRule($this->styles, $density, 'bottom-rule');
        $this->main = LayoutWidgets::container('main', new Style(direction: Direction::Horizontal, gap: 0));
    }

    /** The floating chrome Z-order: later adds composite over earlier ones. */
    public function mount(Tui $tui): void
    {
        $tui
            ->add($this->menuBar)
            ->add($this->topRule)
            ->add($this->main)
            ->add($this->dockTabs)
            ->add($this->resultsPanel)
            ->add($this->debugPanel)
            ->add($this->terminalPanel)
            ->add($this->contextMenu)
            ->add($this->bottomRule)
            ->add($this->statusBar);
    }

    /**
     * Re-seat the tab strip around the editor body: a one-row bar above/below
     * (vertical pane) or a fixed-width list beside it (horizontal pane).
     */
    private TabBarPosition $configuredTabs = TabBarPosition::Top;

    private bool $narrowTabs = false;

    /** The workspace reads right-to-left, so a side strip seats on the other edge. */
    private bool $mirrored = false;

    public function setTabBarPosition(TabBarPosition $position): void
    {
        // Remembered, because the responsive rule may be showing something else
        // right now and has to know what to put back. This is the only entry
        // the settings applier uses, at boot and on every change, so it is the
        // one place the user's CHOICE passes through.
        $this->configuredTabs = $position;
        $this->seatTabBar($this->narrowTabs ? TabBarPosition::Top : $position);
    }

    /**
     * Move a side tab strip to the top while the terminal cannot seat it, and
     * put it back where the user asked when the room returns.
     *
     * A no-op for a configured top/bottom bar: there is nothing to rescue, and
     * moving it would be inventing a preference.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain: "the terminal is too narrow to seat a side strip", one fact with two values, not a hidden second responsibility.
    public function setNarrowTabs(bool $narrow): void
    {
        if ($narrow === $this->narrowTabs) {
            return;
        }
        $this->narrowTabs = $narrow;
        if (!$this->configuredTabs->isSide()) {
            return;
        }

        $this->seatTabBar($narrow ? TabBarPosition::Top : $this->configuredTabs);
    }

    /** Whether the strip is currently sitting somewhere the user did not choose. */
    public function tabsAreDisplaced(): bool
    {
        return $this->narrowTabs && $this->configuredTabs->isSide();
    }

    /**
     * Tell the workspace which way it reads, and re-seat the tab strip.
     *
     * A side strip is placed by CHILD ORDER, which is physical, so a mirrored
     * workspace has to swap left for right — otherwise a right-hand strip stays
     * on the editor's right and lands between the editor and the (now
     * right-hand) file tree. Found by sweeping the surfaces in Hebrew.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain: "this workspace reads right-to-left", one fact with two values, exactly as setNarrowTabs() above.
    public function setMirrored(bool $mirrored): void
    {
        if ($mirrored === $this->mirrored) {
            return;
        }
        $this->mirrored = $mirrored;
        $this->tabBar->setTextDirection($mirrored ? BaseDirection::Rtl : BaseDirection::Ltr);
        $this->seatTabBar($this->narrowTabs ? TabBarPosition::Top : $this->configuredTabs);
    }

    private function seatTabBar(TabBarPosition $position): void
    {
        $position = $this->mirrored ? $position->mirrored() : $position;
        $this->tabBar->setPosition($position);
        $this->editorPane->setStyle($position->isSide() ? new Style(flex: 1, direction: Direction::Horizontal) : new Style(flex: 1));
        $this->editorPane->clear();
        foreach ($position->tabsFirst() ? [$this->tabBar, $this->editorBody] : [$this->editorBody, $this->tabBar] as $pane) {
            $this->editorPane->add($pane);
        }
    }

    /**
     * The editor area's LTR pane order (tree, drag separator, editor pane).
     *
     * @return list<AbstractWidget>
     */
    public function editorPanes(): array
    {
        return [$this->tree, $this->separator, $this->editorPane];
    }
}
