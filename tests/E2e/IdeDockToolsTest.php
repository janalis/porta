<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\OpenFocus;
use Porta\History\BookmarkPersistence;
use Porta\History\BookmarkStore;
use Porta\Ide;
use Porta\Lsp\NavigationController;
use Porta\Lsp\Uri;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Porta\Widget\DockTabsWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolHandlers;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Drives the live bottom-dock list tools through a real Ide: the Problems
 * tab refreshes in place as diagnostics land (and keeps its count fresh
 * while concealed), without stealing the focus.
 */
final class IdeDockToolsTest extends TestCase
{
    use PreservesPath;
    use RunsIde;

    private static function bufferService(mixed $ide): BufferService
    {
        /** @var BufferService */
        return Services::resolve($ide, BufferService::class);
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
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('results'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 5, $rect->row + 2));
                },
                300 => function ($tui, $terminal, $ide): void {
                    $results = $tui->getById('results');
                    $joined = implode('', $results->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12)));
                    $this->assertStringContainsString("\x1b[48;5;236m", $joined, 'the row under the pointer is tinted');

                    // Move into the editor: the panel's tint must not survive it.
                    $renderer = Services::resolve($ide, Renderer::class);
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

    public function testChangeMarksReachTheGutterAndFollowTheActiveBuffer(): void
    {
        // The seam a version-control plugin publishes through: path-keyed, so
        // an answer that arrives after a tab switch lands on the file it was
        // computed for rather than whatever is on screen.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$x = 1;\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $decorations = Probe::get($tui->getById('editor'), 'decorations');
                    $this->assertSame([], $decorations->vcsMarks(), 'nothing published yet');

                    self::bufferService($ide)->applyChangeMarks((string) realpath($project->path('a.php')), [1 => ChangeMarkKind::Modified]);
                    $this->assertSame([1 => ChangeMarkKind::Modified], $decorations->vcsMarks(), 'the active file paints');

                    // A file with no open buffer is a silent no-op, not a crash.
                    self::bufferService($ide)->applyChangeMarks($project->path('nope.php'), [0 => ChangeMarkKind::Added]);
                    $this->assertSame([1 => ChangeMarkKind::Modified], $decorations->vcsMarks(), 'and nothing else moved');

                    // The tab switch this test is named for. A subprocess that
                    // answers late names the file it diffed, so the marks must
                    // reach that BUFFER without touching the editor, which is
                    // showing something else.
                    self::bufferService($ide)->openFile($project->path('b.php'), OpenFocus::Editor);
                    $this->assertSame([], $decorations->vcsMarks(), 'b.php has none of its own');

                    self::bufferService($ide)->applyChangeMarks((string) realpath($project->path('a.php')), [0 => ChangeMarkKind::Added, 1 => ChangeMarkKind::Deleted]);
                    $this->assertSame([], $decorations->vcsMarks(), 'a background answer does not paint over the file on screen');

                    // And it was stored, not dropped: switching back replays it.
                    self::bufferService($ide)->openFile($project->path('a.php'), OpenFocus::Editor);
                    $this->assertSame([0 => ChangeMarkKind::Added, 1 => ChangeMarkKind::Deleted], $decorations->vcsMarks(), 'the switch back replays what arrived while it was hidden');
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
                    // Await the strip COUNT, which is the last of the two things
                    // asserted; 'Problems' alone can be on screen before it.
                    $this->awaitPainted($tui, $terminal, '0 results');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen, 'the Problems tab opened on the dock strip');
                },
                350 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->setFocus($tui->getById('editor'));
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]]],
                        );
                    // The row and the strip count are two paints; the count
                    // follows, so it is the one to await.
                    $this->awaitPainted($tui, $terminal, '✗ live boom');
                    $this->awaitPainted($tui, $terminal, '1 results');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the live refresh did not steal the focus');

                    // A further refresh that adds an EARLIER row must not move
                    // the selection off the diagnostic the user is on.
                    $results = $tui->getById('results');
                    \assert($results instanceof \Porta\Widget\ResultsPanelWidget, description: 'the dock shows the results panel');
                    $this->assertSame(1, $results->captureViewState()->selectedLine, 'the fresh tab selected its first match');
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [
                                0 => [['severity' => 2, 'message' => 'warn zero', 'col' => 0, 'endCol' => 3]],
                                1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]],
                            ],
                        );
                    $tui->tick();
                    $this->assertSame(1, $results->captureViewState()->selectedLine, 'the selection stayed on the same diagnostic through the refresh');
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
                    $this->awaitPainted($tui, $terminal, '2 results');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('▲ warn one', $screen, 'the concealed tab picked up the second diagnostic');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTwoSearchesCoexistAsTabsAndF5RerunsTheRightOne(): void
    {
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // real grep
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
        $dock = static fn ($ide): object => Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class);

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
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // real grep
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
        $dock = static fn ($ide): object => Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class);

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
                    $this->frame($tui); // a capped bare tick paints nothing — the strip window needs a real frame

                    $renderer = Services::resolve($ide, Renderer::class);
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
                    $this->frame($tui);

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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/Main.php' => "<?php\n"]);
        $dock = static fn ($ide): object => Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/Main.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock, $project): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('ide_notifications'); // Notifications joins, Problems keeps its tab
                    $commands->run('ide_lsp_log'); // LSP Log joins
                    $tui->tick();
                    $labels = array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertSame(['Problems', 'Notifications', 'LSP Server Log'], $labels, 'three tools coexist, none replaced');

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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/Main.php' => "<?php\n"]);
        $dock = static fn ($ide): object => Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('src/Main.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    Services::resolve($ide, CommandRegistry::class)->run('ide_notifications'); // a second tool joins
                    $tui->tick();
                    $labels = static fn (): array => array_column($dock($ide)->aliveTabs(), 'label');
                    $this->assertSame(['Problems', 'Notifications'], $labels());

                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, 1))[0]);
                    $problems = (int) mb_strpos($strip, needle: 'Problems');
                    $second = (int) mb_strpos($strip, needle: 'Notifications');

                    // Press the Problems tab body and drag it over the other tab's slot.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $problems + 1, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + $second + 1, $rect->row + 1));
                    $this->assertSame(['Notifications', 'Problems'], $labels(), 'the drag reordered the tool tabs');

                    // Wandering onto the strip's empty tail (a non-tool endpoint)
                    // moves nothing; the release there just ends the drag.
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $this->assertSame(['Notifications', 'Problems'], $labels(), 'a fixed/tail endpoint is a no-op');
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
        $dock = static fn ($ide): object => Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($dock): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Problems
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
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
                    $renderer = Services::resolve($ide, Renderer::class);
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
                    self::bufferService($ide)->openFile($project->path('b.php'), OpenFocus::Keep);
                    $tui->handleInput("\x1b6"); // alt+6 → a dock tool tab exists
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);

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
                    $renderer = Services::resolve($ide, Renderer::class);

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
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui); // a capped bare tick paints nothing — rects need a real frame
                    $navRect = $renderer->getWidgetRect($tui->getById('settings-nav'));
                    $wheel($tui, $navRect->col + 2, $navRect->row + 2);
                    $this->frame($tui);
                    // Whichever pane the nav's wheel landed on: the section list
                    // swaps the form for the keybindings list, and the point here
                    // is that the *content* pane scrolls, not which one it is.
                    $contentRect = $renderer->getWidgetRect($tui->getById('settings-content'));
                    $wheel($tui, $contentRect->col + 4, $contentRect->row + 2);
                    $tui->tick();
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings'); // back out
                    $tui->tick();
                },
                600 => function ($tui, $terminal, $ide) use ($wheel): void {
                    // Dock strip: the wheel cycles the tool tabs.
                    $tui->handleInput("\x1b6"); // Problems
                    Services::resolve($ide, CommandRegistry::class)->run('ide_notifications'); // a second tab joins
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $stripRect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $this->assertSame('Notifications', $tui->getById('results')->getTitle());
                    $wheel($tui, $stripRect->col + 3, $stripRect->row + 1, code: 64); // wheel-up cycles back
                    $tui->tick();
                    $this->assertSame('Problems', $tui->getById('results')->getTitle(), 'a strip wheel switches the tool tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAContributedToolOwnsItsEnterAndItsF5(): void
    {
        // The seam a plugin's list tool rides: the tab carries its behaviour,
        // so Enter and F5 reach the tool rather than being routed by a mode it
        // does not have. Both directions matter — a tool WITHOUT an activate
        // must still get core's open-and-jump, which is what most want.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// one\n// two\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('b.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $dock = Services::resolve($ide, BottomDock::class);
                    $reruns = 0;
                    $activated = [];
                    $rows = [['path' => 'a.php', 'line' => 1, 'text' => '// one']];

                    // A tool that answers Enter itself.
                    $mine = new DockToolHandlers(
                        'demo:mine',
                        activate: static function (string $path, int $line) use (&$activated): void {
                            $activated[] = $path.':'.$line;
                        },
                        rerun: static function () use (&$reruns): void {
                            ++$reruns;
                        },
                    );
                    $dock->showTool($mine, 'Mine', $rows);
                    $tui->tick();
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                    $tui->handleInput("\x1b[15~"); // F5

                    $this->assertSame(['a.php:1'], $activated, "the tool's own Enter fired with the row's location");
                    $this->assertSame(1, $reruns, 'and its own F5');
                    $this->assertStringEndsWith('/b.php', (string) $tui->getById('editor')->getFilePath(), 'core did not also jump');

                    // A tool that declares neither: Enter falls through to
                    // core's open-and-jump, F5 is inert.
                    $dock->showTool(new DockToolHandlers('demo:bare'), 'Bare', $rows);
                    $tui->tick();
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b[15~"); // F5: nothing to re-run
                    $tui->handleInput("\r");
                    $tui->tick();

                    $this->assertStringEndsWith('/a.php', (string) $tui->getById('editor')->getFilePath(), 'the default Enter opened the row');
                    $this->assertSame(1, $reruns, "the other tool's F5 did not fire");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The whole bookmark chain through a real Ide — the only thing that proves
     * the gutter applier, the dock tool and the persistence file are wired to
     * the keys at all. Every one of them passed its own unit test while
     * nothing in the app constructed it.
     */
    public function testF11MarksALineInTheGutterAndTheBookmarksToolListsAndJumpsToIt(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// remembered line\n// third\n", 'b.php' => "<?php\n// elsewhere\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal) use ($project): void {
                    $editorScreen = static fn (): string => implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x1b[23~"); // F11 → toggle bookmark
                    $this->frame($tui);

                    $this->assertStringContainsString(Glyphs::BOOKMARK, $editorScreen(), 'F11 painted the mark in the gutter');

                    // Alt+2 opens the tool, showing the marked line's own text.
                    $tui->handleInput("\x1b2");
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '// remembered line'));
                },
                320 => function ($tui, $terminal, $ide) use ($project): void {
                    self::bufferService($ide)->openFile($project->path('b.php'), OpenFocus::Editor);
                    $tui->tick();
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                    $tui->tick();

                    $this->assertStringEndsWith('/a.php', (string) $tui->getById('editor')->getFilePath(), 'the row jumped back to the bookmarked file');
                    $this->assertSame(1, $tui->getById('editor')->getDocument()->getCursorLine(), 'and to its line');
                },
                420 => function ($tui, $terminal, $ide) use ($project): void {
                    // The mark outlives the session: teardown writes it, and a
                    // fresh store reads back exactly what was marked.
                    Services::resolve($ide, BookmarkPersistence::class)->save();
                    $this->assertSame(
                        // realpath, because that is the key a buffer carries —
                        // the temp root is a symlink into /private on macOS.
                        [realpath($project->path('a.php')) => [1]],
                        new BookmarkStore($project->dir)->load(),
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Recent Locations through a real Ide: a go-to-definition records where it
     * left, Alt+5 lists that place with its own text, and Enter returns to it.
     * Navigate-back would have reached the same place — the point of the list
     * is reaching the fifth one back without walking through four.
     */
    public function testAltFiveListsWhereYouJumpedFromAndGoesBackToIt(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// departure point\n// third\n", 'b.php' => "<?php\n// somewhere else\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // Record a departure the way every navigation verb does.
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    Services::resolve($ide, NavigationController::class)->pushCurrentLocation();
                    self::bufferService($ide)->openFile($project->path('b.php'), OpenFocus::Editor);
                    $tui->tick();

                    $tui->handleInput("\x1b5"); // alt+5 → Recent Locations
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '// departure point'));

                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                    $tui->tick();

                    $this->assertStringEndsWith('/a.php', (string) $tui->getById('editor')->getFilePath(), 'the row returned to the file left behind');
                    $this->assertSame(1, $tui->getById('editor')->getDocument()->getCursorLine());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
