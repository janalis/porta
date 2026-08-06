<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
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
    private readonly ContainerWidget $main;

    private readonly ContainerWidget $editorPane;

    private readonly ContainerWidget $editorBody;

    private readonly SeparatorWidget $separator;

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
    ) {
        // The body is a fixed inner container: breadcrumbs, then the Ctrl+F find
        // bar (below the breadcrumbs, above the content), then the content slot.
        // BufferService's content swaps only remove + re-append the viewer, so
        // the breadcrumbs and the find bar stay put across the editor↔help↔image
        // swaps regardless of where the tab strip sits around the body.
        $this->editorBody = $this->container('editor-body', new Style(flex: 1));
        $this->editorBody->add($breadcrumbs)->add($this->findBar)->add($editor);

        $this->editorPane = $this->container('editor-pane', new Style(flex: 1));
        $this->editorPane->add($tabBar)->add($this->editorBody);

        $this->separator = $this->separatorWidget('separator');
        $this->main = $this->container('main', new Style(direction: Direction::Horizontal, gap: 0));
    }

    /** The floating chrome Z-order: later adds composite over earlier ones. */
    public function mount(Tui $tui): void
    {
        $tui
            ->add($this->menuBar)
            ->add($this->main)
            ->add($this->dockTabs)
            ->add($this->resultsPanel)
            ->add($this->debugPanel)
            ->add($this->terminalPanel)
            ->add($this->contextMenu)
            ->add($this->statusBar);
    }

    /**
     * Re-seat the tab strip around the editor body: a one-row bar above/below
     * (vertical pane) or a fixed-width list beside it (horizontal pane).
     */
    public function setTabBarPosition(TabBarPosition $position): void
    {
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

    public function main(): ContainerWidget
    {
        return $this->main;
    }

    public function editorBody(): ContainerWidget
    {
        return $this->editorBody;
    }

    public function separator(): SeparatorWidget
    {
        return $this->separator;
    }

    private function container(string $id, Style $style): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setId($id);
        $container->setStyle($style);
        $container->expandVertically(true);

        return $container;
    }

    private function separatorWidget(string $id): SeparatorWidget
    {
        $separator = new SeparatorWidget($this->styles);
        $separator->setId($id)->setStyle(new Style(flex: 0));

        return $separator;
    }
}
