<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Support\RevoltScheduler;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Ui\Glyphs;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Editing chrome beyond keystrokes: multi-caret flows, structural
 * selection, sticky scroll, breadcrumbs, toasts.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeEditingTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    /**
     * The fold column is part of the gutter, so a click there must fold rather
     * than set a breakpoint — the two live one cell apart.
     */
    public function testClickingTheFoldColumnCollapsesTheRegionRatherThanSettingABreakpoint(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\nfunction a()\n{\n    return 1;\n}\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));

                    $this->assertStringContainsString(Glyphs::EXPANDED, $screen(), 'the brace line starts an open region');

                    // The fold column is the gutter's second-to-last cell; SGR
                    // columns are 1-based. Editor row 2 is document line 2.
                    $foldCol = $rect->col + $editor->gutterWidth() - 1;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $foldCol, $rect->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $foldCol, $rect->row + 3));

                    $after = $screen();
                    $this->assertStringContainsString(Glyphs::COLLAPSED, $after, 'the click collapsed the region');
                    $this->assertStringNotContainsString(Glyphs::BREAKPOINT, $after, 'and set no breakpoint');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testStickyScrollPinsHeaderAndClickJumps(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => static function ($tui): void {
                    for ($i = 0; $i < 30; ++$i) {
                        $tui->handleInput("\x1b[B"); // scroll deep into the method
                    }
                },
                280 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($editor);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    $this->assertStringContainsString('class Service', AnsiUtils::stripAnsiCodes($rendered[0]), 'class header pinned at the top');
                    // Click the pinned class header's TEXT (editor row 0, first
                    // content column — the gutter's own columns route to the
                    // breakpoint and fold actions instead).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $rect->row + 1));
                },
                360 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the pinned header jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbsShowScopePathAndJumpOnClick(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    // Caret at the very top is outside every scope → no bar.
                    $this->assertSame([], $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)));
                    // Navigate into handle() with the keyboard (invalidates the editor → relayout).
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to line 8 (the `$x` line)
                    }
                },
                280 => function ($tui): void {
                    $plain = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('App › Service › handle', $plain, 'the scope path is shown');
                },
                340 => static function ($tui, $terminal, $ide): void {
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('breadcrumbs'));
                    // ' App'(1..3) › 'Service'(7..13) › 'handle'. Click 'Service' (col 8) →
                    // jump to the class declaration (0-based line 4).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8 + 1, $rect->row + 1));
                },
                420 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the Service crumb jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAVisibleToastDoesNotSelfSustainFrames(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    // A long expiry: the toast dismissing mid-step is a REAL
                    // repaint and would fail the quiescence assert under load.
                    Services::resolve($ide, Notifier::class)->flash('quiesce check', seconds: 60.0);
                    $tui->tick(); // paint the toast
                    $tui->tick(); // settle: the hook re-delivers identical spans
                    $this->assertSame('quiesce check', Toasts::last($ide), 'the toast is up');

                    Probe::set($tui, 'renderRequested', false);
                    $tui->tick(); // hooks run — identical spans must not re-invalidate
                    $tui->tick(); // a revision bump would surface as a request here
                    $this->assertFalse(
                        (bool) Probe::get($tui, 'renderRequested'),
                        'a visible toast must not keep requesting frames (the perf.log in=0 passes=9 loop)',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbTrailSettlesAfterTypingPauses(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    // Rename handle → handleX by typing at the end of the name.
                    // The rescan debounces, so the settled trail is awaited on
                    // the live loop — not asserted on the keystroke's frame.
                    $editor = $tui->getById('editor');
                    // The debounce only exists when the container actually
                    // injected the scheduler — a silently-null optional would
                    // put production back on the per-keystroke tokenizer path.
                    $this->assertInstanceOf(RevoltScheduler::class, Probe::get($editor, 'scheduler'), 'the container wired the rescan scheduler');
                    $editor->getDocument()->setCursor(6, 26);
                    $tui->handleInput('X');
                    $this->await(
                        static function () use ($tui): bool {
                            $rows = $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1));

                            return [] !== $rows && str_contains(AnsiUtils::stripAnsiCodes($rows[0]), 'handleX');
                        },
                        what: 'the breadcrumb trail to settle after the edit',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFoldAllAndUnfoldAllAreReachableFromTheKEYBOARD(): void
    {
        // They were palette-only commands with inline labels, which is why this
        // asserts the chords rather than the handlers: an action nothing can
        // press is the failure mode a new binding invites.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\nclass A\n{\n    public function one(): void\n    {\n        \$x = 1;\n    }\n}\n"]);

        // The terminal's output ACCUMULATES, so every read here clears it
        // first and repaints. Reading it without clearing finds the opening
        // frame and reports that nothing ever folded.
        $screen = function ($tui, $terminal): string {
            $terminal->clearOutput();
            $tui->requestRender(force: true);
            $this->frame($tui);

            return AnsiUtils::stripAnsiCodes($terminal->getOutput());
        };

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal) use ($screen): void {
                    $this->assertStringContainsString('$x = 1;', $screen($tui, $terminal), 'the body starts visible');

                    $tui->handleInput("\x1b[45;6u"); // ctrl+shift+-
                    $folded = $screen($tui, $terminal);
                    $this->assertStringContainsString(Glyphs::COLLAPSED, $folded);
                    $this->assertStringNotContainsString('$x = 1;', $folded, 'the body is folded away');

                    $tui->handleInput("\x1b[43;6u"); // ctrl+shift++ — the spelling that CANNOT bind
                    $this->assertStringNotContainsString('$x = 1;', $screen($tui, $terminal), 'still folded');

                    $tui->handleInput("\x1b[61;6u"); // ctrl+shift+= — the one that does
                    $this->assertStringContainsString('$x = 1;', $screen($tui, $terminal), 'unfolded again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testLastEditLocationTakesYouBackToWhatYouWereWriting(): void
    {
        // Through the running IDE, because the point is the wiring: a
        // Document's mutation has to reach a service that knows which FILE it
        // was in, and the chord has to reach that service back.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n".str_repeat("// a\n", times: 40),
            'B.php' => "<?php\n// b\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(30, 0);
                    $tui->handleInput('X'); // an edit, deep in A.php
                },
                400 => function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('B.php'), OpenFocus::Editor);
                    $tui->requestRender();
                    $tui->tick();
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath());

                    // Ctrl+Shift+Backspace: back to A.php, on the edited line.
                    // Navigate-back could not do this — opening a file is not a
                    // jump, so nothing was pushed onto that stack.
                    $tui->handleInput("\x1b[127;6u");
                },
                550 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath());
                    $this->assertSame(30, $editor->getDocument()->getCursorLine());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCursorThroughRunningIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B"); // ctrl+alt+down
                    $tui->handleInput("\x1b[1;7B");
                    $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());
                    $tui->handleInput('#'); // type at all three carets
                    $this->assertSame("#one\n#two\n#three\n", $editor->getDocument()->getText());
                    $tui->handleInput("\x1a"); // ctrl+z: one undo reverts all
                    $this->assertSame("one\ntwo\nthree\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCaretSurvivesStrayInputAndShiftSelects(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbbbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B");
                    $tui->handleInput("\x1b[1;7B"); // carets on lines 0,1,2
                    // A stray focus report between keystrokes must not collapse them.
                    $tui->handleInput('X');
                    $tui->handleInput("\x1b[I");
                    $tui->handleInput('Y');
                    $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple(), 'stray input kept the carets');
                    $this->assertSame("XYaaaa\nXYbbbb\nXYcccc\n", $editor->getDocument()->getText());
                    // shift+right selects one char at every caret; typing replaces each.
                    $tui->handleInput("\x1b[1;2C");
                    $tui->handleInput('Z');
                    $this->assertSame("XYZaaa\nXYZbbb\nXYZccc\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExpandSelectionThenSurroundThroughFullIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$x = foo(bar);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 10); // inside "bar"
                    $tui->handleInput("\x17"); // ctrl+w: expand to the word "bar"
                    $tui->handleInput("\x1b\x14"); // ctrl+alt+t: open the surround menu
                    $tui->handleInput("\x1b[B"); // down to "[ ] Brackets"
                    $tui->handleInput("\r"); // accept -> wrap "bar" in [ ]
                },
                // The assertion below the run reads ACCUMULATED output, so the
                // last step has to leave the effect painted — the loop stops
                // 80 ms later and a tick is not a frame.
                600 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'foo([bar])');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('foo([bar])', $screen);
    }

    public function testNotificationLogShowsExpiredFlashesInTheDock(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    // The 0.1 s flash will expire; the log is the only way back to it.
                    Services::resolve($ide, Notifier::class)
                        ->flash('the answer is 42', 0.1);
                },
                400 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_notifications');
                    // The log ROW is the last of the two things asserted: the
                    // tab title can be on screen before the entries render.
                    $this->awaitPainted($tui, $terminal, 'the answer is 42');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Notifications', $screen, 'the dock tab opened');

                    // A flash landing while the tab is open appears live, newest first.
                    Services::resolve($ide, Notifier::class)
                        ->flash('a later event', 0.1);
                    $tui->tick();
                    $panelText = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12))));
                    $this->assertLessThan(
                        strpos($panelText, needle: 'the answer is 42'),
                        strpos($panelText, needle: 'a later event'),
                        'the new flash appended live, newest first',
                    );
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r"); // Enter on a log row is inert
                    $tui->tick();
                    $this->assertTrue($tui->getById('results')->isVisible(), 'the tab stays put on Enter');
                    $tui->handleInput("\x1b"); // Esc hides the dock
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isVisible());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
