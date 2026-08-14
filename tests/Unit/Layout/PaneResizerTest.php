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

        $this->assertGreaterThan($before, $this->nav->width(), 'and the chord widened that region');
    }

    public function testAViewThatDeclaredNoResizableSidebarIsNotResizable(): void
    {
        // The chord has to fall through to the widget instead. A region whose
        // view never called setSidebarPanes() has no width to fan out, so
        // claiming the key would swallow it for nothing.
        $this->assertFalse($this->resizer(declareSidebar: false)->canResize());
    }
}
