<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\GitPlugin\View\GitSidebar;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Layout\LayoutController;
use Porta\Layout\ResizableSidebar;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\Theme\Styles;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\ResultsPanelMode;
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
    private Tui $tui;
    private Renderer $renderer;
    private ViewRegions $regions;

    private function controller(int $columns = 100): LayoutController
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: $columns, rows: 30), keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->gitSidebar = new GitSidebar(
            new GitTabsWidget(new Translator('en')),
            new ChangesListWidget(new Translator('en')),
            new CommitBoxWidget(new Translator('en')),
            new HistoryListWidget(new Translator('en')),
        );
        $this->settingsSidebar = new SettingsNavWidget(new Translator('en'));

        $this->regions = self::regionsWith($this->gitSidebar, $this->settingsSidebar);

        return new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $this->renderer,
            $this->tree,
            TestBottomDock::create($this->tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $this->regions,
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
        $this->assertSame(30, $file['viewSidebarWidthPercent']['git']);
        $this->assertArrayHasKey('settings', $file['viewSidebarWidthPercent']);
    }

    public function testForgetViewDropsTheCachedResizeStateSoAReMintResizesTheFreshSidebar(): void
    {
        // The live view lifecycle un-mints a region and mints a fresh one on
        // re-enable. Without forgetView() the cached SidebarResizeState keeps
        // wrapping the DEAD region's sidebar — nudges and drags would resize
        // widgets no longer on screen while the visible ones never move.
        $layout = $this->controller();
        $layout->nudgeViewSidebar('git', 2);
        $oldSidebar = $this->gitSidebar;
        $oldWidth = $oldSidebar->width();

        $layout->forgetView('git');
        $this->regions->forget('git');
        $fresh = new GitSidebar(
            new GitTabsWidget(new Translator('en')),
            new ChangesListWidget(new Translator('en')),
            new CommitBoxWidget(new Translator('en')),
            new HistoryListWidget(new Translator('en')),
        );
        $this->regions->mint('git')->setSidebarPanes($fresh);

        $layout->nudgeViewSidebar('git', 4);

        $this->assertSame($oldWidth, $oldSidebar->width(), 'the dead sidebar is not touched again');
        $this->assertNotSame($oldWidth, $fresh->width(), 'the nudge landed on the freshly minted sidebar');
    }

    public function testNudgingTheBottomPanelMovesItByEXACTLYTheDelta(): void
    {
        // The bug the panel's own row count fixes: the nudge used to size from
        // the renderer's rect, which is a frame behind, so presses inside one
        // burst all read the same height. Five presses have to be five rows.
        // (Here the rect is absent entirely — nothing has rendered — which is
        // the same fault at its extreme: the nudge was a no-op.)
        $layout = $this->controller();
        $results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $results, new DebugPanelWidget());
        $layout = new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $this->renderer,
            $this->tree,
            $dock,
            $this->regions,
            new Translator('en'),
        );
        $dock->showList(ResultsPanelMode::RecentLocations, 'Recent Locations', []);

        $before = $results->visibleRows();
        for ($i = 0; $i < 5; ++$i) {
            $layout->nudgeBottomPanel(1);
        }

        $this->assertSame($before + 5, $results->visibleRows(), 'five presses, five rows');

        for ($i = 0; $i < 5; ++$i) {
            $layout->nudgeBottomPanel(-1);
        }

        $this->assertSame($before, $results->visibleRows(), 'and back exactly where it started');
    }

    /** @return array{LayoutController, ResultsPanelWidget, \Porta\Workbench\Dock\BottomDock} */
    private function withOpenPanel(int $rows = 30): array
    {
        $this->controller(); // builds the tui, tree and regions these rewire
        $this->tui->getTerminal()->simulateResize(columns: 100, rows: $rows);
        $results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $results, new DebugPanelWidget());
        $layout = new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $this->renderer,
            $this->tree,
            $dock,
            $this->regions,
            new Translator('en'),
        );
        $dock->showList(ResultsPanelMode::RecentLocations, 'Recent Locations', []);

        return [$layout, $results, $dock];
    }

    public function testAHandChosenHeightENDSMaximisedMode(): void
    {
        // Otherwise the next toggle "restores" a height the user has since
        // moved away from, which reads as the panel jumping on its own.
        [$layout, $results] = $this->withOpenPanel();
        $layout->toggleBottomPanelMaximised();
        $this->assertTrue($layout->isBottomPanelMaximised());

        $layout->nudgeBottomPanel(-1);

        $this->assertFalse($layout->isBottomPanelMaximised(), 'the nudge ended it');
        $chosen = $results->visibleRows();

        $layout->toggleBottomPanelMaximised();
        $this->assertSame(30 - 6, $results->visibleRows(), 'so this maximises rather than restores');
        $layout->toggleBottomPanelMaximised();
        $this->assertSame($chosen, $results->visibleRows(), 'and comes back to the hand-chosen height');
    }

    public function testMaximiseAsksForAFrame(): void
    {
        // Both halves of the toggle change a widget's size without touching
        // input, so nothing else would repaint them.
        [$layout] = $this->withOpenPanel();

        foreach ([true, false] as $expectedMaximised) {
            $this->tui->tick(); // clears any pending request
            $this->assertFalse(Probe::get($this->tui, 'renderRequested'), 'nothing pending');

            $layout->toggleBottomPanelMaximised();

            $this->assertSame($expectedMaximised, $layout->isBottomPanelMaximised());
            $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the toggle asked for a frame');
        }
    }

    public function testOnATINYTerminalTheMaximisedPanelKeepsTheWidgetsFloor(): void
    {
        // 8 rows less the 6 the frame needs is 2, which is below what a panel
        // can be. The floor is the controller's, so the arithmetic cannot hand
        // the widget a height its own clamp would then silently change.
        [$layout, $results] = $this->withOpenPanel(rows: 8);

        $layout->toggleBottomPanelMaximised();

        $this->assertSame(3, $results->visibleRows());
    }

    public function testMaximiseIsANoOpWithNoPanelOpen(): void
    {
        // Nothing to maximise. Opening one to fill the screen is not what the
        // key asked for, and it must not arm the restore either — a later
        // toggle would then resize a panel the user never maximised.
        $layout = $this->controller();

        $layout->toggleBottomPanelMaximised();

        $this->assertFalse($layout->isBottomPanelMaximised());
        $this->assertSame([], $this->layoutFile(), 'and nothing persisted');
    }

    public function testAMaximisedPanelRETAKESTheScreenWhenTheTerminalResizes(): void
    {
        // Otherwise a resize snaps the panel back to its stored proportion,
        // silently un-maximising it while the flag still says otherwise.
        $this->controller(); // builds the tui, tree and regions this one rewires
        $results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $results, new DebugPanelWidget());
        $layout = new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $this->renderer,
            $this->tree,
            $dock,
            $this->regions,
            new Translator('en'),
        );
        $dock->showList(ResultsPanelMode::RecentLocations, 'Recent Locations', []);
        $layout->nudgeBottomPanel(1); // persist a proportion, so applyLayout has one to restore
        $layout->toggleBottomPanelMaximised();
        $this->assertSame(30 - 6, $results->visibleRows());

        $this->tui->getTerminal()->simulateResize(columns: 100, rows: 40);
        $layout->applyLayout();

        $this->assertSame(40 - 6, $results->visibleRows(), 'it took the bigger screen');
        $this->assertTrue($layout->isBottomPanelMaximised(), 'and is still maximised');
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

    public function testNudgeSidebarRequestsARender(): void
    {
        $layout = $this->controller();
        Probe::set($this->tui, 'renderRequested', false);

        $layout->nudgeSidebar(2);

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testClampSidebarWidthsColsMinus20FloorBindsBeforeTheRatio(): void
    {
        // On a 40-column terminal the tree's own 0.6 ratio would allow 24
        // columns, but the neighbour-usability floor (cols - 20 = 20) is
        // tighter and must win — this is the only bound this method itself
        // owns (the others come from the pane), so it needs its own exact
        // boundary and sign pinned directly.
        $layout = $this->controller(columns: 40);

        $layout->nudgeSidebar(1_000_000);

        $this->assertSame(20, $this->tree->width(), 'clamped at cols - 20, not the 24-column ratio bound');
    }

    public function testClampSidebarWidthRoundsRatherThanCeilingUp(): void
    {
        // 82 * 0.6 = 49.2: round and floor agree at 49, but ceil would push
        // it to 50 — isolates the RoundingFamily mutant that only a
        // ceil-shaped input exposes.
        $layout = $this->controller(columns: 82);

        $layout->nudgeSidebar(1_000_000);

        $this->assertSame(49, $this->tree->width());
    }

    public function testClampSidebarWidthRoundsRatherThanFlooringDown(): void
    {
        // 81 * 0.6 = 48.6: round and ceil agree at 49, but floor would give
        // 48 — the complementary case to the one above.
        $layout = $this->controller(columns: 81);

        $layout->nudgeSidebar(1_000_000);

        $this->assertSame(49, $this->tree->width());
    }

    public function testCommitPercentRoundsHalfUpNotDownToZero(): void
    {
        // Forced to the tree's 12-column floor on a 2400-column terminal:
        // 12*100/2400 = 0.5, which rounds up to 1% — a *99 multiplier (or a
        // floor()) would instead read as 0%, silently dropping the fact that
        // any sidebar is there at all.
        $layout = $this->controller(columns: 2400);

        $layout->nudgeSidebar(-1_000_000);

        $this->assertSame(1, $this->layoutFile()['sidebarWidthPercent']);
    }

    public function testCommitPercentDoesNotCeilAFractionBelowHalf(): void
    {
        // 12*100/4000 = 0.3: rounds down to 0%. A ceil() would report 1%
        // instead — a sidebar collapsed to nothing must persist as 0, not a
        // phantom sliver.
        $layout = $this->controller(columns: 4000);

        $layout->nudgeSidebar(-1_000_000);

        $this->assertSame(0, $this->layoutFile()['sidebarWidthPercent']);
    }

    public function testCommitPercentDoesNotInflateTheNumeratorBy1Percent(): void
    {
        // 12*100/2406 ≈ 0.4988%, rounding down to 0. A value*101 mutant pushes
        // it to ≈0.5038%, which rounds up to 1 — the numerator must be exactly
        // value*100, not value*101.
        $layout = $this->controller(columns: 2406);

        $layout->nudgeSidebar(-1_000_000);

        $this->assertSame(0, $this->layoutFile()['sidebarWidthPercent']);
    }
}
