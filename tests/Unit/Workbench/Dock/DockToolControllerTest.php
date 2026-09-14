<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Dock\ToolRun;
use Symfony\Component\Tui\Tui;

/**
 * The drain a plugin's list tool publishes through. Everything here is driven
 * by ID, never by a tab handle — that is what makes a late answer to a ×'d tab
 * a no-op instead of a resurrection.
 */
final class DockToolControllerTest extends TestCase
{
    private Tui $tui;
    private ResultsPanelWidget $results;
    private BottomDock $dock;

    /** @param list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> $tools */
    private function controller(array $tools, string $rootDir = '/p'): DockToolController
    {
        $this->tui = TestTui::create(100, 24);
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());

        return new DockToolController(TestDockTools::source($tools), $this->dock, new ProjectPaths($rootDir));
    }

    /** @return list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> */
    private static function tool(string $id = 'demo:todo', string $title = 'TODO'): array
    {
        return [['plugin' => 'demo', 'id' => $id, 'title' => $title, 'rerun' => null, 'activate' => null]];
    }

    public function testOpeningPublishesRowsUnderTheRegisteredTitleAndFocusesTheTab(): void
    {
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [['path' => 'a.php', 'line' => 3, 'text' => '// TODO one']]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame('TODO', $tab->title, 'the title is the registration\'s, not the caller\'s');
        $this->assertSame([['path' => 'a.php', 'line' => 3, 'text' => '// TODO one', 'ranges' => []]], $tab->rows);
        $this->assertNull($tab->mode, 'a contributed tool is not one of core\'s modes');
        $this->assertSame($this->results, $this->tui->getFocus());
    }

    public function testAnUnregisteredIdPublishesNothing(): void
    {
        // The API rejects this at the plugin's own call (PluginApiTest pins the
        // throw); reaching the drain means something else built the id, and a
        // silent nothing beats a tab nobody registered.
        $controller = $this->controller(self::tool());
        $controller->open('demo:ghost', [['path' => 'a.php', 'line' => 0, 'text' => 'x']]);

        $this->assertNull($this->dock->findTool('demo:ghost'));
        $this->assertSame([], $this->dock->aliveTabs());
    }

    public function testUpdateRefreshesInPlaceAndIsANoOpOnceTheTabIsClosed(): void
    {
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [], ToolRun::Running);
        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertTrue($tab->searching, 'work in flight shows as searching');

        $controller->update('demo:todo', [['path' => 'a.php', 'line' => 0, 'text' => 'landed']]);
        $this->assertFalse($tab->searching);
        $this->assertSame('landed', $tab->rows[0]['text']);

        // The staleness guard: the user ×s the tab while a scan is still out.
        $this->dock->closeTab(\Porta\Workbench\Dock\DockTab::Results, (string) $tab->id);
        $controller->update('demo:todo', [['path' => 'b.php', 'line' => 0, 'text' => 'too late']]);

        $this->assertNull($this->dock->findTool('demo:todo'), 'the late answer did not resurrect the tab');
    }

    public function testUpdateOnTheBoundTabPushesTheNewRowsIntoTheDisplayedPanel(): void
    {
        // Setting $tab->rows alone proves nothing about refreshIfBound() firing —
        // the panel's OWN copy only moves if that call actually happens.
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [['path' => 'a.php', 'line' => 0, 'text' => 'first']]);

        $controller->update('demo:todo', [['path' => 'a.php', 'line' => 0, 'text' => 'second']]);

        $this->assertSame(
            [['path' => 'a.php', 'line' => 0, 'text' => 'second', 'ranges' => []]],
            Probe::get($this->results, 'results'),
            'the bound panel is refreshed in place, not just the tab\'s own row copy',
        );
    }

    public function testANonAdjacentInvalidRowIsSkippedNotTruncating(): void
    {
        // A valid row placed AFTER an invalid one distinguishes "skip the bad
        // row and keep scanning" from "stop at the first bad row" — every row
        // in the other normalisation test is invalid from the first bad one
        // onward, so it can't tell the two apart.
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [
            ['path' => 'bad.php', 'text' => 'dropped, no line'],
            ['path' => 'ok.php', 'line' => 1, 'text' => 'must survive'],
        ]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame(
            [['path' => 'ok.php', 'line' => 1, 'text' => 'must survive', 'ranges' => []]],
            $tab->rows,
        );
    }

    public function testRangesAreValidatedFieldByFieldNotBundled(): void
    {
        // A range that IS an array but carries a non-int 'start' must still be
        // rejected — even though 'length' alone is fine — proving the
        // is_array/is_int checks are independent guards, not accidentally
        // paired by a stray && instead of ||.
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [
            ['path' => 'a.php', 'line' => 0, 'text' => 'x', 'ranges' => [['start' => 'not-an-int', 'length' => 3]]],
        ]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame([], $tab->rows[0]['ranges']);
    }

    public function testNegativeRangeBoundsClampToZeroExactly(): void
    {
        // max(0, x) mutated to max(-1, x) or max(1, x) both only differ from
        // the original at a deeply negative input — 0 is the one value every
        // one of those three formulas would otherwise agree on.
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [
            ['path' => 'a.php', 'line' => 0, 'text' => 'x', 'ranges' => [['start' => -5, 'length' => -5]]],
        ]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame([['start' => 0, 'length' => 0]], $tab->rows[0]['ranges']);
    }

    public function testMultipleValidRangesAreAllKeptNotTruncatedToOne(): void
    {
        $controller = $this->controller(self::tool());
        $controller->open('demo:todo', [
            ['path' => 'a.php', 'line' => 0, 'text' => 'x', 'ranges' => [['start' => 1, 'length' => 2], ['start' => 5, 'length' => 3]]],
        ]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame(
            [['start' => 1, 'length' => 2], ['start' => 5, 'length' => 3]],
            $tab->rows[0]['ranges'],
        );
    }

    public function testRowsAreNormalisedBecauseAPluginsShapeIsUnchecked(): void
    {
        // plugin.php files sit outside mago's analyzer, so this is the only
        // place a row's shape is enforced at all.
        $controller = $this->controller(self::tool(), rootDir: __DIR__);
        $controller->open('demo:todo', [
            ['path' => __FILE__, 'line' => -4, 'text' => 'absolute path, negative line'],
            ['path' => 'ok.php', 'line' => 2, 'text' => 'ranges kept', 'ranges' => [['start' => 1, 'length' => 3]]],
            ['path' => 'ok.php', 'line' => 2, 'text' => 'half a range', 'ranges' => [['start' => 1]]],
            ['path' => 'no-line.php', 'text' => 'dropped'],
            ['line' => 1, 'text' => 'dropped'],
            ['path' => 'x.php', 'line' => 1],
            'not even an array',
        ]);

        $tab = $this->dock->findTool('demo:todo');
        $this->assertNotNull($tab);
        $this->assertSame(
            [
                ['path' => \basename(__FILE__), 'line' => 0, 'text' => 'absolute path, negative line', 'ranges' => []],
                ['path' => 'ok.php', 'line' => 2, 'text' => 'ranges kept', 'ranges' => [['start' => 1, 'length' => 3]]],
                ['path' => 'ok.php', 'line' => 2, 'text' => 'half a range', 'ranges' => []],
            ],
            $tab->rows,
            'a row missing any of path/line/text is dropped; a malformed range set is dropped whole',
        );
    }

    public function testACoreToolAndAContributedOneCoexist(): void
    {
        $controller = $this->controller(self::tool());
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'a.php', 'line' => 0, 'text' => 'boom']]);
        $controller->open('demo:todo', [['path' => 'a.php', 'line' => 1, 'text' => 'TODO']]);

        $this->assertCount(2, $this->dock->aliveTabs(), 'tools coexist — opening one never closes another');
        $this->assertNotNull($this->dock->findTool('demo:todo'));
        $this->assertNull($this->dock->mode(), 'the contributed tab is the bound one');
    }
}
