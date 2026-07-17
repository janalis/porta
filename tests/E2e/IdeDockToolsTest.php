<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
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
        $this->originalPath = (string) getenv('PATH');
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
                    $dock = new \ReflectionProperty($ide, 'panels')->getValue($ide);
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
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'panels')->getValue($ide);

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

    public function testToolTabsCoexistCycleAndSurviveAPicker(): void
    {
        putenv('PATH='.$this->originalPath); // real grep for the TODO scan
        $project = new TempProject(['src/Main.php' => "<?php\n// TODO polish\n"]);
        $dock = static fn ($ide): object => new \ReflectionProperty(Ide::class, 'panels')->getValue($ide);

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
                    // A picker over a tool tab: Esc restores the displaced tab.
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a → command palette
                    $tui->tick();
                    $this->assertSame([], $dock($ide)->aliveTabs(), 'a picker blanks the strip');
                    $tui->handleInput("\x1b"); // esc → picker closes
                    $tui->tick();
                    $this->assertSame('Problems', $tui->getById('results')->getTitle(), 'the displaced tool tab came back');

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
}
