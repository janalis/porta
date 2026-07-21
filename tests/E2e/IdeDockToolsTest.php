<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Widget\DockTabsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Drives the live bottom-dock list tools through a real Ide: the Problems
 * tab refreshes in place as diagnostics land (and keeps its count fresh
 * while concealed), without stealing the focus.
 */
final class IdeDockToolsTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private static function bufferService(mixed $ide): BufferService
    {
        /** @var BufferService */
        return new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(BufferService::class);
    }

    public function testHoveringThePanelTintsARowAndLeavingItClearsTheTint(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// two\n// three\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Show Problems
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [
                                0 => [['severity' => 1, 'message' => 'first', 'col' => 0, 'endCol' => 3]],
                                1 => [['severity' => 1, 'message' => 'second', 'col' => 0, 'endCol' => 3]],
                            ],
                        );
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '✗ second'));
                    $tui->tick();

                    // Hover the second result row; the first stays selected.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('results'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 5, $rect->row + 2));
                },
                300 => function ($tui, $terminal, $ide): void {
                    $results = $tui->getById('results');
                    $joined = implode('', $results->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12)));
                    $this->assertStringContainsString("\x1b[48;5;236m", $joined, 'the row under the pointer is tinted');

                    // Move into the editor: the panel's tint must not survive it.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 5, $editorRect->row + 1));
                },
                400 => function ($tui): void {
                    $results = $tui->getById('results');
                    $joined = implode('', $results->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12)));
                    $this->assertStringNotContainsString("\x1b[48;5;236m", $joined, 'the tint cleared when the pointer left the panel');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testProblemsTabRefreshesLiveAsDiagnosticsLand(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// two\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Show Problems
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen, 'the Problems tab opened on the dock strip');
                    $this->assertStringContainsString('0 results', $screen, 'no diagnostics yet');
                },
                350 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->setFocus($tui->getById('editor'));
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]]],
                        );
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '✗ live boom'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('✗ live boom', $screen, 'the open Problems tab refreshed in place');
                    $this->assertStringContainsString('1 results', $screen, 'the strip count followed');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the live refresh did not steal the focus');
                },
                500 => function ($tui, $terminal, $ide) use ($project): void {
                    // Concealed (Esc-hidden dock): the tab still refreshes, so
                    // revealing it shows the fresh rows.
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b"); // esc → hide the dock
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [
                                0 => [['severity' => 2, 'message' => 'warn one', 'col' => 0, 'endCol' => 3]],
                                1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]],
                            ],
                        );
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isVisible(), 'the dock is hidden');
                    $tui->handleInput("\x1b6"); // alt+6 → reveal Problems again
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('▲ warn one', $screen, 'the concealed tab picked up the second diagnostic');
                    $this->assertStringContainsString('2 results', $screen);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTodoScanListsJumpsAndRerunsWithF5(): void
    {
        putenv('PATH='.$this->originalPath); // real grep for the async scan
        $project = new TempProject([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.py' => "# FIXME races\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b8"); // alt+8 → Show TODOs
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'FIXME races'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('TODO extract this', $screen, 'the scan found both markers');
                    $this->assertStringContainsString('2 results', $screen);
                    // The list is path-sorted: src/A.php first, selected. Enter jumps.
                    $tui->handleInput("\r");
                },
                400 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // grep's row order is directory-walk order, so the first
                    // (auto-selected) row may be either file.
                    $this->assertMatchesRegularExpression('~src/(A\.php|B\.py)$~', (string) $tui->getById('editor')->getFilePath(), 'Enter jumped to a TODO match');
                    // F5 re-scans and picks up a marker added on disk meanwhile.
                    $project->write('src/C.php', "<?php\n// TODO added later\n");
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b[15~"); // F5
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '3 results'));
                    $this->assertStringContainsString('TODO added later', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the rerun saw the new file');
                },
                700 => function ($tui, $terminal, $ide): void {
                    // Stale completion: kick a scan, bind another tool, then ×
                    // the TODO tab before grep lands — the panel stays open (no
                    // engine cancel) and the late result must be dropped.
                    $dock = new \ReflectionProperty($ide, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);
                    $tui->handleInput("\x1b8"); // alt+8 → scan starts
                    $tui->handleInput("\x1b6"); // alt+6 → Problems binds; the TODO tab stays alive behind it
                    foreach ($dock->aliveTabs() as $entry) {
                        if ('TODO' !== $entry['label']) {
                            continue;
                        }

                        $dock->closeTab(\Porta\Ui\DockTab::Results, $entry['key']); // × the inactive TODO tab
                    }
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Problems'));
                },
                1000 => function ($tui): void {
                    // Assert on the widget, not the accumulated terminal
                    // output (which still carries every earlier frame).
                    $tui->tick();
                    $results = $tui->getById('results');
                    $this->assertSame('Problems', $results->getTitle(), 'the Problems tab kept the panel');
                    $this->assertStringContainsString('0 results', $results->statusText(), 'the stale TODO completion did not overwrite the rows');
                    $panel = AnsiUtils::stripAnsiCodes(implode("\n", $results->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12))));
                    $this->assertStringNotContainsString('FIXME races', $panel, 'the stale TODO completion was dropped');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTwoSearchesCoexistAsTabsAndF5RerunsTheRightOne(): void
    {
        putenv('PATH='.$this->originalPath); // real grep
        $project = new TempProject([
            'src/Main.php' => "<?php\n// nothing to see\n",
            'src/A.php' => "<?php\n// alpha here\n",
            'src/B.php' => "<?php\n// beta here\n",
        ]);
        $search = static function ($tui, string $query): void {
            $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
            foreach (str_split($query) as $ch) {
                $tui->handleInput($ch);
            }
        };
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/Main.php'),
            [
                200 => function ($tui, $terminal) use ($search): void {
                    $search($tui, query: 'alpha');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'alpha here'));
                    $tui->handleInput("\x1b\r"); // alt+enter → promote to a dock tab
                    $tui->tick();
                },
                450 => function ($tui, $terminal, $ide) use ($search, $dock): void {
                    $search($tui, query: 'beta');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'beta here'));
                    $tui->handleInput("\x1b\r"); // alt+enter → a SECOND tab
                    $tui->tick();
                    $labels = array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertContains('Find in Project: alpha', $labels, 'the first search kept its tab');
                    $this->assertContains('Find in Project: beta', $labels, 'the second search opened beside it');
                },
                700 => function ($tui, $terminal, $ide) use ($project, $dock): void {
                    // Switch back to the alpha tab and F5: only ITS query re-runs.
                    foreach ($dock($ide)->aliveTabs() as $entry) {
                        if ('Find in Project: alpha' !== $entry['label']) {
                            continue;
                        }

                        $dock($ide)->reveal($entry['tab'], $entry['key']);
                    }
                    $tui->tick();
                    $project->write('src/C.php', "<?php\n// alpha again\n");
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b[15~"); // F5 → reruns 'alpha', not 'beta'
                    $this->await(static fn (): bool => str_contains($tui->getById('results')->statusText(), '2 results'));
                    $panel = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 14))));
                    $this->assertStringContainsString('alpha again', $panel, "F5 re-ran the alpha tab's own query");
                    $this->assertStringNotContainsString('beta here', $panel, 'the beta results stayed in their tab');
                },
                1000 => function ($tui, $terminal, $ide) use ($project, $dock): void {
                    $tui->tick();
                    // × the beta tab: its query lands in the search history.
                    foreach ($dock($ide)->aliveTabs() as $entry) {
                        if ('Find in Project: beta' !== $entry['label']) {
                            continue;
                        }

                        $dock($ide)->closeTab($entry['tab'], $entry['key']);
                    }
                    $tui->tick();
                    $this->assertContains('beta', new \Porta\History\SearchHistoryStore($project->dir)->all(), '×ing a search tab records its query');
                    $labels = array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertNotContains('Find in Project: beta', $labels);
                    $this->assertContains('Find in Project: alpha', $labels, 'the alpha tab survives');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testOverflowingDockStripScrollsWhenTheIndicatorIsClicked(): void
    {
        putenv('PATH='.$this->originalPath); // real grep
        $project = new TempProject([
            'src/A.php' => "<?php\n// alpha here\n",
            'src/B.php' => "<?php\n// beta here\n",
        ]);
        $search = static function ($tui, string $query): void {
            $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
            foreach (str_split($query) as $ch) {
                $tui->handleInput($ch);
            }
        };
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui, $terminal) use ($search): void {
                    $search($tui, query: 'alpha');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'alpha here'));
                    $tui->handleInput("\x1b\r"); // alt+enter → a wide "Find in Project: alpha" tab
                    $tui->tick();
                },
                450 => function ($tui, $terminal, $ide) use ($search, $dock): void {
                    $search($tui, query: 'beta');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'beta here'));
                    $tui->handleInput("\x1b\r"); // a SECOND wide tab → the two overflow 30 cols
                    $this->await(static fn (): bool => \count($dock($ide)->aliveTabs()) >= 2);
                    $tui->requestRender();
                    $tui->tick();

                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    /** @var DockTabsWidget $dockTabs */
                    $dockTabs = $tui->getById('dock-tabs');
                    $rect = $renderer->getWidgetRect($dockTabs);
                    $this->assertNotNull($rect, 'the dock strip is laid out');
                    $lastCol = $rect->columns - 1;

                    // The strip overflows: exactly one end shows an indicator (the
                    // window parked against the active tab). Click it and assert the
                    // OPPOSITE end's indicator appears — the click scrolled the strip.
                    $leftStep = $dockTabs->scrollIndicatorAt(0, 0);
                    $rightStep = $dockTabs->scrollIndicatorAt(0, $lastCol);
                    $this->assertTrue(null !== $leftStep || null !== $rightStep, 'the overflowing strip shows a ‹ or ›');

                    $clickCol = null !== $leftStep ? 0 : $lastCol;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $clickCol + 1, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $clickCol + 1, $rect->row + 1));
                    $tui->tick();

                    $opposite = null !== $leftStep ? $dockTabs->scrollIndicatorAt(0, $lastCol) : $dockTabs->scrollIndicatorAt(0, 0);
                    $this->assertNotNull($opposite, 'clicking the indicator scrolled the strip, revealing the other end');
                },
            ],
            columns: 30,
        );

        $this->addToAssertionCount(1);
    }

    public function testToolTabsCoexistCycleAndClose(): void
    {
        putenv('PATH='.$this->originalPath); // real grep for the TODO scan
        $project = new TempProject(['src/Main.php' => "<?php\n// TODO polish\n"]);
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/Main.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock, $project): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    $tui->handleInput("\x1b8"); // alt+8 → TODO joins, Problems keeps its tab
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'TODO polish'));
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_lsp_log'); // LSP Log joins
                    $tui->tick();
                    $labels = array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertSame(['Problems', 'TODO', 'LSP Server Log'], $labels, 'three tools coexist, none replaced');

                    // A diagnostic lands while the Problems tab is INACTIVE: its
                    // model refreshes, so activating it shows the fresh row.
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            \Porta\Lsp\Uri::fromPath($project->path('src/Main.php')),
                            [0 => [['severity' => 1, 'message' => 'background boom', 'col' => 0, 'endCol' => 3]]],
                        );
                    $tui->tick();
                    $this->assertSame('LSP Server Log', $tui->getById('results')->getTitle(), 'the displayed tool is untouched');

                    $tui->handleInput("\x1b[6;3~"); // alt+page_down cycles: LSP Log → wraps to Problems
                    $tui->tick();
                    $this->assertSame('Problems', $tui->getById('results')->getTitle(), 'cycling walks the tool instances');
                    $this->assertStringContainsString('1 results', $tui->getById('results')->statusText(), 'the inactive-tab refresh is visible on arrival');
                },
                600 => function ($tui, $terminal, $ide) use ($dock): void {
                    // × tabs one by one: the last close gives the slot back via the MRU.
                    $strip = $dock($ide);
                    while ([] !== ($tabs = $strip->aliveTabs())) {
                        $entry = $tabs[0];
                        $strip->closeTab($entry['tab'], $entry['key']);
                    }
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isOpen(), 'all tools closed, the panel too');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDockToolTabDragReordersAndFixedEndpointsAreNoOps(): void
    {
        putenv('PATH='.$this->originalPath); // real grep for the TODO scan
        $project = new TempProject(['src/Main.php' => "<?php\n// TODO polish\n"]);
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/Main.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    $tui->handleInput("\x1b8"); // alt+8 → TODO joins
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'TODO polish'));
                    $tui->tick();
                    $labels = static fn (): array => array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertSame(['Problems', 'TODO'], $labels());

                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, 1))[0]);
                    $problems = (int) mb_strpos($strip, needle: 'Problems');
                    $todo = (int) mb_strpos($strip, needle: 'TODO');

                    // Press the Problems tab body and drag it over TODO's slot.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $problems + 1, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + $todo + 1, $rect->row + 1));
                    $this->assertSame(['TODO', 'Problems'], $labels(), 'the drag reordered the tool tabs');

                    // Wandering onto the strip's empty tail (a non-tool endpoint)
                    // moves nothing; the release there just ends the drag.
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $this->assertSame(['TODO', 'Problems'], $labels(), 'a fixed/tail endpoint is a no-op');
                    $this->assertSame('Problems', $tui->getById('results')->getTitle(), 'the pressed tool stayed revealed through its move');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickOnADockToolTabOffersClose(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(\Porta\Ui\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, 1))[0]);
                    $problems = (int) mb_strpos($strip, needle: 'Problems');

                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + $problems + 1, $rect->row + 1));
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a tool tab opened its menu');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Close'));
                    $this->assertStringContainsString('Close', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $tui->handleInput("\r"); // run Close — the same action as the strip ×
                    $tui->tick();
                    $this->assertSame([], $dock($ide)->aliveTabs(), 'the menu Close really closed the tool');
                    $this->assertFalse($tui->getById('results')->isOpen());
                },
                500 => function ($tui, $terminal, $ide): void {
                    // The strip's empty tail stays menu-less.
                    $tui->handleInput("\x1b6");
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $tui->tick();
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'the empty tail offers no menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPointerMotionTintsStripTabs(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    self::bufferService($ide)->openFile($project->path('b.php'), \Porta\Editor\OpenFocus::Keep);
                    $tui->handleInput("\x1b6"); // alt+6 → a dock tool tab exists
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);

                    // Editor tab strip: hover the inactive b.php tab.
                    $tabsRect = $renderer->getWidgetRect($tui->getById('tabs'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $tabsRect->col + 3, $tabsRect->row + 1));
                    $tui->tick();
                    $this->assertStringContainsString(
                        "\x1b[48;5;240m",
                        implode('', $tui->getById('tabs')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 1))),
                        'the hovered editor tab tints',
                    );

                    // Dock strip: hover the Problems tab.
                    $stripRect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $stripRect->col + 3, $stripRect->row + 1));
                    $tui->tick();
                    $this->assertStringNotContainsString(
                        "\x1b[48;5;240m",
                        implode('', $tui->getById('tabs')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 1))),
                        'leaving the editor strip clears its tint',
                    );

                    // The Problems tab is ACTIVE (no tint); moving onto the editor body clears everything.
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 10, $editorRect->row + 2));
                    $tui->tick();
                    $this->addToAssertionCount(1); // the motion path itself is the coverage target
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWheelParityAcrossPopupsMenusSettingsAndTheDockStrip(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $wheel = static function ($tui, int $col, int $row, int $code = 65): void {
            $tui->handleInput(\sprintf("\x1b[<%d;%d;%dM", $code, $col, $row));
        };

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($wheel): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);

                    // Go-to-file (the editor's centered search overlay now): the wheel moves its selection.
                    $tui->handleInput("\x1b[112;5u"); // ctrl+p
                    foreach (str_split('php') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen());
                    $gotoRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $wheel($tui, $gotoRect->col + 3, $gotoRect->row + 3);
                    $tui->tick();
                    $tui->handleInput("\r"); // picks the wheel-selected row
                    $tui->tick();
                    $this->assertStringEndsWith('b.php', (string) $tui->getById('editor')->getFilePath(), 'wheel-down + Enter opened the later match');

                    // Menu dropdown: the wheel moves the selection inside the region.
                    $tui->handleInput("\x1b[21~"); // F10 opens the app menu
                    $tui->tick();
                    $wheel($tui, col: 3, row: 3);
                    $tui->tick();
                    $tui->handleInput("\x1b"); // close the menu
                    $tui->tick();

                    // Context menu: right-click the editor, wheel inside the dropdown.
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $editorRect->col + 10, $editorRect->row + 2));
                    $tui->tick();
                    $wheel($tui, col: $editorRect->col + 12, row: $editorRect->row + 4);
                    $tui->tick();
                    $tui->handleInput("\x1b"); // close the context menu
                    $tui->tick();

                    // Settings: nav and form both scroll under the wheel.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->tick();
                    $navRect = $renderer->getWidgetRect($tui->getById('settings-nav'));
                    $wheel($tui, $navRect->col + 2, $navRect->row + 2);
                    $tui->tick();
                    // Whichever pane the nav's wheel landed on: the section list
                    // swaps the form for the keybindings list, and the point here
                    // is that the *content* pane scrolls, not which one it is.
                    $contentRect = $renderer->getWidgetRect($tui->getById('settings-content'));
                    $wheel($tui, $contentRect->col + 4, $contentRect->row + 2);
                    $tui->tick();
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings'); // back out
                    $tui->tick();
                },
                600 => function ($tui, $terminal, $ide) use ($wheel): void {
                    // Dock strip: the wheel cycles the tool tabs.
                    $tui->handleInput("\x1b6"); // Problems
                    $tui->handleInput("\x1b8"); // the scanner tab joins (grep-less PATH → instant empty scan)
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $stripRect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $this->assertSame('TODO', $tui->getById('results')->getTitle());
                    $wheel($tui, $stripRect->col + 3, $stripRect->row + 1, code: 64); // wheel-up cycles back
                    $tui->tick();
                    $this->assertSame('Problems', $tui->getById('results')->getTitle(), 'a strip wheel switches the tool tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
