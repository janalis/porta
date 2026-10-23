<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\CloseMode;
use Porta\Editor\OpenFocus;
use Porta\Editor\SelectionMode;
use Porta\Ide;
use Porta\Support\RevoltScheduler;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Glyphs;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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
    use ScreenReads;

    /**
     * The fold column is part of the gutter, so a click there must fold rather
     * than set a breakpoint — the two live one cell apart.
     */
    public function testClickingTheFoldColumnCollapsesTheRegionRatherThanSettingABreakpoint(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\nfunction a()\n{\n    return 1;\n}\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $editor = Widgets::editor($tui);
                    $rect = Widgets::rectIn($ide, $editor);
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes(\implode("\n", Paint::lines($editor, new RenderContext(120, 20))));

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
        \putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".\str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n";
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
                280 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = Widgets::editor($tui);
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = Widgets::rect($renderer, $editor);
                    $rendered = Paint::lines($editor, new RenderContext($rect->columns, $rect->rows));
                    $this->assertStringContainsString('class Service', AnsiUtils::stripAnsiCodes($rendered[0]), 'class header pinned at the top');
                    // Click the pinned class header's TEXT (editor row 0, first
                    // content column — the gutter's own columns route to the
                    // breakpoint and fold actions instead).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $rect->row + 1));
                },
                360 => function (Tui $tui): void {
                    $this->assertSame(4, Widgets::editor($tui)->getDocument()->cursorLine, 'clicking the pinned header jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbsShowScopePathAndJumpOnClick(): void
    {
        \putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['src/Service.php' => $code]);
        $column = static function ($tui, string $label): int {
            $plain = AnsiUtils::stripAnsiCodes(Paint::lines($tui->getById('breadcrumbs'), new RenderContext(120, 1))[0]);

            return (int) \mb_strpos($plain, ' '.$label.' ', encoding: 'UTF-8') + 1;
        };

        $this->runIde(
            $project,
            $project->path('src/Service.php'),
            [
                200 => function ($tui): void {
                    // Caret at the very top is outside every scope: the file is where you are.
                    $plain = AnsiUtils::stripAnsiCodes(Paint::lines($tui->getById('breadcrumbs'), new RenderContext(120, 1))[0]);
                    $this->assertSame(' src › Service.php ', \rtrim($plain).' ');
                    // Navigate into handle() with the keyboard (invalidates the editor → relayout).
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to line 8 (the `$x` line)
                    }
                },
                280 => function ($tui): void {
                    $plain = AnsiUtils::stripAnsiCodes(Paint::lines($tui->getById('breadcrumbs'), new RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('src › Service.php › App › Service › handle', $plain, 'the path, then the scope path');
                },
                340 => static function ($tui, $terminal, Ide $ide) use ($column): void {
                    $tui->requestRender(force: true);
                    $tui->processRender();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = Widgets::rect($renderer, $tui->getById('breadcrumbs'));
                    // Click 'Service' → jump to the class declaration (0-based line 4).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $column($tui, label: 'Service') + 1, $rect->row + 1));
                },
                420 => function (Tui $tui, $terminal, Ide $ide) use ($column, $project): void {
                    $this->assertSame(4, Widgets::editor($tui)->getDocument()->cursorLine, 'clicking the Service crumb jumped to the class declaration');
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = Widgets::rect($renderer, $tui->getById('breadcrumbs'));
                    // The bar's empty tail is no crumb: the press falls through.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $rect->columns - 2, $rect->row + 1));
                    $this->assertSame(4, Widgets::editor($tui)->getDocument()->cursorLine, 'past the trail, nothing moves');
                    // Click 'src' → the folder is revealed and selected in the tree.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $column($tui, label: 'src') + 1, $rect->row + 1));
                    $this->assertSame($project->path('src'), Widgets::byId($tui, 'tree', FileTreeWidget::class)->selectedNode()?->path, 'a folder crumb reveals its folder');
                    $this->assertSame(4, Widgets::editor($tui)->getDocument()->cursorLine, 'and moves no caret');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAVisibleToastDoesNotSelfSustainFrames(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (object $tui, $terminal, Ide $ide): void {
                    // A long expiry: the toast dismissing mid-step is a REAL
                    // repaint and would fail the quiescence assert under load.
                    Services::resolve($ide, Notifier::class)->flash('quiesce check', seconds: 60.0);
                    $this->frame($tui); // paint the toast (a tick inside the frame cap paints nothing)
                    $this->frame($tui); // settle: the hook re-delivers identical spans
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
        \putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function (Tui $tui): void {
                    // Rename handle → handleX by typing at the end of the name.
                    // The rescan debounces, so the settled trail is awaited on
                    // the live loop — not asserted on the keystroke's frame.
                    $editor = Widgets::editor($tui);
                    // The debounce only exists when the container actually
                    // injected the scheduler — a silently-null optional would
                    // put production back on the per-keystroke tokenizer path.
                    $this->assertInstanceOf(RevoltScheduler::class, Probe::get($editor, 'scheduler'), 'the container wired the rescan scheduler');
                    $editor->getDocument()->setCursor(6, 26);
                    $tui->handleInput('X');
                    $this->await(
                        static function () use ($tui): bool {
                            $rows = Paint::lines($tui->getById('breadcrumbs'), new RenderContext(120, 1));

                            return [] !== $rows && \str_contains(AnsiUtils::stripAnsiCodes($rows[0]), 'handleX');
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
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\nclass A\n{\n    public function one(): void\n    {\n        \$x = 1;\n    }\n}\n"]);

        // The terminal's output ACCUMULATES, so every read here clears it
        // first and repaints. Reading it without clearing finds the opening
        // frame and reports that nothing ever folded.
        $screen = function (Tui $tui, $terminal): string {
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

    /**
     * The clipboard history through the running app, because the picker is
     * composited by the OVERLAY HOST and not by the editor widget: every other
     * test of it reads state (`pickerOpen`, then Down and Enter) and would pass
     * on a picker that draws nothing at all. This is the one that looks.
     *
     * It also reads the ROW SHAPE off the screen. Newlines render as `⏎`
     * rather than the entry being cut to its first line, because what tells two
     * copies apart is usually their shape — and that argument is only worth
     * anything if the marker actually reaches the terminal.
     */
    public function testTheClipboardHistoryPickerDRAWSEveryCopyWithItsShape(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$alpha = 1;\n\$beta = 2;\n\$gamma = 3;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $document = Widgets::editor($tui)->getDocument();
                    // One single-line copy and one spanning two lines, so the
                    // ⏎ has something to mark and the rows differ in shape.
                    foreach ([[1, 1], [2, 3]] as [$from, $to]) {
                        $document->setCursor($from, 0);
                        $document->beginSelect();
                        $document->setCursor($to, \strlen($document->getLine($to)), SelectionMode::Keep);
                        $tui->handleInput("\x03"); // ctrl+c
                    }

                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[118;6u"); // ctrl+shift+v
                    $this->awaitPainted($tui, $terminal, 'Paste from History');

                    // The CARD, not the screen: every string in this picker is
                    // also in the buffer behind it, so a screen-wide assertion
                    // passes on a picker that offers nothing at all — measured,
                    // by cutting the older entry and watching it still pass.
                    $card = $this->card($this->screenGrid($tui, $terminal), 'Paste from History');
                    $this->assertStringContainsString('$beta = 2;⏎$gamma = 3;', $card, 'the two-line copy keeps its shape on one row');
                    $this->assertStringContainsString('$alpha = 1;', $card, 'and the older copy is still offered');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCopyPathAndCopyReferenceReachTheCLIPBOARDFromTheirChords(): void
    {
        // Both halves through the running IDE. The chords, because a binding
        // can match its descriptor and still never fire — and the OSC 52 the
        // terminal receives, because every other test of these two reads the
        // flash, which a copy that never happened would still produce.
        \putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App\\Http;\n\nclass Svc\n{\n    public function run(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['src/Svc.php' => $code]);

        $this->runIde(
            $project,
            $project->path('src/Svc.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    Widgets::editor($tui)->getDocument()->setCursor(8, 8); // inside run()
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[99;8u"); // ctrl+alt+shift+c

                    $this->awaitPaintedRaw($tui, $terminal, "\x1b]52;c;".\base64_encode('App\\Http\\Svc::run'));
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[99;6u"); // ctrl+shift+c

                    $this->awaitPaintedRaw($tui, $terminal, "\x1b]52;c;".\base64_encode('src/Svc.php'));
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
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n".\str_repeat("// a\n", times: 40),
            'B.php' => "<?php\n// b\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function (Tui $tui): void {
                    Widgets::editor($tui)->getDocument()->setCursor(30, 0);
                    $tui->handleInput('X'); // an edit, deep in A.php
                },
                400 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('B.php'), OpenFocus::Editor);
                    $tui->requestRender(force: true);
                    $tui->processRender();
                    $this->assertStringEndsWith('B.php', (string) Widgets::editor($tui)->getFilePath());

                    // Ctrl+Shift+Backspace: back to A.php, on the edited line.
                    // Navigate-back could not do this — opening a file is not a
                    // jump, so nothing was pushed onto that stack.
                    $tui->handleInput("\x1b[127;6u");
                },
                550 => function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath());
                    $this->assertSame(30, $editor->getDocument()->cursorLine);
                    // Now edit B.php and delete it. Walking back to a file that
                    // has gone must not move the caret in the file still on
                    // screen — that line number belongs to another file.
                    $tui->handleInput("\x1b[1;5D");
                    // ctrl+left: away from the edit, no jump recorded
                    $editor->getDocument()->setCursor(2, 0);
                    $tui->handleInput('Z');

                    // an edit in A.php at line 2
                },
                650 => static function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('B.php'), OpenFocus::Editor);
                    $tui->requestRender(force: true);
                    $tui->processRender();
                    Widgets::editor($tui)->getDocument()->setCursor(1, 0);
                    $tui->handleInput('Q'); // an edit in B.php, the newest one
                },
                750 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->closeActiveBuffer(CloseMode::Force);
                    \unlink($project->path('B.php'));
                    $tui->requestRender(force: true);
                    $tui->processRender();

                    $editor = Widgets::editor($tui);
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath());
                    $editor->getDocument()->setCursor(20, 0);

                    $tui->handleInput("\x1b[127;6u"); // walk back into the deleted file
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath(), 'still here');
                    $this->assertSame(20, $editor->getDocument()->cursorLine, 'and the caret did not jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCursorThroughRunningIde(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
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
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbbbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
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
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$x = foo(bar);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function (Tui $tui): void {
                    Widgets::editor($tui)->getDocument()->setCursor(1, 10); // inside "bar"
                    $tui->handleInput("\x17"); // ctrl+w: expand to the word "bar"
                    $tui->handleInput("\x1b\x14"); // ctrl+alt+t: open the surround menu
                    $tui->handleInput("\x1b[B"); // down to "[ ] Brackets"
                    $tui->handleInput("\r"); // accept -> wrap "bar" in [ ]
                },
                // The assertion below the run reads ACCUMULATED output, so the
                // last step has to leave the effect painted — the loop stops
                // 80 ms later and a tick is not a frame.
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'foo([bar])');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('foo([bar])', $screen);
    }

    public function testNotificationLogShowsExpiredFlashesInTheDock(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, Ide $ide): void {
                    // The 0.1 s flash will expire; the log is the only way back to it.
                    Services::resolve($ide, Notifier::class)
                        ->flash('the answer is 42', 0.1);
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
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
                    $panelText = AnsiUtils::stripAnsiCodes(\implode("\n", Paint::lines($tui->getById('results'), new RenderContext(160, 12))));
                    $this->assertLessThan(
                        \strpos($panelText, needle: 'the answer is 42'),
                        \strpos($panelText, needle: 'a later event'),
                        'the new flash appended live, newest first',
                    );
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r"); // Enter on a log row is inert
                    $tui->tick();
                    $this->assertTrue(Widgets::byId($tui, 'results', ResultsPanelWidget::class)->isVisible(), 'the tab stays put on Enter');
                    $tui->handleInput("\x1b"); // Esc hides the dock
                    $tui->tick();
                    $this->assertFalse(Widgets::byId($tui, 'results', ResultsPanelWidget::class)->isVisible());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The clipboard history picker, SEEN.
     *
     * Its behaviour has unit tests — the ring, the promotion, the pick — but
     * the surface itself had never been rendered by anything: it is composited
     * by the app-level overlay host, so neither the widget rig nor a probe
     * driving keys reaches it, and every assertion about it read a data
     * structure. What a person looks at is the entries, and the thing that
     * makes them readable is that a multi-line copy is ONE row with its
     * newlines shown — otherwise the list is a column of identical stumps.
     */
    public function testTheClipboardHistoryPickerShowsEveryCopyOnItsOwnRow(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$alpha = 1;\n\$beta = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function (Tui $tui): void {
                    $document = Widgets::editor($tui)->getDocument();
                    // Two lines first, then one — so the NEWEST entry is the
                    // single-line copy and the multi-line one is below it.
                    $document->setCursor(1, 0);
                    $document->beginSelect();
                    $document->setCursor(2, \strlen($document->getLine(2)), SelectionMode::Keep);
                    $tui->handleInput("\x03"); // ctrl+c
                    $document->setCursor(2, 0);
                    $document->beginSelect();
                    $document->setCursor(2, \strlen($document->getLine(2)), SelectionMode::Keep);
                    $tui->handleInput("\x03");
                    $document->setCursor(0, 5); // caret after `<?php`, where the paste lands
                },
                400 => static function ($tui, $terminal): void {
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[118;6u"); // ctrl+shift+v
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->assertTrue(EditorState::pickerOpen($tui->getById('editor')), 'the history opened');
                    $rows = \explode("\n", $this->card($this->screenGrid($tui, $terminal), 'Paste from History'));
                    $offering = \array_values(\array_filter($rows, static fn (string $row): bool => \str_contains($row, '$beta = 2;')));

                    // Both copies END in the same text, so a card-wide
                    // assertion is satisfied by either one alone — the rows
                    // have to be told apart, which is the whole point of the
                    // surface.
                    $this->assertNotEmpty(\array_filter($offering, static fn (string $row): bool => !\str_contains($row, '⏎')), 'the single-line copy is a row of its own');
                    $this->assertNotEmpty(
                        \array_filter($offering, static fn (string $row): bool => \str_contains($row, '$alpha = 1;⏎$beta = 2;')),
                        'the multi-line copy is ONE row with its newline shown',
                    );

                    // Newest first, so one Down reaches the multi-line entry.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                800 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $document = Widgets::editor($tui)->getDocument();
                    $this->assertSame('<?php$alpha = 1;', $document->getLine(0), 'the multi-line entry pasted its first line at the caret');
                    $this->assertSame('$beta = 2;', $document->getLine(1), 'and its second line below — the single-line entry would have pasted neither');
                    // The SCREEN, not the accumulated output: nothing has been
                    // written to the terminal since the clear above, so a
                    // negative assertion on getOutput() would pass against an
                    // empty string — measured, it does.
                    $this->assertStringNotContainsString(
                        'Paste from History',
                        \implode("\n", $this->screenGrid($tui, $terminal)),
                        'the picker closes behind the pick',
                    );
                },
            ],
        );
    }
}
