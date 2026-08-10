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
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class LayoutControllerNudgeTest extends TestCase
{
    private TempProject $project;
    private FileTreeWidget $tree;
    private GitSidebar $gitSidebar;
    private SettingsNavWidget $settingsSidebar;

    private function controller(int $columns = 100): LayoutController
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $tui = new Tui(terminal: new VirtualTerminal(columns: $columns, rows: 30), keybindings: new Keybindings([]), renderer: $renderer = new Renderer(new StyleSheet()));
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
            new Translator('en'),
        );
    }

    /** @return array<string, int|bool> the persisted layout.json contents */
    private function layoutFile(): array
    {
        $file = $this->project->dir.'/.porta/layout.json';
        if (!is_file($file)) {
            return [];
        }

        /** @var array<string, int|bool> */
        return json_decode((string) file_get_contents($file), associative: true);
    }

    /**
     * The two regions, each already told how its sidebar resizes — which is
     * what the container does once each view has minted and declared its own.
     */
    private static function regionsWith(ResizableSidebar $git, ResizableSidebar $settings): ViewRegions
    {
        $regions = new ViewRegions(Styles::dark());
        $regions->mint('git')->setSidebarPanes($git);
        $regions->mint('settings')->setSidebarPanes($settings);

        return $regions;
    }

    public function testNudgeSidebarResizesTheTreeAndPersistsThePercent(): void
    {
        $layout = $this->controller();
        $start = $this->tree->width();

        $layout->nudgeSidebar(2);
        $this->assertSame($start + 2, $this->tree->width());
        $this->assertSame($start + 2, $this->layoutFile()['sidebarWidthPercent'], '32 of 100 cols persists as 32%');

        $layout->nudgeSidebar(-2);
        $this->assertSame($start, $this->tree->width(), 'shrinking undoes the grow');
    }

    public function testNudgeSidebarClampsAtTheFloorAndCeiling(): void
    {
        $layout = $this->controller();

        for ($i = 0; $i < 30; ++$i) {
            $layout->nudgeSidebar(-2);
        }
        $this->assertSame(12, $this->tree->width(), 'the tree keeps its 12-column floor');

        for ($i = 0; $i < 60; ++$i) {
            $layout->nudgeSidebar(2);
        }
        $this->assertSame(60, $this->tree->width(), 'capped at 60% of a 100-column terminal');
    }

    public function testNudgeGitAndSettingsSidebarsUseTheirOwnClampsAndKeys(): void
    {
        $layout = $this->controller();

        $layout->nudgeViewSidebar('git', -200);
        $this->assertSame(30, $this->gitSidebar->width(), 'git sidebar floor is 30');

        $layout->nudgeViewSidebar('settings', 2);
        $file = $this->layoutFile();
        $this->assertSame(30, $file['gitSidebarWidthPercent']);
        $this->assertArrayHasKey('settingsSidebarWidthPercent', $file);
    }

    public function testNudgeBottomPanelIsANoOpWhenNoPanelIsOpen(): void
    {
        $layout = $this->controller();

        $layout->nudgeBottomPanel(1);
        $this->assertSame([], $this->layoutFile(), 'nothing to resize, nothing persisted');
    }

    public function testArmPanelResizeIgnoresAHiddenPanel(): void
    {
        $layout = $this->controller();
        $press = MouseEvent::parse("\x1b[<0;10;20M");
        $this->assertNotNull($press);

        $this->assertFalse($layout->armPanelResize(new TerminalPanelWidget(), $press), 'a closed panel has no resize grab');
    }

    /**
     * Each bound is now spelled once, on the pane. These pin the two that were
     * previously restated in the controller and disagreed with the widget: the
     * git floor (the controller's 30 always beat the widget's 24) and the
     * settings cap (the widget's 60 columns beats the controller's ratio on a
     * wide terminal — deleting it would have silently widened the pane).
     */
    public function testTheSettingsSidebarKeepsItsColumnCapOnAWideTerminal(): void
    {
        $layout = $this->controller(columns: 200);

        for ($i = 0; $i < 100; ++$i) {
            $layout->nudgeViewSidebar('settings', 2);
        }

        $this->assertSame(60, $this->settingsSidebar->width(), '60 columns, not 50% of 200');
    }

    public function testTheGitSidebarsFloorIsTheOneThatWasAlwaysEnforced(): void
    {
        $layout = $this->controller();

        $layout->nudgeViewSidebar('git', -200);

        $this->assertSame(30, $this->gitSidebar->width(), 'the pane declares 30 now, which is what the controller always clamped to');
        $this->assertSame(30, $this->gitSidebar->minWidth(), 'and it says so itself');
    }
}
