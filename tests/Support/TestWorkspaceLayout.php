<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Layout\WorkspaceLayout;
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
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Builds a {@see WorkspaceLayout} over throwaway widgets for unit tests, so
 * a constructor change lands here once instead of in every test that used to
 * spell out the full 15-widget construction. Pass named overrides for the
 * widgets a test needs to hold; every widget the factory creates itself gets
 * its production `#[AsWidget]` id (the container applies those in the real
 * boot), while overrides are used untouched.
 */
final class TestWorkspaceLayout
{
    public static function create(
        string $rootDir = '/tmp',
        ?FileTreeWidget $tree = null,
        ?TabBarWidget $tabBar = null,
        ?BreadcrumbsWidget $breadcrumbs = null,
        ?CodeEditorWidget $editor = null,
        ?MenuBarWidget $menuBar = null,
        ?ContextMenu $contextMenu = null,
        ?FindBarWidget $findBar = null,
        ?DockTabsWidget $dockTabs = null,
        ?ResultsPanelWidget $resultsPanel = null,
        ?DebugPanelWidget $debugPanel = null,
        ?TerminalPanelWidget $terminalPanel = null,
        ?StatusBarWidget $statusBar = null,
    ): WorkspaceLayout {
        $t = new Translator('en');

        return new WorkspaceLayout(
            $tree ?? self::withId(new FileTreeWidget($rootDir), id: 'tree'),
            $tabBar ?? self::withId(new TabBarWidget(), id: 'tabs'),
            $breadcrumbs ?? self::withId(new BreadcrumbsWidget(), id: 'breadcrumbs'),
            $editor ?? self::withId(new CodeEditorWidget(), id: 'editor'),
            $menuBar ?? self::withId(new MenuBarWidget(), id: 'menu'),
            $contextMenu ?? self::withId(new ContextMenu(), id: 'context-menu'),
            $findBar ?? self::withId(new FindBarWidget($t), id: 'find-bar'),
            $dockTabs ?? self::withId(new DockTabsWidget(), id: 'dock-tabs'),
            $resultsPanel ?? self::withId(new ResultsPanelWidget(), id: 'results'),
            $debugPanel ?? self::withId(new DebugPanelWidget(), id: 'debug'),
            $terminalPanel ?? self::withId(new TerminalPanelWidget(), id: 'terminal'),
            $statusBar ?? self::withId(new StatusBarWidget(), id: 'status'),
            Styles::dark(),
        );
    }

    /**
     * Assign the production widget id and hand the SAME instance back — a
     * closure loses the concrete type, and the constructor above needs it.
     *
     * @template T of AbstractWidget
     *
     * @param T $widget
     *
     * @return T
     */
    private static function withId(AbstractWidget $widget, string $id): AbstractWidget
    {
        $widget->setId($id);

        return $widget;
    }
}
