<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FindModalReads;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * The find-in-files modal and results pane UI: carets, wheel
 * scrolling, filters, replace flows and option tags.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeFindUiTest extends TestCase
{
    use ClearsPortaLsp;
    use FindModalReads;
    use PreservesPath;
    use RunsIde;

    public function testFindModalShowsTheCaretInTheFocusedFieldOverAFile(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$x = 1;\n\$y = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('src/A.php'), // a file is open → the editor is focused and draws its own caret
            [
                200 => function ($tui): void {
                    // While editing, the editor owns the single caret.
                    $this->assertSame(1, $this->caretMarkerCount($this->editorRows($tui)), 'the editor caret shows while editing');

                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → open the modal
                    $tui->tick();
                    $rows = $this->editorRows($tui);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'exactly one caret while the modal is open');
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertStringContainsString('Find:', AnsiUtils::stripAnsiCodes($rows[$caretRow]), 'the caret sits in the Find field, not the editor');

                    // Tab to the Replace field and type — the caret follows the text
                    // (replace edits re-render the preview only; no project re-search).
                    $tui->handleInput("\t");
                    $tui->handleInput('x');
                    $rows = $this->editorRows($tui);
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'still a single caret');
                    $this->assertStringContainsString('Replace: x', AnsiUtils::stripAnsiCodes($rows[$caretRow]), 'the caret moved to the Replace field');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindModalCaretShowsEvenWhenOpenedFromAnUnfocusedEditor(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok\n"]);

        $this->runIde(
            $project,
            null, // no file open → the tree is focused, the editor pane is not
            [
                200 => function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    $tui->tick();
                    $rows = $this->editorRows($tui);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'the modal caret shows even though the editor pane is unfocused');
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertStringContainsString('Find:', AnsiUtils::stripAnsiCodes($rows[$caretRow]));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheModalPreview(): void
    {
        $content = '<?php';
        for ($i = 0; $i < 20; ++$i) {
            $content .= "\n// tok {$i}";
        }
        $project = new TempProject(['src/A.php' => $content."\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → the file content never bleeds behind the modal
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (\str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui): void {
                    // The grep is a subprocess: await the matches landing
                    // instead of a fixed gap.
                    $this->await(fn (): bool => \str_contains($this->modalText($tui), 'tok 0'), what: 'the matches to land');
                    $before = $this->modalText($tui);
                    $this->assertStringContainsString('tok 0', $before, 'the first matches show initially');
                    $this->assertStringNotContainsString('tok 18', $before, 'later matches start below the fold');

                    // Wheel down over the editor pane scrolls the modal preview, not the editor.
                    for ($i = 0; $i < 6; ++$i) {
                        $tui->handleInput("\x1b[<65;80;10M"); // SGR wheel-down at col 80, row 10
                    }
                    $this->assertStringContainsString('tok 18', $this->modalText($tui), 'the wheel scrolled the preview to later matches');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheResultsToolWindow(): void
    {
        $files = [];
        for ($i = 0; $i < 20; ++$i) {
            $files["src/File{$i}.php"] = "<?php\n// tok\n";
        }
        $project = new TempProject($files);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(\implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            null,
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (\str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui) use ($panelText): void {
                    $this->promote($tui); // Alt+Enter → results tool window
                    // The grep is a subprocess: await the matches landing
                    // instead of a fixed gap.
                    $this->await(static fn (): bool => \str_contains($panelText($tui), 'File0.php'), what: 'the matches to land');
                    $this->assertStringContainsString('File0.php', $panelText($tui), 'the first files show initially');
                },
                900 => function ($tui, $terminal, $ide) use ($panelText): void {
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('results'));
                    $this->assertNotNull($rect, 'the results pane has a rect');

                    // Wheel down over the pane scrolls its list past the first files.
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $rect->col + 3, $rect->row + 2));
                    }
                    $this->assertStringNotContainsString('File0.php', $panelText($tui), 'the wheel scrolled the first files out of view');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectFilterNarrowsLoadedResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(\implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (\str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    // Await in the MODAL — the results pane holds nothing until
                    // promote() moves the rows onto it.
                    $this->await(fn (): bool => \str_contains($this->modalText($tui), 'other/B.php'), what: 'both search hits to land');
                    $this->promote($tui); // modal → results pane (the filter field lives on the pane)
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/A.php', $panel, 'both files match');
                    $this->assertStringContainsString('other/B.php', $panel);
                    // Tab query → mask → filter, then narrow to the src/ tree.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (\str_split('src') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('· 1 shown', $tui->getById('results')->statusText(), 'filtered count');
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('other/B.php', $panel, 'the non-matching file is filtered out');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** The find bar's single row, as text. */
    private function findBarRow(Tui $tui): string
    {
        return AnsiUtils::stripAnsiCodes(
            \implode('', $tui->getById('find-bar')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))),
        );
    }

    public function testCtrlFPrefillsWithTheWordTheCaretIsIn(): void
    {
        // PhpStorm's rule: the selection if there is one, the identifier the
        // caret stands in otherwise. Porta only ever did the first half, so
        // Ctrl+F on a symbol meant retyping the symbol.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$total = 1;\necho \$total + \$total;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 3); // inside $total
                    $tui->handleInput("\x06"); // ctrl+f

                    // The sigil comes with it, and the count proves the field
                    // really ran the search rather than merely showing text.
                    $this->awaitPainted($tui, $terminal, 'Find: $total');

                    // Three matches, because opening with a query SEARCHES —
                    // the prefill is not just text in a field. Which one is
                    // current is MatchJump's existing nearest-match rule (the
                    // caret sits inside the first, so the next one is), and not
                    // this feature's business to pin.
                    $this->assertStringContainsString(
                        '/3]',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'and it searched for it',
                    );
                    $tui->handleInput("\x1b"); // esc
                },
                320 => function ($tui): void {
                    // A DIFFERENT word replaces the last query rather than
                    // appending to it or being ignored.
                    //
                    // Read from the WIDGET, not the terminal: the terminal's
                    // output accumulates the whole session, so a negative
                    // assertion against it finds an earlier frame and passes or
                    // fails for the wrong reason.
                    $tui->getById('editor')->getDocument()->setCursor(2, 1); // inside `echo`
                    $tui->handleInput("\x06");
                    $tui->requestRender();
                    $tui->tick();

                    $this->assertStringContainsString('Find: echo', $this->findBarRow($tui));
                },
                420 => function ($tui): void {
                    // A caret standing in NO word contributes nothing, so the
                    // field keeps the query it had — reopening the bar has
                    // always remembered one, and prefilling must not take that
                    // away.
                    //
                    // Column 12 is the `+`. Column 11 is the SPACE after
                    // `$total`, which is the END of that word and therefore
                    // inside it — the same rule CaretWordTest pins, and an easy
                    // off-by-one to write into a test and then misread as the
                    // bar forgetting things.
                    $tui->handleInput("\x1b"); // esc
                    $tui->getById('editor')->getDocument()->setCursor(2, 12); // on the `+`
                    $tui->handleInput("\x06");
                    $tui->requestRender();
                    $tui->tick();

                    $this->assertStringContainsString(
                        'Find: echo',
                        $this->findBarRow($tui),
                        'the operator prefilled nothing, so the last query stands',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testClickingTheFindBarsOptionTagTogglesIt(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// foo foobar foo\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->handleInput("\x06"); // ctrl+f
                    foreach (\str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->awaitPainted($tui, $terminal, '[1/3]');
                    $this->assertStringContainsString('[1/3]', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'substring mode hits foobar too');

                    // Click the right-aligned [\b] tag through the real router.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('find-bar'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $rect->columns - 2, $rect->row + 1));
                },
                300 => function ($tui, $terminal): void {
                    // The re-run count is what the toggle produces; awaiting it
                    // is awaiting the effect, not a gap after the click.
                    $this->awaitPainted($tui, $terminal, '[1/2]');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('[\\b]', $screen, 'and the tag reads as enabled without relying on colour');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testProjectSearchRegexToggle(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$noodle = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f -> open project search
                },
                400 => static function ($tui): void {
                    foreach (\str_split('n..dle') as $ch) {
                        $tui->handleInput($ch); // a regex; as a literal it matches nothing
                    }
                },
                750 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, '0 results');
                    $this->assertStringContainsString('0 results', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1br"); // alt+r -> enable regex, re-runs the search
                },
                1150 => function ($tui, $terminal): void {
                    // Enabling regex re-runs the grep subprocess: await the
                    // widened result instead of a fixed gap.
                    $this->awaitPainted($tui, $terminal, '2 results');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('2 results', $screen, 'regex n..dle matches needle and noodle');
                    $this->assertStringContainsString('A.php', $screen);
                },
            ],
        );
    }

    public function testProjectReplaceAcrossOpenAndDiskFiles(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // opened (active buffer)
            'src/B.php' => "<?php\n\$foo = 2;\n", // only on disk
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (\str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui): void {
                    // The grep is a subprocess: await its match landing in the
                    // MODAL before acting on it, instead of a fixed gap. (The
                    // query field itself already echoes "foo", so await the
                    // file path — only the results preview carries it.)
                    $this->await(fn (): bool => \str_contains($this->modalText($tui), 'B.php'), what: 'the search to land');
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (\str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a -> replace all
                },
                1000 => function ($tui) use ($project): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('$bar = 1;', $doc->getText(), 'open buffer replaced (undoable)');
                    $this->assertStringContainsString('$bar = 2;', (string) \file_get_contents($project->path('src/B.php')), 'on-disk file replaced');
                },
            ],
        );
    }

    public function testProjectReplaceInASingleFileFlashesSingular(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // the only file containing the match
            'src/B.php' => "<?php\n\$bar = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (\str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (\str_split('baz') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a -> replace all
                },
                1000 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Replaced in 1 file');
                    $this->assertStringContainsString('Replaced in 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testProjectReplaceWithNothingFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // open the modal
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput("\x1ba"); // replace all with no query/results
                },
                500 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Nothing to replace');
                    $this->assertStringContainsString('Nothing to replace', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testProjectReplacePreviewExcludesSelectedMatch(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$foo = 1;\n\$foo = 2;\n"]); // two matching lines
        $panelText = $this->modalText(...); // replace preview + exclude happen in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (\str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    // The grep is a subprocess: await its rows before driving
                    // the preview, instead of assuming 400ms was enough.
                    $this->await(static fn (): bool => \str_contains($panelText($tui), 'A.php'), what: 'the project search to land');
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (\str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('→', $panel, 'preview shows old → new');
                    $this->assertStringContainsString('[x]', $panel, 'kept matches are checked');
                    // Select the second match (Down past the first) and exclude it.
                    $tui->handleInput("\x1b[B"); // down → second match
                    $tui->handleInput("\x1b[3~"); // delete → exclude this line
                    $this->assertStringContainsString('(excluded)', $panelText($tui), 'excluded line is marked');
                    $tui->handleInput("\x1ba"); // alt+a → replace kept
                },
                1000 => function ($tui): void {
                    $tui->tick();
                    $text = $tui->getById('editor')->getDocument()->getText();
                    $this->assertStringContainsString('$bar = 1;', $text, 'kept match replaced');
                    $this->assertStringContainsString('$foo = 2;', $text, 'excluded match left intact');
                    $this->assertStringNotContainsString('$bar = 2;', $text);
                },
            ],
        );
    }

    public function testMouseClicksAndHoversSearchPanels(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n", 'c.php' => "<?php // c\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $results = $tui->getById('results');
                    $results->open('Usages', ResultsPanelFields::resultsOnly());
                    $results->setResults([
                        ['path' => 'b.php', 'line' => 0, 'text' => 'one'],
                        ['path' => 'c.php', 'line' => 0, 'text' => 'two'],
                    ]);
                    $tui->requestRender();
                    $tui->tick(); // force a frame so the panel gets a positioned rect
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($results);
                    // A list tool has no header row; the second result is at relative row 1.
                    $x = $rect->col + 6;
                    $y = $rect->row + 2; // 1-based screen row of relative row 1
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x, $y)); // hover the second result
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y)); // click it
                },
                340 => function ($tui): void {
                    $this->assertStringEndsWith('c.php', (string) $tui->getById('editor')->getFilePath(), 'clicked the second result');
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
