<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\DiagnosticSeverity;
use Porta\Ide;
use Porta\Overlay\State\TooltipCenter;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A language server's diagnostics in the running IDE: the squiggle and its
 * card, the status-bar problem, the Problems panel, quick fixes, and the
 * inline hints and message modes. Split from {@see IdeLspFeaturesTest},
 * which keeps navigation, completion, formatting and the server's lifecycle.
 */
final class IdeLspDiagnosticsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testWarningDiagnosticsShowInStatusBar(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$x = 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    Widgets::editor($tui)
                        ->decorations->setDiagnostics([
                        1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'unused variable', 'col' => 0, 'endCol' => 2]],
                    ]);
                    $tui->requestRender(); // what Ide::handleDiagnostics does after mutating
                    // requestRender only ASKS; the post-run assertion below needs
                    // the frame to have happened, and a tick is not one.
                    $this->awaitPainted($tui, $terminal, '▲ 1');
                },
            ],
        );

        $this->assertStringContainsString('▲ 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testInlayHintsRenderThroughIde(): void
    {
        // The mock emits inlay hints only for *Inlay* files.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Inlay.php' => "<?php\n\$x = f();\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inlay.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // Inlay hints are an LSP round trip, so this was a bet on the
                    // response AND on the frame that draws it.
                    $this->awaitPainted($tui, $terminal, ': int');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString(': int', $screen, 'inlay type hint rendered inline');
                },
            ],
        );
    }

    /**
     * PhpStorm's problem card: rest the pointer on a squiggle and the card
     * says what is wrong, then — once the server answers — what Alt+Enter
     * would offer, with the key that does it.
     */
    public function testASquiggleExplainsItsProblemAndItsFix(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    $editor = Widgets::editor($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $this->assertInstanceOf(WidgetRect::class, $rect);

                    // A bare move onto the `<` the error underlines.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + $editor->gutterWidth() + 1, $rect->row + 1));

                    $this->await(static fn (): bool => \str_starts_with(Services::resolve($ide, TooltipCenter::class)->shown()->key ?? '', 'problem:'), what: 'the problem card');
                    $this->awaitPainted($tui, $terminal, 'Fix: Import class');
                    $this->assertNotNull($this->rowOf($this->screenGrid($tui, $terminal), 'More actions…'), 'and the list behind it');
                },
            ],
        );
    }

    /** The 0-based [row, col] of the LAST screen row carrying $needle — the status bar sits at the bottom. */
    private function lastCellOf(Tui $tui, VirtualTerminal $terminal, string $needle): array
    {
        $grid = $this->screenGrid($tui, $terminal);
        foreach (\array_reverse($grid, preserve_keys: true) as $row => $text) {
            $col = \mb_strpos($text, $needle);
            if (false !== $col) {
                return [$row, $col];
            }
        }
        self::fail("nothing on screen reads {$needle}");
    }

    /** The caret's problem, clicked in the status bar, opens the Problems panel on its row. */
    public function testClickingTheStatusBarProblemOpensItInProblems(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    [$row, $col] = $this->lastCellOf($tui, $terminal, 'Mock diagnostic');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));

                    $panel = Widgets::byId($tui, 'results', ResultsPanelWidget::class);
                    $this->await($panel->isOpen(...), what: 'the Problems panel');
                    $state = $panel->captureViewState();
                    $this->assertStringEndsWith('Problem.php', (string) $state->selectedPath);
                    $this->assertSame(0, $state->selectedLine, 'on the problem\'s row');
                },
            ],
        );
    }

    /** In `menu` mode, a problem row at the top of Alt+Enter opens it in Problems. */
    public function testClickingAProblemRowInTheQuickFixesOpensItInProblems(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                ['settings' => ['diagnosticMessages' => 'menu']] + (array) \json_decode($this->mockLspConfig(), associative: true, flags: \JSON_THROW_ON_ERROR),
                \JSON_THROW_ON_ERROR,
            ),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r"); // alt+enter
                    $this->awaitPainted($tui, $terminal, 'Import class');
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = $this->rowOf($grid, '✗ Mock diagnostic');
                    $this->assertNotNull($row, 'the problem heads the list');
                    $col = (int) \mb_strpos($grid[$row], needle: '✗ Mock diagnostic');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 3, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 3, $row + 1));

                    $panel = Widgets::byId($tui, 'results', ResultsPanelWidget::class);
                    $this->await($panel->isOpen(...), what: 'the Problems panel');
                    $this->assertSame(0, $panel->captureViewState()->selectedLine);
                    $this->assertNull($this->rowOf($this->screenGrid($tui, $terminal), 'Import class'), 'and the list closed');
                },
            ],
        );
    }

    /** The gutter beside a problem says nothing of it: its card is on the code. */
    public function testTheGutterBesideAProblemHasNoCard(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    $editor = Widgets::editor($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $tooltips = Services::resolve($ide, TooltipCenter::class);
                    $tooltips->setDelayMs(0);

                    // The marker column, then the code's first cell.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + $editor->gutterWidth() - 2, $rect->row + 1));
                    $this->assertNull($tooltips->shown(), 'no card from the gutter');
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + $editor->gutterWidth() + 1, $rect->row + 1));
                    $this->await(static fn (): bool => \str_starts_with($tooltips->shown()->key ?? '', 'problem:'), what: 'the card on the code');
                },
            ],
        );
    }

    /** PhpStorm's Ctrl+F1 on the mock's problem: the error, described where the caret is. */
    public function testCtrlF1DescribesTheProblemAtTheCaret(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    $document = Widgets::editor($tui)->getDocument();

                    $document->setCursor(1, 2);
                    $tui->handleInput("\x1b[1;5P");
                    $this->awaitPainted($tui, $terminal, 'No problem at the caret');

                    $document->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;5P");
                    // The popup's row, not the status bar's: it is framed.
                    $this->awaitPainted($tui, $terminal, '│ ✗ Mock diagnostic');
                    $this->assertNull($this->rowOf($this->screenGrid($tui, $terminal), 'Says hello'), 'the problem alone: no documentation was asked for');
                },
            ],
        );
    }

    /** Shift+F1 on a problem: the problem heads the documentation. */
    public function testShiftF1PutsTheProblemAboveTheDocs(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;2P"); // shift+f1

                    $this->awaitPainted($tui, $terminal, 'Says hello');
                    $grid = $this->screenGrid($tui, $terminal);
                    $problem = $this->rowOf($grid, '│ ✗ Mock diagnostic');
                    $this->assertNotNull($problem);
                    $docs = $this->rowOf($grid, 'Says hello');
                    $this->assertNotNull($docs, 'the documentation is there too');
                    $this->assertGreaterThan($problem, $docs, 'under the problem');
                },
            ],
        );
    }

    /** PhpStorm's Alt+Shift+Enter, sent the way kitty's protocol does: the first fix, applied without the list. */
    public function testAltShiftEnterAppliesTheFirstFix(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Problem.php' => "<?php\necho 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Problem.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                    $document = Widgets::editor($tui)->getDocument();
                    $document->setCursor(0, 0);
                    $tui->handleInput("\x1b[13;4u");

                    $this->await(static fn (): bool => 'use Acme\\Imported;' === $document->getLine(1), what: 'the fix applied');
                    $this->awaitPainted($tui, $terminal, 'Applied "Import class"');
                    $this->assertNull($this->rowOf($this->screenGrid($tui, $terminal), 'Generate method'), 'no list was opened');
                },
            ],
        );
    }

    /**
     * A parameter's name is a shaded pill before a literal argument and
     * nothing before a variable, as in PhpStorm — until the setting asks for
     * all of them, which applies without asking the server again.
     */
    public function testParameterHintsArePillsBeforeLiteralsOnly(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Params.php' => "<?php\nf(42, \$y);\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Params.php'),
            [
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'f(n: 42, $y);');
                    $pill = Styles::dark()->of(Role::InlayParameter)->apply('n:');
                    $this->assertStringContainsString($pill, $terminal->getOutput(), 'the name is drawn as a pill');

                    Services::resolve($ide, SettingsController::class)->changeSetting('inlayParameterHints', 'all');
                    $this->awaitPainted($tui, $terminal, 'f(n: 42, m: $y);');
                },
            ],
        );
    }

    /**
     * Both display modes switch live from the View menu's cycle commands —
     * no restart: the hint leaves the line and comes back (asked for again,
     * having been dropped while off), and the diagnostic message moves from
     * after the code to the top of the Alt+Enter list.
     */
    public function testHintAndMessageModesSwitchLiveFromTheCommands(): void
    {
        $project = new TempProject([
            // Starts from the message after the code, which PhpStorm's default does not show.
            '.porta.json' => (string) \json_encode(
                ['settings' => ['diagnosticMessages' => 'inline']] + (array) \json_decode($this->mockLspConfig(), associative: true, flags: \JSON_THROW_ON_ERROR),
                \JSON_THROW_ON_ERROR,
            ),
            'src/Inlay.php' => "<?php\n\$x = f();\n",
        ]);
        $lineShows = fn (Tui $tui, VirtualTerminal $terminal, string $needle): bool => null !== $this->rowOf($this->screenGrid($tui, $terminal), $needle);
        // The code line carrying the diagnostic, not the status bar — which
        // names the caret line's problem in every mode.
        $codeLineShows = function (Tui $tui, VirtualTerminal $terminal, string $needle): bool {
            $grid = $this->screenGrid($tui, $terminal);

            return \str_contains($grid[$this->rowOf($grid, '<?php') ?? 0] ?? '', $needle);
        };

        $this->runIde(
            $project,
            $project->path('src/Inlay.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitLspReady($tui, $terminal);
                    $this->awaitPainted($tui, $terminal, ': int');
                    $this->awaitPainted($tui, $terminal, 'Mock diagnostic');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($lineShows, $codeLineShows): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('cycle_inlay_hints'); // inline → tooltip
                    $this->await(static fn (): bool => !$lineShows($tui, $terminal, needle: ': int'), what: 'the hint to leave the line');
                    $commands->run('cycle_inlay_hints'); // tooltip → none
                    $commands->run('cycle_inlay_hints'); // none → inline, asked for again
                    $this->await(static fn (): bool => $lineShows($tui, $terminal, needle: ': int'), what: 'the hint to come back');

                    $this->assertTrue($codeLineShows($tui, $terminal, needle: 'Mock diagnostic'), 'inline: after the code');
                    $commands->run('cycle_diagnostic_messages'); // inline → menu
                    $this->await(static fn (): bool => !$codeLineShows($tui, $terminal, needle: 'Mock diagnostic'), what: 'the message to leave the line');
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r"); // alt+enter
                    $this->await(static fn (): bool => $lineShows($tui, $terminal, needle: 'Import class'), what: 'the quick fixes');
                    $grid = $this->screenGrid($tui, $terminal);
                    // The first row naming it, which is above the status bar's.
                    $this->assertLessThan($this->rowOf($grid, 'Import class'), $this->rowOf($grid, 'Mock diagnostic'), 'the problem heads the fixes');
                    $tui->handleInput("\x1b");
                },
            ],
        );
    }

    public function testQuickFixesEditCommandAndEmptyPaths(): void
    {
        $tall = "<?php\n\nclass QuickFixMe {}\n".\str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Q.php' => $tall,
        ]);

        $this->runIde(
            $project,
            $project->path('src/Q.php'),
            [
                // Edit-based action: "Import class" inserts a use statement
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // alt+enter against a session that has not initialised is
                    // dropped in silence, and every barrier after this one then
                    // waits for a request that was never sent. The status bar
                    // naming the server is the signal that it is up.
                    $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');

                    Widgets::editor($tui)->getDocument()->setCursor(2, 6);
                    $tui->handleInput("\x1b\r"); // alt+enter
                },
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The picker opens on the server's codeAction RESPONSE, so
                    // this was a 200 ms bet standing in front of the paint
                    // barrier that follows it.
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the quick-fix picker to open',
                    );
                    $this->awaitPainted($tui, $terminal, 'Quick Fixes');
                    $this->assertStringContainsString('Import class', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // pick "Import class"
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // Accepting sends the edit; it lands when the server
                    // answers, which is what this used to bet 150 ms on.
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => 'use Acme\Imported;' === $editor->getDocument()->getLine(1),
                        what: 'the Import class edit to reach the buffer',
                    );
                    $this->awaitPainted($tui, $terminal, 'Applied "Import class" to 1 file');
                    $this->assertStringContainsString('Applied "Import class" to 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Command-based action next
                    $tui->handleInput("\x1b\r");
                },
                400 => function (Tui $tui): void {
                    // These keys only mean anything once the picker is up, and
                    // it is up on another codeAction response.
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the second quick-fix picker to open',
                    );

                    $tui->handleInput("\x1b[B"); // -> Generate method
                    $tui->handleInput("\r");
                },
                500 => function (Tui $tui, $terminal): void {
                    $editor = Widgets::editor($tui);
                    // executeCommand reply and the server-initiated applyEdit both
                    // flash and race; await the deterministic buffer effect.
                    $this->await(
                        static fn (): bool => '// generated' === $editor->getDocument()->getLine(0),
                        what: 'the server applyEdit to land',
                    );
                    // Empty path: mock convention line >= 90
                    $editor->getDocument()->setCursor(95, 0);
                    $tui->handleInput("\x1b\r");
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The POSITIVE first. "No picker is open" is also true
                    // before the server has answered at all, so asserting it
                    // ahead of the flash proved nothing.
                    $this->awaitPainted($tui, $terminal, 'No quick fixes here');
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')), 'an empty action list opens no picker');
                    $this->assertStringContainsString('No quick fixes here', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testQuickFixUsesDiagnosticRangeAndHandlesNoOpAction(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/D.php' => "<?php\n\$broken = ;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/D.php'),
            [
                // Wait for the mock's published diagnostic on line 0, then request
                // quick fixes from that line: the request range comes from the
                // diagnostic (covered branch), and the actions apply normally.
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The picker opens on the codeAction response.
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the quick-fix picker to open',
                    );
                    // The assertion after the run reads the picker's TITLE off
                    // the accumulated output, so it has to have been painted
                    // while the picker was open. A bare tick() here was not
                    // that: inside the 60fps cap it paints nothing, Esc closes
                    // the picker on the next line, and the terminator's forced
                    // frame then renders the closed state — the title never
                    // reaching the output at all.
                    $this->awaitPainted($tui, $terminal, 'Quick Fixes');
                    $tui->handleInput("\x1b"); // close without applying
                },
            ],
        );

        $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testProblemsPanelAggregatesAcrossBuffersAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/A.php' => "<?php\n\$x = 1;\n",
            'src/B.php' => "<?php\n\$y = 2;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // Open B too so both buffers carry the mock's line-0 diagnostic
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->handleInput("\x14"); // tree (selection on src/)
                    $tui->handleInput("\r"); // expand src/
                    $tui->handleInput("\x1b[B"); // A.php
                    $tui->handleInput("\x1b[B"); // B.php
                    $tui->handleInput("\r"); // open B.php
                },
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b6"); // alt+6 problems
                },
                300 => function ($tui, $terminal): void {
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    // Diagnostics for both buffers are async LSP notifications:
                    // await them landing instead of a fixed gap.
                    $this->await(static function () use ($terminal): bool {
                        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());

                        return \str_contains($screen, 'Mock diagnostic') && \str_contains($screen, 'A.php') && \str_contains($screen, 'B.php');
                    }, what: "both buffers' diagnostics to land");
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen);
                    // Both buffers carry the mock server's line-0 diagnostic
                    $this->assertStringContainsString('Mock diagnostic', $screen);
                    $this->assertStringContainsString('A.php', $screen);
                    $this->assertStringContainsString('B.php', $screen);
                    $tui->handleInput("\r"); // jump to first problem
                },
                400 => function (Tui $tui): void {
                    // First row sorts to A.php; jumping opens it in the editor
                    $editor = Widgets::editor($tui);
                    $this->await(
                        static fn (): bool => \str_ends_with((string) $editor->getFilePath(), 'A.php'),
                        what: 'the problems-row jump to open its file',
                    );
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertTrue(Widgets::byId($tui, 'results', ResultsPanelWidget::class)->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );
    }
}
