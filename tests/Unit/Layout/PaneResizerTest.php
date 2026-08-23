<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\I18n\Translator;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneResizer;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Theme\Styles;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;

/**
 * Shift+F11/F12 over a view's sidebar.
 *
 * Two hardcoded three-widget lists stood in the match, one per view core knew.
 * A region's sidebar IS the list — whatever the view stacked in it — so a
 * contributed view answers the same question, and a view that declared no
 * resizable sidebar answers no.
 *
 * Worth a test of its own because the old form was covered by accident: an
 * `in_array(...)` arm inside a match runs whether or not it matches, so the
 * line counted as executed while nothing ever took the branch.
 */
final class PaneResizerTest extends TestCase
{
    private ViewRegions $regions;
    private SettingsNavWidget $nav;

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    private function resizer(bool $declareSidebar = true): PaneResizer
    {
        $project = new TempProject();
        $tui = TestTui::create(120, 30);
        $this->regions = new ViewRegions(Styles::dark());
        $this->nav = new SettingsNavWidget(new Translator('en'));
        $region = $this->regions->mint('settings');
        $region->sidebar()->add($this->nav);
        if ($declareSidebar) {
            $region->setSidebarPanes($this->nav);
        }

        $layout = new LayoutController(
            new LayoutStore($project->dir),
            $tui,
            new Renderer(new StyleSheet()),
            new FileTreeWidget($project->dir),
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $this->regions,
            new Translator('en'),
        );
        $tui->add($this->nav);
        $tui->setFocus($this->nav);

        return new PaneResizer(
            $tui,
            $layout,
            new FileTreeWidget($project->dir),
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $this->regions,
        );
    }

    public function testFocusInsideAViewSidebarResizesThatView(): void
    {
        $resizer = $this->resizer();
        $before = $this->nav->width();

        $this->assertTrue($resizer->canResize(), 'the focused widget is in a region sidebar');
        $resizer->resize(1);

        $this->assertSame($before + 2, $this->nav->width(), 'exactly 2 columns per direction unit, not 1 or 3');

        // direction=3: 2*3=6 (an int) vs a corrupted 2/3 (a non-integer
        // float) — the multiplication itself, not just its magnitude.
        $resizer->resize(3);
        $this->assertSame($before + 2 + 6, $this->nav->width(), 'multiplication, not division — 2/3 would not even be a valid int delta');
    }

    public function testAViewThatDeclaredNoResizableSidebarIsNotResizable(): void
    {
        // The chord has to fall through to the widget instead. A region whose
        // view never called setSidebarPanes() has no width to fan out, so
        // claiming the key would swallow it for nothing.
        $this->assertFalse($this->resizer(declareSidebar: false)->canResize());
    }

    public function testFocusOnTheTreeResizesItByExactlyTwoColumnsPerDirection(): void
    {
        $project = new TempProject();
        $tui = TestTui::create(120, 30);
        $tree = new FileTreeWidget($project->dir);
        $regions = new ViewRegions(Styles::dark());
        $layout = new LayoutController(
            new LayoutStore($project->dir),
            $tui,
            new Renderer(new StyleSheet()),
            $tree,
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $regions,
            new Translator('en'),
        );
        $tui->add($tree);
        $tui->setFocus($tree);
        $resizer = new PaneResizer(
            $tui,
            $layout,
            $tree,
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $regions,
        );
        $before = $tree->width();

        $this->assertTrue($resizer->canResize(), 'the tree itself is a resizable pane, not just view sidebars');
        $resizer->resize(1);

        $this->assertSame($before + 2, $tree->width(), 'exactly 2 columns per direction unit, not 1 or 3');

        // direction=3: 2*3=6 (an int) vs a corrupted 2/3 (a non-integer
        // float) — the multiplication itself, not just its magnitude at
        // direction=1, where 2*1 and 2/1 coincide.
        $resizer->resize(3);
        $this->assertSame($before + 2 + 6, $tree->width(), 'multiplication, not division — 2/3 would not even be a valid int delta');
    }

    public function testFocusOnTheVisibleBottomPanelIsResizable(): void
    {
        // Distinguishes the dock-panel match arm's identity check from the
        // arms above/below it: a focus that IS the dock's own visible panel
        // must claim the chord, not fall through to "not resizable".
        $project = new TempProject();
        $tui = TestTui::create(120, 30);
        $debug = new DebugPanelWidget();
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), $debug);
        $debug->open('a.php');
        $tui->add($debug);
        $tui->setFocus($debug);
        $regions = new ViewRegions(Styles::dark());
        $layout = new LayoutController(
            new LayoutStore($project->dir),
            $tui,
            new Renderer(new StyleSheet()),
            new FileTreeWidget($project->dir),
            $dock,
            $regions,
            new Translator('en'),
        );
        $resizer = new PaneResizer($tui, $layout, new FileTreeWidget($project->dir), $dock, $regions);

        $this->assertSame($debug, $dock->visiblePanel(), 'sanity: the debug panel is the one the dock shows');
        $this->assertTrue($resizer->canResize(), 'the focused widget is the dock\'s own visible panel');
    }
}
