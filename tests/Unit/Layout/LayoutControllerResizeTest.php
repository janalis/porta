<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Layout\ResizableSidebar;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\Theme\Styles;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The sidebar resize drags, in both writing directions. RTL mirrors the pane
 * order ({@see \Porta\Layout\PaneLayout::order()}), so the sidebar sits on the
 * right, its separator is the column before it, and the drag grows the pane
 * leftward — the mirror image of every assertion below.
 */
final class LayoutControllerResizeTest extends TestCase
{
    private TempProject $project;
    private FileTreeWidget $tree;
    private GitSidebar $gitSidebar;
    private SettingsNavWidget $settingsSidebar;

    private function controller(string $locale = 'en'): LayoutController
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 30), keybindings: new Keybindings([]), renderer: $renderer = new Renderer(new StyleSheet()));
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->gitSidebar = new GitSidebar(
            new GitTabsWidget(new Translator('en')),
            new ChangesListWidget(new Translator('en')),
            new CommitBoxWidget(new Translator('en')),
            new HistoryListWidget(new Translator('en')),
        );
        $this->settingsSidebar = new SettingsNavWidget(new Translator('en'));

        return new LayoutController(
            new LayoutStore($this->project->dir),
            $tui,
            $renderer,
            $this->tree,
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            self::regionsWith($this->gitSidebar, $this->settingsSidebar),
            new Translator($locale),
        );
    }

    /**
     * The two regions, each already told how its sidebar resizes — which is
     * what each view does when it mints its own.
     */
    private static function regionsWith(ResizableSidebar $git, ResizableSidebar $settings): ViewRegions
    {
        $regions = new ViewRegions(Styles::dark());
        $regions->mint('git')->setSidebarPanes($git);
        $regions->mint('settings')->setSidebarPanes($settings);

        return $regions;
    }

    private function press(int $col): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<0;%d;%dM", $col + 1, 6));
        $this->assertNotNull($event);

        return $event;
    }

    private function drag(int $col): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<32;%d;%dM", $col + 1, 6));
        $this->assertNotNull($event);

        return $event;
    }

    public function testTheTreeSeparatorArmsAndResizesLeftToRight(): void
    {
        $layout = $this->controller();
        // The tree owns columns 0..19; its separator is the column past it.
        $separator = new WidgetRect(row: 0, col: 20, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);

        $this->assertFalse($layout->armSidebarResize($separator, $this->press(19)), 'a press inside the tree is not a resize');
        $this->assertTrue($layout->armSidebarResize($separator, $this->press(20)), 'the separator column arms the drag');

        $layout->continueResizeDrag($this->drag(30), $tree);
        $this->assertSame(30, $this->tree->width(), 'dragging right widens the tree to the pointer');
    }

    public function testTheTreeSeparatorArmsAndResizesRightToLeftUnderRtl(): void
    {
        $layout = $this->controller('he');
        // Mirrored: the tree owns columns 80..99, so its separator is column 79
        // and the pane grows leftward as the pointer moves away from the edge.
        $separator = new WidgetRect(row: 0, col: 79, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 80, columns: 20, rows: 30);

        $this->assertTrue($layout->armSidebarResize($separator, $this->press(79)), 'the mirrored separator column arms the drag');

        $layout->continueResizeDrag($this->drag(70), $tree);
        $this->assertSame(30, $this->tree->width(), 'dragging left widens the mirrored tree to the pointer');
    }

    public function testTheGitSidebarDragIsMirroredToo(): void
    {
        $layout = $this->controller('he');
        $separator = new WidgetRect(row: 0, col: 59, columns: 1, rows: 30);
        $left = new WidgetRect(row: 0, col: 60, columns: 40, rows: 30);

        $this->assertTrue($layout->armViewSidebarResize('git', $separator, $this->press(59)));

        $layout->continueResizeDrag($this->drag(50), null, $left);
        $this->assertSame(50, $this->gitSidebar->width(), 'the git sidebar grows leftward under RTL');
    }

    public function testTheSettingsSidebarDragIsMirroredToo(): void
    {
        $layout = $this->controller('he');
        $separator = new WidgetRect(row: 0, col: 69, columns: 1, rows: 30);
        $left = new WidgetRect(row: 0, col: 70, columns: 30, rows: 30);

        $this->assertTrue($layout->armViewSidebarResize('settings', $separator, $this->press(69)));

        $layout->continueResizeDrag($this->drag(60), null, $left);
        $this->assertSame(40, $this->settingsSidebar->width(), 'the settings sidebar grows leftward under RTL');
    }
}
