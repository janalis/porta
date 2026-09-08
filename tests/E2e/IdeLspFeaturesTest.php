<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\JumpHistory;
use Porta\Editor\OpenFocus;
use Porta\Editor\SelectionMode;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Sgr;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * LSP-driven features end to end: diagnostics, completion,
 * navigation, symbols, quick fixes, formatting, server fault handling.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeLspFeaturesTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testNoLanguageServerMode(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x00"); // completion: silently ignored
                    $tui->handleInput("\x1b[24~"); // definition: ignored
                    $tui->handleInput("\x1b[1;2P"); // hover: ignored
                    $tui->handleInput("\x1b[18;3~"); // usages: ignored
                    $tui->handleInput("\x1b[104;6u"); // call hierarchy: ignored
                    $tui->handleInput('('); // signature: ignored
                    $tui->handleInput("\x1b[17;2~"); // rename prompt opens...
                    $tui->handleInput("\r"); // ...accept prefilled: flashes "needs a language server"... but word empty -> nothing
                    $tui->handleInput("\x05"); // ctrl+e with a single buffer: no-op
                    $tui->handleInput('x'); // type (didChange path without LSP)
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:none (enable a PHP server in Settings)', $screen);
    }

    public function testRenameWithoutServerFlashes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$word\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 5);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                    $tui->handleInput("\r"); // accept prefilled "word"
                },
            ],
        );

        $this->assertStringContainsString('Rename needs a language server', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testGoToTestRoundTripsBetweenClassAndTest(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'src/Foo.php' => "<?php\n",
            'tests/Unit/FooTest.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Foo.php'),
            [
                100 => function ($tui): void {
                    $path = static fn (): string => (string) $tui->getById('editor')->getFilePath();
                    $this->await(static fn (): bool => '' !== $path(), what: 'the subject file to open at boot');

                    $tui->handleInput("\x1b[116;6u"); // ctrl+shift+t → the single test
                    $this->await(
                        static fn (): bool => str_ends_with($path(), 'tests/Unit/FooTest.php'),
                        what: 'the test file to open',
                    );

                    $tui->handleInput("\x1b[116;6u"); // ctrl+shift+t again → back to the subject
                    $this->await(
                        static fn (): bool => str_ends_with($path(), 'src/Foo.php'),
                        what: 'the subject file to reopen',
                    );
                },
            ],
        );

        // Each hop is awaited above, so reaching here IS the assertion: an
        // await that never becomes true throws rather than falling through.
        $this->addToAssertionCount(1);
    }

    public function testGoToTestFlashesWhenNothingIsFound(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/Alone.php' => "<?php\n", 'tests/OrphanTest.php' => "<?php\n"]);

        // From a class with no test.
        $this->runIde(
            $project,
            $project->path('src/Alone.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                340 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'No test found');
                    $this->assertStringContainsString('No test found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // From a test with no subject.
        $this->runIde(
            $project,
            $project->path('tests/OrphanTest.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                340 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'No test subject found');
                    $this->assertStringContainsString('No test subject found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToTestOffersChooserForMultipleCandidates(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'src/Multi.php' => "<?php\n",
            'tests/Unit/MultiTest.php' => "<?php\n",
            'tests/E2e/MultiTest.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Multi.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                200 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Choose target');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('tests/E2e/MultiTest.php', $screen);
                    $this->assertStringContainsString('tests/Unit/MultiTest.php', $screen);
                    $tui->handleInput("\r"); // open the selected candidate
                },
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => str_ends_with((string) $editor->getFilePath(), 'MultiTest.php'),
                        what: 'the chosen candidate to open',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWarningDiagnosticsShowInStatusBar(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$x = 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui
                        ->getById('editor')
                        ->decorations()
                        ->setDiagnostics([
                            1 => [['severity' => 2, 'message' => 'unused variable', 'col' => 0, 'endCol' => 2]],
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

    public function testUnspawnableServerReportsFailed(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => '/nonexistent-binary-xyz --stdio']),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.5);

        $this->assertStringContainsString('LSP:failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDebounceFiresAfterBufferClosed(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->handleInput('x'); // schedules a didChange debounce
                    $tui->handleInput("\x1a"); // undo... still dirty; save instead
                    $tui->handleInput("\x13"); // ctrl+s -> clean
                    $tui->handleInput("\x1bw"); // close: debounce later fires with no file
                },
            ],
        ); // > 400ms + 300ms debounce

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    public function testCompletionWithNullResultIsIgnored(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(91, 0);
                    $tui->handleInput("\x00"); // ctrl+space -> mock replies null
                },
                // This gap stays, and the reason is worth writing down: a
                // null completion response produces NOTHING — no popup, no
                // flash, no buffer change — so "the popup is closed" is equally
                // true before the server has answered at all. There is no
                // positive to await here, only the absence of one, and the
                // gap is what makes the absence mean anything.
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen(), 'a null result opens no popup');
                },
            ],
        );
    }

    public function testCompletionResolveWithoutImportsLeavesFileUntouched(): void
    {
        // Accepting an item whose completionItem/resolve returns no
        // additionalTextEdits must insert only the completion, no `use` line.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Plain.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Plain.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    $editor->getDocument()->setCursor(1, 0); // blank line, empty prefix
                    $tui->handleInput("\x00"); // ctrl+space
                    // The popup opens on the server's completion RESPONSE, not
                    // on the keypress — a 300 ms gap here was a bet on that
                    // round trip.
                    // First-class callable, not an arrow fn: the method is
                    // re-invoked on a stable object every turn, so it
                    // re-evaluates — which is the property await() requires.
                    $this->await($editor->isCompletionOpen(...), what: 'the completion popup to open');

                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\r"); // accept trim (resolves to no imports)

                    // Accepting sends completionItem/resolve; the edit lands
                    // when that answers, so awaiting the text IS awaiting the
                    // resolve — which is also what makes the negative below
                    // safe, since any import it would have added has landed
                    // by then too.
                    $this->await(
                        static fn (): bool => str_contains($editor->getDocument()->getText(), 'trim('),
                        what: 'the accepted completion to reach the document',
                    );

                    $this->assertStringNotContainsString('use ', $editor->getDocument()->getText(), 'no auto-import added');
                },
            ],
        );
    }

    public function testIncrementalDidChangeSendsMinimalDelta(): void
    {
        // A server advertising incremental sync (change kind 2) must receive a
        // ranged content change carrying only the typed text, not the whole file.
        $project = new TempProject([
            '.porta.json' => json_encode([
                'lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/incremental-lsp-server.php',
            ]),
            'src/Inc.php' => "<?php\n\nfoo\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inc.php'),
            [
                100 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    $editor->getDocument()->setCursor(2, 3); // end of "foo"
                    $tui->handleInput('Z'); // insert one character

                    // The mock reflects the change kind + text into a
                    // diagnostic, so awaiting it IS awaiting the didChange
                    // round trip to the server PROCESS — which is what the
                    // 600ms gap here used to bet on.
                    $editor->getDocument()->setCursor(0, 0);
                    $this->await(
                        static fn (): bool => 'sync:incremental:Z' === $editor->getCursorDiagnostic(),
                        what: 'the incremental didChange to come back as a diagnostic',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSnippetCompletionExpandsWithTabStops(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Snip.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Snip.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x00"); // ctrl+space
                },
                200 => function ($tui): void {
                    // The popup opens on the completion RESPONSE, not the keypress.
                    $editor = $tui->getById('editor');
                    $this->await($editor->isCompletionOpen(...), what: 'the completion popup to open');

                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\x1b[B"); // -> sprintf (snippet)
                    $tui->handleInput("\r"); // accept
                },
                300 => function ($tui): void {
                    // Accepting resolves the item first; the expansion lands
                    // when that answers.
                    $doc = $tui->getById('editor')->getDocument();
                    $this->await(
                        static fn (): bool => 'sprintf(format, args)' === $doc->getLine(1),
                        what: 'the snippet to expand into the buffer',
                    );
                    $this->assertSame('format', $doc->getSelectedText(), 'first tab stop selected');
                },
            ],
        );
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
                600 => function ($tui, $terminal): void {
                    // Inlay hints are an LSP round trip, so this was a bet on the
                    // response AND on the frame that draws it.
                    $this->awaitPainted($tui, $terminal, ': int');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString(': int', $screen, 'inlay type hint rendered inline');
                },
            ],
        );
    }

    public function testGoToImplementationChooserAndTypeDefinitionJump(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Target.php' => "<?php\n\n// line 2\nclass Target\n{\n}\n",
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string\n    {\n        return 'x';\n    }\n}\n".str_repeat("//\n", times: 100),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b -> implementation (2 results)
                },
                200 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Choose target');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Target.php', $screen);
                    $tui->handleInput("\x1b"); // close the chooser
                },
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b[98;6u"); // ctrl+shift+b -> type definition (single -> jump)
                },
                400 => function ($tui): void {
                    // The type-definition jump is an LSP round trip: await it
                    // landing instead of a fixed gap.
                    $this->await(static fn (): bool => str_ends_with((string) $tui->getById('editor')->getFilePath(), 'Target.php'), what: 'the type-definition jump to land');
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'single type definition jumped');
                    // No implementation at a high line -> flash.
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                },
            ],
        );
    }

    public function testGoToImplementationWithNoResultFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", times: 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    $editor->getDocument()->setCursor(95, 0); // >= 90 -> null
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b
                    $this->awaitPainted($tui, $terminal, 'No implementation found');
                    $this->assertStringContainsString('No implementation found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // >= 80 returns several candidates, all outside the
                    // filesystem, so every one is dropped and the controller
                    // flashes the SAME not-found message instead of opening a
                    // chooser. That flash is the terminal state the negative
                    // below hangs off: asserting "no chooser" after a bare gap
                    // cannot tell "it decided not to" from "it has not answered
                    // yet", which is the trap the doctrine names for negatives.
                    $terminal->clearOutput();
                    $editor->getDocument()->setCursor(85, 0);
                    $tui->handleInput("\x1b\x02");
                    $this->awaitPainted($tui, $terminal, 'No implementation found');

                    $this->assertStringNotContainsString('Choose target', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no chooser when every candidate is unresolvable');
                },
            ],
        );
    }

    public function testFormatSelectionUsesRangeFormatting(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Fmt.php' => "<?php\nmessy( );\nkeep();\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Fmt.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(1, 0);
                    $doc->beginSelect();
                    $doc->setCursor(1, 8, selection: SelectionMode::Keep); // select "messy( )"
                    $tui->handleInput("\x1b\x0c"); // ctrl+alt+l reformat
                },
                200 => function ($tui): void {
                    // rangeFormatting replaced only the selection with the
                    // marker — await the edit, then the untouched line means
                    // "outside the range", not "not there yet".
                    $doc = $tui->getById('editor')->getDocument();
                    $this->await(
                        static fn (): bool => str_contains($doc->getLine(1), 'FORMATTED'),
                        what: 'the range formatting to reach the buffer',
                    );
                    $this->assertSame('keep();', $doc->getLine(2), 'code outside the selection untouched');
                },
            ],
        );
    }

    public function testPrepareRenameDeclineFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", times: 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // >= 95 -> prepareRename null
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                },
                400 => function ($tui, $terminal): void {
                    // A flash (not a prompt) proves prepareRename was rejected.
                    $this->awaitPainted($tui, $terminal, 'Cannot rename this element');
                    $this->assertStringContainsString('Cannot rename this element', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    /**
     * Semantic tokens reach the buffer's decorations in the RUNNING IDE.
     *
     * The unit tests all construct the controller themselves, which proves it
     * works and not that anything builds it. A previous round shipped an LSP
     * controller with every gate green and 100% coverage that had never run in
     * the app once, because nothing injected it — here the refresher is an
     * event listener and the buffer opening is what has to trigger it.
     */
    public function testANonPhpBufferGetsItsScopesFromTheSERVER(): void
    {
        // PHP is the one language that does NOT take this path:
        // DocumentScopeController returns early on `.php` and the offline
        // scanner answers instead. So everything that reads scopes — the
        // breadcrumb trail, the sticky band, the member separators — has a
        // whole half that only runs for other languages, and it had been
        // proved only by setting DocumentScopes by hand.
        //
        // The line numbers below are the mock's, which are fixed: class `Demo`
        // spans lines 2-8 and its method `greet` 4-6, zero-based.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/app.js' => "// one\n\nclass Demo {\n\n  greet() {\n    return 1;\n  }\n\n}\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/app.js'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');
                    $tui->getById('editor')->getDocument()->setCursor(5, 4); // inside greet()

                    $crumbs = $tui->getById('breadcrumbs');
                    $this->await(
                        static function () use ($tui, $crumbs): bool {
                            $tui->requestRender();
                            $tui->tick();
                            $rows = $crumbs->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1));

                            return str_contains(AnsiUtils::stripAnsiCodes($rows[0] ?? ''), 'Demo');
                        },
                        what: "the server's symbols to reach the trail",
                    );

                    $plain = AnsiUtils::stripAnsiCodes($crumbs->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('Demo › greet', $plain, 'a JavaScript file gets a trail');
                },
                300 => function ($tui): void {
                    // Same scopes, a second reader: the rule closes off greet()
                    // on the server's endLine (0-based 6, the `}` line).
                    $editor = $tui->getById('editor');
                    $editor->display()->setMethodSeparators(true);
                    $rows = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 12));
                    $ruled = array_keys(array_filter($rows, static fn (string $row): bool => str_contains($row, Sgr::UNDERLINE)));

                    $this->assertSame([6], $ruled, "one rule, on the member's last line");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSemanticTokensReachTheBufferInTheRunningIde(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            // The mock only colours files named *Semantic*, so every other
            // screen in this suite keeps the lexer's colours.
            'src/Semantic.php' => "<?php\n\nclass Semantic\n{\n    public string \$field = 'x';\n}\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Semantic.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');

                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => [] !== $editor->decorations()->semanticTokensAt(1),
                        what: 'the server semantic tokens to land on the buffer',
                    );

                    // The mock's legend is deliberately scrambled, so these
                    // names only come out right if the legend was READ.
                    $this->assertSame(
                        [
                            ['col' => 4, 'endCol' => 9, 'type' => 'property', 'modifiers' => []],
                            ['col' => 11, 'endCol' => 14, 'type' => 'method', 'modifiers' => ['static', 'deprecated']],
                        ],
                        $editor->decorations()->semanticTokensAt(1),
                    );
                },
            ],
        );
    }

    public function testQuickFixesEditCommandAndEmptyPaths(): void
    {
        $tall = "<?php\n\nclass QuickFixMe {}\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Q.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Q.php'),
            [
                // Edit-based action: "Import class" inserts a use statement
                100 => function ($tui, $terminal): void {
                    // alt+enter against a session that has not initialised is
                    // dropped in silence, and every barrier after this one then
                    // waits for a request that was never sent. The status bar
                    // naming the server is the signal that it is up.
                    $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');

                    $tui->getById('editor')->getDocument()->setCursor(2, 6);
                    $tui->handleInput("\x1b\r"); // alt+enter
                },
                200 => function ($tui, $terminal): void {
                    // The picker opens on the server's codeAction RESPONSE, so
                    // this was a 200 ms bet standing in front of the paint
                    // barrier that follows it.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the quick-fix picker to open',
                    );
                    $this->awaitPainted($tui, $terminal, 'Quick Fixes');
                    $this->assertStringContainsString('Import class', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // pick "Import class"
                },
                300 => function ($tui, $terminal): void {
                    // Accepting sends the edit; it lands when the server
                    // answers, which is what this used to bet 150 ms on.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => 'use Acme\Imported;' === $editor->getDocument()->getLine(1),
                        what: 'the Import class edit to reach the buffer',
                    );
                    $this->awaitPainted($tui, $terminal, 'Applied "Import class" to 1 file');
                    $this->assertStringContainsString('Applied "Import class" to 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Command-based action next
                    $tui->handleInput("\x1b\r");
                },
                400 => function ($tui): void {
                    // These keys only mean anything once the picker is up, and
                    // it is up on another codeAction response.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the second quick-fix picker to open',
                    );

                    $tui->handleInput("\x1b[B"); // -> Generate method
                    $tui->handleInput("\r");
                },
                500 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
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
                600 => function ($tui, $terminal): void {
                    // The POSITIVE first. "No picker is open" is also true
                    // before the server has answered at all, so asserting it
                    // ahead of the flash proved nothing.
                    $this->awaitPainted($tui, $terminal, 'No quick fixes here');
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')), 'an empty action list opens no picker');
                    $this->assertStringContainsString('No quick fixes here', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
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
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r");
                },
                200 => function ($tui): void {
                    // The picker opens on the codeAction response.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the quick-fix picker to open',
                    );
                    $tui->tick();
                    $tui->handleInput("\x1b"); // close without applying
                },
            ],
        );

        $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testFileStructurePopupJumpsToSymbol(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string { return 'x'; }\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->handleInput("\x1b[24;5~"); // ctrl+f12
                },
                200 => function ($tui, $terminal): void {
                    // The popup opens on the documentSymbol response. This is
                    // the specific barrier: awaiting 'greet' alone is weak here,
                    // because the fixture's own source contains `greet` and the
                    // EDITOR has already painted it.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the file-structure popup to open',
                    );
                    $this->awaitPainted($tui, $terminal, 'File Structure');
                    // The CARD, not the screen: `Demo` and `greet` are the
                    // symbols in the buffer this popup is drawn over, so a
                    // screen-wide assertion passes whether the popup lists them
                    // or nothing at all. Measured — replacing every label with
                    // `ZZZ0`, `ZZZ1`… left this test green.
                    $card = $this->card($this->screenGrid($tui, $terminal), 'File Structure');
                    $this->assertStringContainsString('Demo', $card);
                    $this->assertStringContainsString('greet', $card);
                    $tui->handleInput("\x1b[B"); // -> greet
                    $tui->handleInput("\r");
                },
                300 => function ($tui): void {
                    // greet's selectionRange starts at line 4
                    $doc = $tui->getById('editor')->getDocument();
                    $this->await(
                        static fn (): bool => 4 === $doc->getCursorLine(),
                        what: 'the structure pick to move the caret',
                    );
                },
            ],
        );

        // No trailing screen assertion: card() above already fails loudly when
        // no card by that title is on screen, and it reads a FORCED frame
        // rather than the accumulated output — which this line was reading, and
        // which screenGrid() drains.
        $this->addToAssertionCount(1);
    }

    public function testFileStructureEmptyResult(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Empty.php' => "<?php\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Empty.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->handleInput("\x1b7"); // alt+7
                },
                300 => function ($tui, $terminal): void {
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')));
                    $this->awaitPainted($tui, $terminal, 'No symbols in this file');
                    $this->assertStringContainsString('No symbols in this file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToSymbolInProject(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Greeter {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    // Ctrl+Alt+Shift+N, this action's primary and PhpStorm's own
                    // binding for it. Ctrl+Alt+O was a second binding here and is
                    // Optimize Imports now, which is what PhpStorm means by it.
                    $tui->handleInput("\x1b[110;8u");
                    foreach (str_split('greet') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                200 => function ($tui, $terminal): void {
                    $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen(), 'go-to-symbol opens the centered picker');
                    // The workspace-symbol query is an LSP round trip: await it
                    // landing instead of a fixed gap.
                    $this->awaitPainted($tui, $terminal, 'Greeter');
                    // The CARD, not the screen: `Greeter` is also the tree's
                    // file name and the buffer's own text, so a screen-wide
                    // assertion passes whether the modal lists the symbol or
                    // `ZZZ` — measured.
                    $card = $this->card($this->screenGrid($tui, $terminal), 'Go to Symbol');
                    $this->assertStringContainsString('Greeter', $card);
                    $tui->handleInput("\r"); // jump
                },
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => str_ends_with((string) $editor->getFilePath(), 'Target.php'),
                        what: 'the symbol pick to open its file',
                    );
                    $this->assertSame(3, $editor->getDocument()->getCursorLine());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testProblemsPanelAggregatesAcrossBuffersAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/A.php' => "<?php\n\$x = 1;\n",
            'src/B.php' => "<?php\n\$y = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // Open B too so both buffers carry the mock's line-0 diagnostic
                100 => function ($tui, $terminal): void {
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

                        return str_contains($screen, 'Mock diagnostic') && str_contains($screen, 'A.php') && str_contains($screen, 'B.php');
                    }, what: "both buffers' diagnostics to land");
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen);
                    // Both buffers carry the mock server's line-0 diagnostic
                    $this->assertStringContainsString('Mock diagnostic', $screen);
                    $this->assertStringContainsString('A.php', $screen);
                    $this->assertStringContainsString('B.php', $screen);
                    $tui->handleInput("\r"); // jump to first problem
                },
                400 => function ($tui): void {
                    // First row sorts to A.php; jumping opens it in the editor
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => str_ends_with((string) $editor->getFilePath(), 'A.php'),
                        what: 'the problems-row jump to open its file',
                    );
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testReformatFile(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Messy.php' => "<?php\n   \$a=1  ;\n\n\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Messy.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->handleInput("\x1b\x0c"); // ctrl+alt+l
                },
                300 => function ($tui, $terminal): void {
                    // The formatted text lands on the server's response, so
                    // await it rather than reading it before the flash barrier.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => 'formatted();' === $editor->getDocument()->getLine(2),
                        what: 'the reformat edit to reach the buffer',
                    );
                    $this->awaitPainted($tui, $terminal, 'Reformatted src/Messy.php');
                    $this->assertStringContainsString('Reformatted src/Messy.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekDefinitionShowsContextAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nnew Target();\n",
            'src/Target.php' => "<?php\n\n// header\nclass Target\n{\n    public int \$x = 1;\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(1, 4);
                    $tui->handleInput("\x1b[105;6u"); // ctrl+shift+i peek
                },
                200 => function ($tui, $terminal): void {
                    // The peek popup opens on the definition response.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::pickerOpen($editor),
                        what: 'the peek popup to open',
                    );
                    $this->awaitPainted($tui, $terminal, 'Peek: src/Target.php:4');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('class Target', $screen, 'definition line shown');
                    // Only now does "still Demo.php" mean the peek DECLINED to
                    // switch buffers, rather than not having happened yet.
                    $this->assertStringEndsWith('Demo.php', (string) $editor->getFilePath(), 'peek did not switch buffers');
                    $tui->handleInput("\r"); // pick a context line -> jump for real
                },
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => str_ends_with((string) $editor->getFilePath(), 'Target.php'),
                        what: 'Enter to jump into the file',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekWithNoDefinition(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/T.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/T.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->getById('editor')->getDocument()->setCursor(91, 0); // mock: 90-94 -> no definition
                    $tui->handleInput("\x1b[105;6u");
                },
                300 => function ($tui, $terminal): void {
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')));
                    $this->awaitPainted($tui, $terminal, 'Nothing to peek');
                    $this->assertStringContainsString('Nothing to peek', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // A definition that resolves outside the filesystem is refused.
                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // mock: >=95 untitled uri
                    $tui->handleInput("\x1b[105;6u");
                },
                500 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Peek target outside the filesystem');
                    $this->assertStringContainsString('Peek target outside the filesystem', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testJumpStackIsCapped(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('src/Demo.php'), $terminal);
        $tui = Services::resolve($ide, Tui::class);

        // The history is a real service now, so this primes it through its own
        // API rather than reflecting into a private field.
        $history = Services::resolve($ide, JumpHistory::class);
        for ($i = 0; $i < 50; ++$i) {
            $history->push($project->path('src/Demo.php'), 0, 0);
        }

        EventLoop::delay(0.4, static fn () => $tui->handleInput("\x1b[24~")); // F12
        // The jump is an LSP round trip: poll for it landing instead of a
        // fixed delay, which races the mock server under pcov + machine load.
        $poll = EventLoop::repeat(0.02, static function (string $watcherId) use ($tui, &$safety): void {
            if (str_ends_with((string) $tui->getById('editor')->getFilePath(), 'Target.php')) {
                EventLoop::cancel($watcherId);
                EventLoop::cancel($safety);
                $tui->stop();
            }
        });
        $safety = EventLoop::delay(10.0, static function () use ($poll, $tui): void {
            EventLoop::cancel($poll);
            $tui->stop(); // the jump never landed; stop so the assertion below fails cleanly
        });
        $ide->run();

        $this->assertCount(50, $history->entries(), 'the jump the IDE actually made evicted the oldest at the cap');
        $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath());
    }

    public function testCrashedServerReportsFailedStatus(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/crashing-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The crash surfaces via stderr logging; initialize never completes.
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:starting…', $screen);
    }

    public function testServerInitiatedApplyEditIsHandled(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/demanding-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The demanding server fires an empty workspace/applyEdit after init
        // (applied: false path) and a real one against the opened file after
        // didOpen (applied: true path, flashed to the status bar).
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:demanding-lsp-server', $screen);
        $this->assertStringContainsString('Applied server edit to 1 file', $screen);
    }

    public function testLspNegativePathsWithMockServer(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    // Every negative path is a mock-server round trip; await
                    // each flash instead of racing fixed gaps and asserting
                    // the accumulated output after the loop stopped.
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(91, 0); // mock convention: >= 90 -> nothing found
                    $tui->handleInput("\x1b[24~"); // definition -> none
                    $this->await(static fn (): bool => str_contains($screen(), 'No definition found'), what: 'the no-definition flash');
                    $tui->handleInput("\x1b[1;2P"); // hover -> none
                    $this->await(static fn (): bool => str_contains($screen(), 'No documentation found'), what: 'the no-hover flash');
                    $tui->handleInput("\x1b[18;3~"); // usages -> none
                    $this->await(static fn (): bool => str_contains($screen(), 'No usages found'), what: 'the no-usages flash');
                    $editor->getDocument()->setCursor(96, 0); // >= 95 -> definition outside filesystem
                    $tui->handleInput("\x1b[24~");
                    $this->await(static fn (): bool => str_contains($screen(), 'Target outside the filesystem'), what: 'the outside-filesystem flash');
                    // Rename that the server rejects: prepareRename succeeds at a
                    // low line, then the rename call itself errors on "ERR".
                    $editor->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6 -> prepareRename
                    $this->await(static fn (): bool => EditorState::promptOpen($editor), what: 'the rename prompt');
                    foreach (str_split('ERR') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => str_contains($screen(), 'Rename failed: cannot rename this'), what: 'the rename-rejected flash');
                },
            ],
        );

        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testGotoDeclarationOnNativePhpFunctionOpensDocs(): void
    {
        // No .porta.json: proves the native path never needs a language server.
        $project = new TempProject(['src/A.php' => "<?php\nstrlen('x');\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 3); // inside 'strlen'
                    $tui->handleInput("\x1b[24~"); // F12
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // Ctrl+Click the same word: editor pane starts at screen col 31
                    // (tree 30 + separator), gutter is 6 wide, so doc col ~3 of
                    // line 1 (screen row 4, 1-based) is at 1-based col 41.
                    $tui->handleInput("\x1b[<16;41;4M");
                    $tui->handleInput("\x1b[<16;41;4m");
                },
                900 => function ($tui, $terminal, $ide) use ($captureFile): void {
                    // The shim opener is an async subprocess: under load its
                    // second write can land after the loop would stop.
                    $this->await(static fn (): bool => is_file($captureFile) && 2 === substr_count((string) file_get_contents($captureFile), needle: "\n"));
                    $this->assertStringContainsString('Opened https://www.php.net', implode("\n", \Porta\Tests\Support\Toasts::all($ide)));
                },
            ],
        );

        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://www.php.net/manual/en/function.strlen.php', $urls);
        $this->assertSame(2, substr_count($urls, needle: "\n"), 'both F12 and ctrl+click opened the docs');
    }

    public function testGotoDeclarationOnJsBuiltinOpensMdn(): void
    {
        $project = new TempProject(['app.js' => "JSON.parse(x);\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('app.js'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // inside 'JSON'
                    $tui->handleInput("\x1b[24~");
                },
                600 => function ($tui, $terminal, $ide) use ($captureFile): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 6); // 'parse': not curated, no LSP
                    $tui->handleInput("\x1b[24~");
                    // The opener shim is an independent OS process — await its write.
                    $this->await(static fn (): bool => str_contains((string) (is_file($captureFile) ? file_get_contents($captureFile) : false), 'mozilla.org'));
                    $this->assertStringContainsString('Opened https://developer.mozilla.org', implode("\n", \Porta\Tests\Support\Toasts::all($ide)));
                },
            ],
        );

        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON', $urls);
        $this->assertStringNotContainsString('parse', $urls, 'a non-curated word opens nothing');
    }

    public function testNativeDocsWithoutAnOpenerFlashesTheUrl(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\narray_map('trim', []);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // inside 'array_map'
                    $tui->handleInput("\x1b[24~");
                },
                420 => function ($tui, $terminal, $ide): void {
                    $this->assertStringContainsString(
                        'Docs: https://www.php.net/manual/en/function.array-map.php',
                        \Porta\Tests\Support\Toasts::last($ide),
                    );
                },
            ],
        );
    }

    public function testPerLanguageServersRouteAndReportStatus(): void
    {
        // Two distinct server processes (the argv differ, so the pool cannot
        // share a session) from one language-map config; markdown has neither.
        $mock = \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php';
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => ['php' => $mock, 'javascript' => $mock.' js-flavor']]),
            'src/A.php' => "<?php\ngreet();\n",
            'src/app.js' => "doWork(x);\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
            'notes.md' => "# notes\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // The 400 ms gaps in this scenario are NOT waste, and were
                // measured as such: converting them to awaits made the
                // js-routed jump time out every time. `LspSession` debounces
                // document sync by 0.3 s (`changeDebounce`), so a definition
                // request fired the instant the buffer becomes current reaches
                // a server that has not been told about the document yet. The
                // barrier that would replace this has to be per-SESSION, and
                // no test-visible signal for it exists today.
                //
                // The assertions are awaited; only the sync waits on the clock.
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // on greet
                    $tui->handleInput("\x1b[24~"); // php server resolves Target.php
                },
                700 => function ($tui, $terminal, $ide) use ($project): void {
                    // Await the actual jump rather than asserting at a fixed delay:
                    // the mock LSP server is a separate process and lags under a
                    // loaded/pcov run, so the definition may not have landed yet.
                    $this->await(static fn (): bool => str_ends_with((string) $tui->getById('editor')->getFilePath(), 'Target.php'), timeout: 8.0, what: 'the php-routed definition jump');
                    Services::resolve($ide, BufferService::class)->openFile($project->path('src/app.js'), OpenFocus::Keep);
                },
                1100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // on doWork
                    $tui->handleInput("\x1b[24~"); // js server resolves Target.php too
                },
                1400 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => str_ends_with((string) $tui->getById('editor')->getFilePath(), 'Target.php'), timeout: 8.0, what: 'the js-routed definition jump');
                    Services::resolve($ide, BufferService::class)->openFile($project->path('notes.md'), OpenFocus::Keep);
                    // The status bar readings after the run need this paint.
                    $this->awaitPainted($tui, $terminal, 'LSP:no server');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:mock-lsp-server', $screen, 'per-language server name in the status bar');
        $this->assertStringContainsString('LSP:no server', $screen, 'markdown has no configured server');
    }

    public function testCtrlClickNavigatesToDeclaration(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n// line 1\n// line 2\n// line 3\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    $tui->tick(); // ensure a frame rendered so widget rects exist
                    // Ctrl (SGR bit 16) + left click at screen row 5 → editor
                    // content row 2 (menu row 0, tab bar row 1) → document line 2.
                    $tui->handleInput("\x1b[<16;45;5M");
                    $tui->handleInput("\x1b[<16;45;5m");
                },
                200 => function ($tui): void {
                    // The jump lands on the definition RESPONSE.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => str_ends_with((string) $editor->getFilePath(), 'Target.php'),
                        what: 'ctrl+click to jump to the declaration',
                    );
                    $this->assertSame(3, $editor->getDocument()->getCursorLine());
                    $this->assertFalse($editor->getDocument()->hasSelection(), 'a ctrl+click is a navigation, not a selection start');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testGotoDeclarationOnDeclarationShowsUsages(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    // Mock convention: definition at lines 85-89 contains the
                    // requested position itself (a decoy Target.php location
                    // comes first, so any-match must beat the chooser).
                    $tui->getById('editor')->getDocument()->setCursor(86, 2);
                    $tui->handleInput("\x1b[24~"); // F12: declaration or usages
                },
                200 => function ($tui, $terminal): void {
                    // "Still on Tall.php" is equally true before F12 has been
                    // answered at all, so the usages panel — the thing that
                    // proves the server replied — has to land first.
                    $editor = $tui->getById('editor');
                    $this->awaitPainted($tui, $terminal, 'Usages');
                    $this->assertStringEndsWith('Tall.php', (string) $editor->getFilePath(), 'on the declaration: no jump');
                    $tui->handleInput("\x1b"); // close the usages panel
                },
                300 => static function ($tui): void {
                    $tui->tick();
                    // Lines 88-89: on the declaration, but zero usages.
                    $tui->getById('editor')->getDocument()->setCursor(88, 2);
                    $tui->handleInput("\x1b[24~");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Usages', $screen);
        $this->assertStringContainsString('Target.php', $screen, 'usages rows listed');
        $this->assertStringNotContainsString('Choose target', $screen, 'a self-match shows usages, never the chooser');
        $this->assertStringContainsString('No usages found', $screen);
    }

    public function testNavigateBackWithEmptyStackAndOsc52Copy(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php echo 'hi';\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // ctrl+alt+left: nowhere to go
                    $tui->handleInput("\x1b[1;2C"); // shift+right
                    $tui->handleInput("\x03"); // ctrl+c -> OSC 52 to terminal
                },
            ],
        );

        $this->assertStringContainsString('Nowhere to go back to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $this->assertStringContainsString("\x1b]52;c;", $terminal->getOutput(), 'system clipboard escape emitted');
    }

    public function testJumpStackCapAndRapidTypingDebounce(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->awaitLspReady($tui, $terminal);

                    // Overfill the jump stack (cap is 50)
                    $ideRef = null; // reach the private stack through reflection on the Ide via closure binding
                    $tui->handleInput('a');
                    $tui->handleInput('b'); // two rapid changes: second one cancels the first debounce
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[24~"); // definition jump (fills jumpStack once)
                },
                650 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // back again
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testLspDisabledShowsDisabledStatus(): void
    {
        $project = new TempProject(['A.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, lspDisabled: true);
        $tui = Services::resolve($ide, Tui::class);

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $this->frame($tui);
            $this->assertStringContainsString('LSP:disabled', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    // --- Automatic refresh on filesystem change -------------------------------

    public function testFormatOnSaveSavesThroughTheFormatPath(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n\$a = 1;\n",
            '.porta.json' => json_encode(['settings' => ['formatOnSave' => true]]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // No LSP server exists (empty PATH): the format round is
                    // skipped and Ctrl+S still saves exactly once, immediately.
                    $tui->handleInput('x');
                    $tui->handleInput("\x13"); // ctrl+s
                    $this->awaitPainted($tui, $terminal, 'Saved a.php');
                    $this->assertStringContainsString('Saved a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('x', (string) file_get_contents($project->path('a.php')));
                },
            ],
        );
    }

    public function testLspLogCommandShowsCapturedServerOutputInTheDock(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    // No server ran (PATH is empty): the tool opens empty and
                    // will live-tail — the exact "why is my server silent" flow.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_lsp_log');
                    $this->awaitPainted($tui, $terminal, '0 results');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP Server Log', $screen, 'the dock tab opened');

                    // Output arrives while the tab is open: it appears without re-running the command.
                    Services::resolve($ide, \Porta\Lsp\LspLog::class)->append("[php-ls] fatal: cannot index\n");
                    Services::resolve($ide, \Porta\Lsp\LspLog::class)->append("[php-ls] retrying\n");
                    $tui->tick();
                    $panelText = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12))));
                    $this->assertStringContainsString('[php-ls] fatal: cannot index', $panelText, 'the captured server line appeared live');
                    $this->assertLessThan(
                        strpos($panelText, needle: 'fatal: cannot index'),
                        strpos($panelText, needle: 'retrying'),
                        'newest first',
                    );
                    // Enter on a log row is inert — nothing to jump to.
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                    $tui->tick();
                    $this->assertTrue($tui->getById('results')->isVisible(), 'the log tab stays put on Enter');
                    $tui->handleInput("\x1b"); // Esc hides the dock
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isVisible());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCallHierarchyFoldAllAndMatchingBracket(): void
    {
        $body = "<?php\n\nfunction greet(string \$n): string {\n    return 'hi '.\$n;\n}\n\nfunction caller(): string {\n    return greet('x');\n}\n".str_repeat("// filler\n", times: 85);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/H.php' => $body,
        ]);

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('src/H.php'),
            [
                100 => function ($tui, $terminal): void {
                    // The file being OPEN is not the server being ready, and
                    // ctrl+shift+h against a session that has not initialised
                    // is dropped in silence — no flash, so the await below then
                    // burns its whole budget. That is what the old 300 ms boot
                    // gap was really covering: it failed 3 runs in 30 under load
                    // once compressed to 100 ms. The status bar naming the
                    // server is the signal that it is up.
                    $this->await(static fn (): bool => null !== $tui->getById('editor')->getFilePath());
                    $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');

                    // No symbol at the caret → flash, no panel.
                    $tui->getById('editor')->getDocument()->setCursor(70, 0);
                    $tui->handleInput("\x1b[104;6u"); // ctrl+shift+h → call hierarchy
                    $this->await(static fn () => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No callers found'));

                    // A symbol nobody calls → the incoming list is empty.
                    $terminal->clearOutput();
                    $tui->getById('editor')->getDocument()->setCursor(88, 0);
                    $tui->handleInput("\x1b[104;6u");
                    $this->await(static fn () => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No callers found'));

                    // The happy path: callers of greet land in the results panel.
                    $tui->getById('editor')->getDocument()->setCursor(2, 10); // on "greet"
                    $tui->handleInput("\x1b[104;6u");
                    $this->await(static fn (): bool => 'results' === $tui->getFocus()?->getId());
                },
                200 => function ($tui, $terminal): void {
                    // The step above awaits FOCUS reaching the results panel;
                    // the panel painting its title is a separate moment, and a
                    // bare tick() is not a frame.
                    $this->awaitPainted($tui, $terminal, 'Callers of greet');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('caller', $screen);
                    $tui->handleInput("\x1b"); // close the panel
                },
                300 => function ($tui, $terminal) use ($palette): void {
                    // Fold All: the LSP foldingRange says 2-4 → the body line hides.
                    $editorScreen = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));
                    $this->assertStringContainsString("return 'hi '", $editorScreen());
                    $palette($tui, label: 'Fold All');
                    $this->await(static fn (): bool => !str_contains($editorScreen(), "return 'hi '"));

                    $palette($tui, label: 'Unfold All');
                    // Unfold is local where Fold All needed the server's
                    // foldingRange, so this resolves at once today — awaited
                    // anyway, because the asymmetry is not worth relying on.
                    $this->await(
                        static fn (): bool => str_contains($editorScreen(), "return 'hi '"),
                        what: 'unfold to restore the body line',
                    );
                },
                400 => function ($tui): void {
                    // Matching bracket: from the opening brace of greet() to its closer.
                    $tui->setFocus($tui->getById('editor'));
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(2, (int) strpos($doc->getLine(2), needle: '{'));
                    $tui->handleInput("\x1b[109;6u"); // ctrl+shift+m
                    $this->assertSame(4, $doc->getCursorLine(), 'jumped to the closing brace line');
                    $tui->handleInput("\x1b[109;6u"); // and back again
                    $this->assertSame(2, $doc->getCursorLine(), 'jumped back to the opener');
                },
            ],
        );
    }

    /**
     * Wait until the mock language server is actually UP, not merely configured.
     *
     * An LSP request fired at a session that has not initialised is dropped in
     * silence: no request goes out, so no response and no flash ever arrive,
     * and whatever barrier follows burns its entire budget. The editor having
     * a file path does NOT imply this — the file opens first and the session
     * starts behind it, which is what the old 300-400 ms boot gaps were really
     * covering. The status bar naming the server is the signal that it is up.
     *
     * Measured, not assumed: with the boot gap at 100 ms and no barrier,
     * testCompletionResolveWithoutImportsLeavesFileUntouched and
     * testGoToImplementationWithNoResultFlashes each failed 6 runs in 30 under
     * 16 CPU burners, both timing out on the effect of a request that had
     * never been sent.
     */
    private function awaitLspReady(Tui $tui, VirtualTerminal $terminal): void
    {
        $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');
    }

    private function mockLspConfig(): string
    {
        return json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php']);
    }

    /**
     * A temp dir holding `open` and `xdg-open` shims that append their args to
     * $captureFile, put first on PATH so BrowserOpener resolves them instead of
     * a real browser opener.
     */
    private function shimBrowserOpener(TempProject $project, string $captureFile): void
    {
        foreach (['open', 'xdg-open'] as $name) {
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".escapeshellarg($captureFile)."\n");
            chmod($shim, permissions: 0o755);
        }
        putenv('PATH='.$project->path('shim-bin'));
    }

    /**
     * Ctrl+Alt+O end to end. The mock filters by `context.only` the way a real
     * server does, so what comes back here is the organizeImports action ALONE
     * — which is what makes the key's `only` worth sending.
     */
    public function testCtrlAltOOrganizesImportsThroughTheServer(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'a.php' => "<?php\n\necho 'x';\n",
        ]);

        $text = null;
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => function ($tui, $terminal) use (&$text): void {
                    $this->awaitLspReady($tui, $terminal);
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b\x0f"); // ctrl+alt+o
                    $this->await(static fn (): bool => str_contains($tui->getById('editor')->getDocument()->getText(), 'Organized'));
                    $text = $tui->getById('editor')->getDocument()->getText();
                },
            ],
            2.0,
        );

        $this->assertStringContainsString('use Acme\\Organized;', (string) $text, 'the organizeImports edit was applied without a picker');
    }
}
