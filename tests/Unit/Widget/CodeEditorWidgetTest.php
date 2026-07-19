<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\FindMode;
use Porta\Editor\SelectionMode;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\FakeBufferNavigation;
use Porta\Tests\Support\FakeEditorCompletion;
use Porta\Tests\Support\FakeEditorFileActions;
use Porta\Tests\Support\FakeEditorNavigation;
use Porta\Tests\Support\FakeEditorRefactor;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class CodeEditorWidgetTest extends TestCase
{
    private function editor(string $content = "<?php\n\necho 'hi';\n", string $path = 'demo.php'): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testCaretScreenCellTracksCursorScrollAndGutter(): void
    {
        $editor = $this->editor("<?php\n\$a = 1;\n\$bb = 22;\n");
        $editor->render(new RenderContext(80, 10));

        $editor->getDocument()->setCursor(0, 0);
        $cell = $editor->caretScreenCell();
        $this->assertSame(0, $cell['row']);
        $this->assertSame($editor->gutterWidth(), $cell['col'], 'column 0 sits right after the gutter');

        $editor->getDocument()->setCursor(2, 4);
        $cell = $editor->caretScreenCell();
        $this->assertSame(2, $cell['row']);
        $this->assertSame($editor->gutterWidth() + 4, $cell['col']);
    }

    public function testCaretScreenCellClampsToTheGutterWhenScrolledPastTheCaret(): void
    {
        $long = "<?php\n\$x = '".str_repeat('a', times: 120)."';\n";
        $editor = $this->editor($long);
        $editor->getDocument()->setCursor(1, 100);
        $editor->render(new RenderContext(40, 10)); // narrow: forces hScroll to follow the caret

        $editor->getDocument()->setCursor(1, 0); // move back without re-rendering
        $this->assertSame($editor->gutterWidth(), $editor->caretScreenCell()['col'], 'a caret left of the viewport clamps to the gutter edge');
    }

    /** @param string[] $lines */
    private function assertExactWidth(array $lines, int $width): void
    {
        foreach ($lines as $i => $line) {
            $this->assertSame($width, AnsiUtils::visibleWidth($line), "row {$i} must be exactly {$width} columns");
        }
    }

    public function testRenderContract(): void
    {
        $editor = $this->editor();
        $rendered = $editor->render(new RenderContext(80, 20));

        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);
        $this->assertStringContainsString('1', AnsiUtils::stripAnsiCodes($rendered[0]), 'gutter shows line number');
        $this->assertStringContainsString("\x1b_pi:c;", $rendered[0], 'cursor marker present when focused');
    }

    private function stickyEditor(): CodeEditorWidget
    {
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".str_repeat("        \$x = 1;\n", times: 20)."    }\n}\n";

        return $this->editor($code, 'Service.php');
    }

    public function testStickyScrollPinsEnclosingHeaders(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8); // deep inside handle(), headers scrolled off
        $rendered = $editor->render(new RenderContext(60, 12));

        $this->assertExactWidth($rendered, 60);
        $top = AnsiUtils::stripAnsiCodes($rendered[0]);
        $second = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('class Service', $top, 'class header pinned at row 0');
        $this->assertStringContainsString('function handle', $second, 'method header pinned at row 1');
        $this->assertStringContainsString("\x1b[48;5;237m", $rendered[0], 'pinned band background');
        $this->assertStringNotContainsString('namespace', $top.$second, 'the namespace is not pinned');
        $this->assertStringContainsString("\x1b_pi:c;", implode('', $rendered), 'the caret is still visible below the band');
    }

    public function testStickyHeaderClickJumpsToDeclaration(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12)); // pins class@line4, handle@line6

        $editor->clickAt(0, 10); // click the pinned class header
        $this->assertSame(4, $editor->getDocument()->getCursorLine(), 'jumped to the class declaration');

        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12));
        $editor->clickAt(1, 10); // click the pinned method header
        $this->assertSame(6, $editor->getDocument()->getCursorLine(), 'jumped to the method declaration');
    }

    public function testClickOnContentRowResolvesTheScrolledLine(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12)); // 2 sticky rows; content starts at row 2

        $editor->clickAt(2, 10); // first content row, below the pinned band
        $this->assertGreaterThan(6, $editor->getDocument()->getCursorLine(), 'a content-row click resolves a scrolled line, not a header');
    }

    public function testNoStickyRowsForStructurelessFile(): void
    {
        $editor = $this->editor("<?php\n".str_repeat("echo 1;\n", times: 30), 'flat.php');
        $editor->getDocument()->setCursor(25, 0);
        $rendered = $editor->render(new RenderContext(60, 12));

        $this->assertStringNotContainsString("\x1b[48;5;237m", $rendered[0], 'no scopes → no pinned band');
    }

    public function testNoFileOpenShowsCenteredHintNotAnEmptyFile(): void
    {
        $editor = new CodeEditorWidget(); // no file opened → filePath is null
        $editor->setFocused(true);

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $rendered));
        $this->assertStringContainsString('No file open', $plain, 'placeholder hint is shown');
        $this->assertStringContainsString('Ctrl+P', $plain);
        $joined = implode('', $rendered);
        $this->assertStringNotContainsString("\x1b_pi:c;", $joined, 'no cursor marker when no file is open');

        // Opening a file restores the normal document render (gutter + cursor).
        $editor->openFile('demo.php', "<?php\n");
        $withFile = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b_pi:c;", $withFile[0], 'cursor returns once a file is open');
        $this->assertStringNotContainsString('No file open', AnsiUtils::stripAnsiCodes(implode("\n", $withFile)));
    }

    public function testMenuOverlayCompositesOverEditorRows(): void
    {
        $editor = $this->editor();
        $editor->setMenuOverlay([
            ['row' => 0, 'col' => 3, 'width' => 10, 'segment' => "\x1b[7m Paste    \x1b[0m"],
            ['row' => 2, 'col' => 3, 'width' => 10, 'segment' => "\x1b[7m Find     \x1b[0m"],
        ]);

        $rendered = $editor->render(new RenderContext(80, 20));

        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $rendered));
        $this->assertStringContainsString('Paste', $plain, 'dropdown item floats over the editor');
        $this->assertStringContainsString('Find', $plain);
    }

    public function testEmptyMenuOverlayLeavesEditorUnchanged(): void
    {
        $editor = $this->editor();
        $baseline = $editor->render(new RenderContext(80, 20));

        $editor->setMenuOverlay([]);
        $this->assertSame($baseline, $editor->render(new RenderContext(80, 20)), 'no overlay → identical output');
    }

    public function testTypingAndBackspace(): void
    {
        $editor = $this->editor();

        $editor->handleInput('X');
        $this->assertStringStartsWith('X<?php', $editor->getDocument()->getLine(0));

        $editor->handleInput("\x7f"); // backspace
        $this->assertStringStartsWith('<?php', $editor->getDocument()->getLine(0));
    }

    public function testArrowMovement(): void
    {
        $editor = $this->editor();
        $editor->handleInput("\x1b[B"); // down

        $this->assertSame(1, $editor->getDocument()->getCursorLine());
    }

    public function testCompletionPopupLifecycle(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);

        $this->assertTrue($editor->isCompletionOpen());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString('echoFirst', implode("\n", $rendered));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput("\x1b[B"); // select second
        $editor->handleInput("\r"); // accept

        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionAppliesInlineAdditionalTextEdits(): void
    {
        // <?php / (blank) / echo 'hi'; — complete on the last line, import lands on line 1.
        $editor = $this->editor();
        $doc = $editor->getDocument();
        $doc->setCursor(2, 4); // after "echo"

        $editor->showCompletions([new CompletionItem(
            label: 'str_replace',
            insertText: 'str_replace',
            kind: 'fn',
            detail: '',
            additionalTextEdits: [[
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => "use function str_replace;\n",
            ]],
        )]);
        $editor->handleInput("\r"); // accept

        $this->assertSame('use function str_replace;', $doc->getLine(1), 'auto-import inserted above');
        $this->assertStringContainsString('str_replace', $doc->getLine(3), 'completion applied at caret');
        $this->assertFalse($editor->isCompletionOpen());

        // The whole accept (insert + import) is a single undo step.
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertStringNotContainsString('use function str_replace;', $doc->getText());
    }

    public function testCompletionWithoutInlineEditsRequestsResolve(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 4);

        $resolved = null;
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                resolveCompletion: static function (array $item) use (&$resolved): void {
                    $resolved = $item;
                },
            ),
        ));

        $editor->showCompletions([new CompletionItem(
            label: 'strlen',
            insertText: 'strlen',
            kind: 'fn',
            detail: '',
            raw: ['label' => 'strlen', 'data' => 'strlen'],
        )]);
        $editor->handleInput("\r"); // accept

        $this->assertNotNull($resolved, 'resolve callback fired for deferred imports');
        $this->assertSame('strlen', $resolved['data']);
    }

    public function testCompletionEscapeCloses(): void
    {
        $editor = $this->editor();
        $editor->showCompletions([new CompletionItem(label: 'foo', insertText: 'foo', kind: 'fn', detail: '')]);
        $this->assertTrue($editor->isCompletionOpen());

        $editor->handleInput("\x1b");
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testIdentifierOccurrencesHighlighted(): void
    {
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n");
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3); // inside $total on line 1

        $rendered = $editor->render(new RenderContext(80, 10));
        // Both occurrences of $total pick up the occurrence background.
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[1]);
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[2]);

        // A selection suppresses the highlight.
        $doc->setCursor(1, 1);
        $doc->beginSelect();
        $doc->setCursor(1, 6, selection: SelectionMode::Keep);
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", $rendered[2]);
    }

    public function testSingleOccurrenceNotHighlighted(): void
    {
        $editor = $this->editor("<?php\n\$only = 1;\necho 2;\n");
        $editor->getDocument()->setCursor(1, 3); // inside $only (appears once)
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", implode('', $rendered));
    }

    public function testOccurrenceHighlightSkipsShortAndNumericTokens(): void
    {
        // Single-letter variable "$i" (too short) is not highlighted even though
        // it repeats; nor is the numeric token "11".
        $editor = $this->editor("<?php\n\$i = 11;\necho \$i + 11;\n");

        $editor->getDocument()->setCursor(1, 1); // on $i
        $short = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", implode('', $short));

        $editor->getDocument()->setCursor(1, 6); // on 11
        $numeric = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", implode('', $numeric));
    }

    public function testIndentGuidesDrawnAtEachLevel(): void
    {
        // 8-space (two-level) indentation gets guides at columns 0 and 4.
        $editor = $this->editor("<?php\nif (true) {\n        deep();\n");
        $rendered = $editor->render(new RenderContext(80, 10));

        $line1 = AnsiUtils::stripAnsiCodes($rendered[1]); // "if (true) {" — no indent
        $line2 = AnsiUtils::stripAnsiCodes($rendered[2]); // "        deep();" — 2 levels

        $this->assertStringNotContainsString('│', $line1, 'unindented line has no guide');
        $this->assertSame(2, substr_count($line2, needle: '│'), 'a guide per indent level');
        $this->assertStringContainsString('deep();', $line2);
    }

    public function testInlayHintsRenderInlineAndShiftCaret(): void
    {
        $editor = $this->editor("<?php\n\$x = f();\n");
        $doc = $editor->getDocument();
        // Hints at the very start (col 0), mid-line (col 2) and end (append).
        $editor->setInlayHints([
            1 => [
                ['col' => 0, 'text' => '»'],
                ['col' => 2, 'text' => ': int'],
                ['col' => 9, 'text' => '⟵end'],
            ],
        ]);
        $doc->setCursor(1, 9); // end of line, after the mid hint

        $plain = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 6))[1]);
        $this->assertStringContainsString('»', $plain, 'hint at column 0');
        $this->assertStringContainsString(': int', $plain, 'mid-line type hint');
        $this->assertStringContainsString('⟵end', $plain, 'appended hint at end of line');
        // The real code is still present around the hints.
        $this->assertStringContainsString('f();', $plain);
    }

    public function testDiagnostics(): void
    {
        $editor = $this->editor();
        $editor->setDiagnostics([2 => [['severity' => 1, 'message' => 'Boom', 'col' => 0]]]);

        $counts = $editor->getDiagnosticCounts();
        $this->assertSame(1, $counts['errors']);
        $this->assertSame(0, $counts['warnings']);

        $rendered = $editor->render(new RenderContext(80, 20));
        // The left gutter shows the severity glyph in the marker column and the
        // line number colored red; the stripe repeats it as a density block.
        $this->assertStringContainsString('✗', AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($rendered[2], 0, 10)), 'error glyph in the left gutter');
        $this->assertStringContainsString("\x1b[38;5;203m", $rendered[2], 'line number colored for the error');
        $stripe = '';
        foreach ($rendered as $row) {
            $stripe .= AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($row, 79, 1));
        }
        $this->assertStringContainsString('█', $stripe, 'error block in the right stripe');

        $editor->getDocument()->setCursor(2, 0);
        $this->assertSame('Boom', $editor->getCursorDiagnostic());
    }

    public function testTabIndentsAndShiftTabOutdentsSelection(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n", 'indent.php');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 0, selection: SelectionMode::Keep); // selection spans lines 0..1

        $editor->handleInput("\t"); // Tab indents the block instead of replacing it
        $this->assertSame('    aaa', $doc->getLine(0));
        $this->assertSame('    bbb', $doc->getLine(1));
        $this->assertSame('ccc', $doc->getLine(2), 'the line past the selection end is untouched');

        $editor->handleInput("\x1b[Z"); // Shift+Tab outdents
        $this->assertSame('aaa', $doc->getLine(0));
        $this->assertSame('bbb', $doc->getLine(1));
    }

    public function testTabWithoutSelectionStillInsertsIndentUnit(): void
    {
        $editor = $this->editor("x\n", 'plain.php');
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\t");
        $this->assertSame('    x', $editor->getDocument()->getLine(0), 'no selection → Tab inserts a unit as before');
    }

    public function testSelectionCopyPasteDuplicateDelete(): void
    {
        $editor = $this->editor("<?php\necho 'hi';\n", 'clip.php');
        $editor->getDocument()->setCursor(1, 0);

        for ($i = 0; $i < 4; ++$i) {
            $editor->handleInput("\x1b[1;2C"); // shift+right
        }
        $this->assertSame('echo', $editor->getDocument()->getSelectedText());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b[48;5;24m", $rendered[1], 'selection background rendered');

        $copied = null;
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                copy: static function (string $text) use (&$copied): void {
                    $copied = $text;
                },
            ),
        ));
        $editor->handleInput("\x03"); // ctrl+c
        $this->assertSame('echo', $copied);

        $editor->getDocument()->setCursor(2, 0);
        $editor->handleInput("\x16"); // ctrl+v
        $this->assertSame('echo', $editor->getDocument()->getLine(2));

        $editor->handleInput("\x04"); // ctrl+d duplicate
        $this->assertSame('echo', $editor->getDocument()->getLine(3));

        $editor->handleInput("\x19"); // ctrl+y delete line
        $this->assertSame(3, $editor->getDocument()->getLineCount());
    }

    public function testCurrentLineAndBracketDecorations(): void
    {
        $editor = $this->editor("<?php\nfunction f(array \$a): void\n{\n    echo \$a[0];\n}\n", 'deco.php');
        $editor->getDocument()->setCursor(2, 0); // on the opening brace

        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2]);
        $bracketRows = $rendered[2]."\n".$rendered[4];
        $this->assertSame(2, substr_count($bracketRows, LineDecorator::BRACKET_SGR), 'both brackets highlighted');
        $this->assertExactWidth($rendered, 80);
    }

    public function testDiagnosticRangeUnderline(): void
    {
        $editor = $this->editor("<?php\nfunction f(array \$a): void\n{\n}\n", 'diag.php');
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'Bad type', 'col' => 11, 'endCol' => 16]]]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[4:3m", $rendered[1], 'curly underline');
        $this->assertStringContainsString("\x1b[58;5;203m", $rendered[1], 'red underline color for the error');
        $this->assertExactWidth($rendered, 80);
    }

    public function testDiagnosticSeverityStylingWorstFirst(): void
    {
        // line 1: an error and a warning share the line → error (worst) wins the
        // gutter/inline; line 2: a lone info; line 3: a lone hint.
        $editor = $this->editor("<?php\n\$a = f();\n\$b = g();\n\$c = h();\n", 'sev.php');
        $editor->setDiagnostics([
            1 => [
                ['severity' => 2, 'message' => 'maybe null', 'col' => 5, 'endCol' => 8],
                ['severity' => 1, 'message' => 'undefined f', 'col' => 5, 'endCol' => 8],
            ],
            2 => [['severity' => 3, 'message' => 'unused', 'col' => 0, 'endCol' => 2]],
            3 => [['severity' => 4, 'message' => 'style', 'col' => 0, 'endCol' => 2]],
        ]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertExactWidth($rendered, 80);

        // Line-number color by worst severity per line (no icon in the left gutter).
        $this->assertStringContainsString("\x1b[38;5;203m", $rendered[1], 'error color (worst of error+warning)');
        $this->assertStringContainsString("\x1b[38;5;75m", $rendered[2], 'info color');
        $this->assertStringContainsString("\x1b[38;5;244m", $rendered[3], 'hint color');

        // Each diagnostic underlined in its own severity color.
        $this->assertStringContainsString("\x1b[58;5;203m", $rendered[1], 'error underline color');
        $this->assertStringContainsString("\x1b[58;5;179m", $rendered[1], 'warning underline color');
        $this->assertStringContainsString("\x1b[58;5;75m", $rendered[2], 'info underline color');

        // The stripe carries a block whose density encodes the worst severity,
        // mapped to its proportional row.
        $this->assertStringContainsString("\x1b[38;5;203m█", implode('', $rendered), 'error: full block, colored red, in the stripe');
        $this->assertStringContainsString("\x1b[38;5;75m▒", implode('', $rendered), 'info: medium-shade block in the stripe');
        $this->assertStringContainsString("\x1b[38;5;244m░", implode('', $rendered), 'hint: light-shade block in the stripe');

        // The gutter marker column repeats the severity as a glyph.
        $this->assertStringContainsString('✗', AnsiUtils::stripAnsiCodes($rendered[1]), 'error glyph in the gutter');
        $this->assertStringContainsString('◦', AnsiUtils::stripAnsiCodes($rendered[2]), 'info glyph in the gutter');
        $this->assertStringContainsString('·', AnsiUtils::stripAnsiCodes($rendered[3]), 'hint glyph in the gutter');

        // Inline message on line 1 shows the worst (error) message + a "+1" count.
        $plain1 = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('undefined f', $plain1, 'worst-severity message inline');
        $this->assertStringContainsString('+1', $plain1, 'count of the other diagnostic on the line');
    }

    public function testErrorStripeShowsWholeFileAndClickJumps(): void
    {
        $src = "<?php\n".str_repeat("\$x = 1;\n", times: 60);
        $editor = $this->editor($src, 'stripe.php');
        $editor->setDiagnostics([
            5 => [['severity' => 1, 'message' => 'err', 'col' => 0, 'endCol' => 2]],
            55 => [['severity' => 2, 'message' => 'warn', 'col' => 0, 'endCol' => 2]],
            30 => [], // an empty list marks nothing (defensive)
        ]);

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertExactWidth($rendered, 80);

        // The stripe (last column) marks both problems even though line 55 is off-screen.
        $all = implode('', $rendered);
        $this->assertStringContainsString("\x1b[38;5;203m\x1b[48;5;236m█", $all, 'error block in the stripe, over the viewport thumb');
        $this->assertStringContainsString("\x1b[38;5;179m▓", $all, 'off-screen warning shown as a dashed amber block');
        $this->assertSame(1, substr_count($all, needle: '█'), 'the empty list draws no stripe mark');
        $this->assertSame(1, substr_count($all, needle: '▓'), 'one warning mark');

        // Clicking low in the stripe jumps deep into the file (near the warning).
        $editor->clickAt(18, 79);
        $this->assertGreaterThan(40, $editor->getDocument()->getCursorLine(), 'stripe click jumps to a proportional file line');

        // A press (drag start) in the stripe also jumps instead of starting a selection.
        $editor->beginSelectionAt(1, 79);
        $this->assertLessThan(10, $editor->getDocument()->getCursorLine(), 'stripe press near the top jumps up');
        $this->assertFalse($editor->getDocument()->hasSelection(), 'no selection started from a stripe press');
    }

    public function testSearchMatchHighlights(): void
    {
        $editor = $this->editor("<?php\necho 'needle';\n", 'search.php');
        $editor->setSearchMatches([1 => [['start' => 6, 'end' => 12, 'current' => true]]]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[48;5;130m", $rendered[1], 'current match background');
        $this->assertExactWidth($rendered, 80);
    }

    public function testListOverlayNavigationAndPick(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => 'src/a.php', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => 'src/b.php', 'value' => 'B'],
            ],
            static function (string $value) use (&$picked): void {
                $picked = $value;
            },
        );

        $this->assertTrue($editor->isOverlayOpen());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString('Recent Files', implode("\n", $rendered));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput("\x1b[B"); // down
        $editor->handleInput("\r"); // pick
        $this->assertSame('B', $picked);
        $this->assertFalse($editor->isOverlayOpen());
    }

    public function testListOverlayEscapeCloses(): void
    {
        $editor = $this->editor();
        $editor->openListOverlay('X', [['label' => 'a', 'hint' => '', 'value' => 'a']], static fn () => null);

        $editor->handleInput("\x1b");
        $this->assertFalse($editor->isOverlayOpen());
    }

    /** @return list<SearchRow> */
    private function searchRows(): array
    {
        return [
            SearchRow::header(SearchCategory::Files),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'alpha.php', 'src', static fn () => null)),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'beta.php', 'src', static fn () => null)),
        ];
    }

    public function testSearchOverlayKeyboardPassThrough(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openSearch(
            'Search Everywhere',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $this->assertTrue($editor->isSearchOverlayOpen());
        $editor->setSearchRows($this->searchRows());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString('Search Everywhere', implode("\n", $rendered));
        $this->assertExactWidth($rendered, 80);

        $editor->handleSearchOverlayInput("\x1b[B"); // down → beta.php
        $editor->handleSearchOverlayInput("\r"); // pick
        $this->assertSame('beta.php', $picked);

        $editor->closeSearch();
        $this->assertFalse($editor->isSearchOverlayOpen());
    }

    public function testSearchOverlayMouseClickPicksAndHoverSelects(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openSearch(
            'Search',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $editor->setSearchRows($this->searchRows());
        $editor->render(new RenderContext(80, 20));

        // Hover finds a hit row (the search branch of handlePopupHover); clicking
        // it picks that hit (the search branch of handlePopupClick).
        $hitRow = -1;
        for ($row = 0; $row < 20 && $hitRow < 0; ++$row) {
            if (!$editor->handlePopupHover($row, 40)) {
                continue;
            }

            $hitRow = $row;
        }
        $this->assertGreaterThanOrEqual(0, $hitRow);
        $this->assertTrue($editor->handlePopupClick($hitRow, 40));
        $this->assertNotNull($picked);
    }

    public function testFindModalMouseClickAndHoverDelegate(): void
    {
        $editor = $this->editor();
        $jumped = null;
        $editor
            ->findModal()
            ->onJump(static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            });
        $editor->findModal()->open('Find in Files');
        $editor
            ->findModal()
            ->setPreview([
                ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
                ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ]);
        $editor->render(new RenderContext(100, 40)); // composites the modal → records its box

        $box = $editor->findModal()->lastBox();
        $this->assertNotNull($box);
        // Hover the second match row (the first is already selected), then click the first → jump.
        $this->assertTrue($editor->handlePopupHover($box['previewRow'] + 2, $box['col'] + 3));
        $this->assertTrue($editor->handlePopupClick($box['previewRow'] + 1, $box['col'] + 3));
        $this->assertSame(['src/A.php', 4], $jumped);
    }

    private function overlayEditor(\ArrayObject $sink): CodeEditorWidget
    {
        $editor = $this->editor();
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => 'src/a.php', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => 'src/b.php', 'value' => 'B'],
            ],
            static function (string $value) use ($sink): void {
                $sink['picked'] = $value;
            },
        );

        return $editor;
    }

    public function testListOverlayClickPicksItem(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        // width 20 (title 12+4 vs item 5+9+6=20) centred → col 30; popup height 3 →
        // startRow intdiv(20-3,3)=5; title row 5, items at rows 6 and 7.
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupClick(7, 35), 'a click on an item is consumed');
        $this->assertSame('B', $sink['picked']);
        $this->assertFalse($editor->isOverlayOpen());
    }

    public function testListOverlayClickOutsideDismisses(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupClick(0, 0), 'the modal overlay consumes any click');
        $this->assertNull($sink['picked']);
        $this->assertFalse($editor->isOverlayOpen());
    }

    public function testListOverlayClickBeforeRenderDismisses(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        // No render yet → no captured box → the click cannot hit an item, so it dismisses.
        $this->assertTrue($editor->handlePopupClick(7, 35));
        $this->assertNull($sink['picked']);
        $this->assertFalse($editor->isOverlayOpen());
    }

    public function testPromptClickOnItsBoxIsSwallowedAndElsewhereDismisses(): void
    {
        $editor = $this->editor();
        $editor->openPrompt('Go to line', '', static fn () => null);
        $editor->render(new RenderContext(80, 20)); // composites the row-0 prompt box

        $this->assertTrue($editor->isPromptOpen());
        $this->assertTrue($editor->handlePopupClick(0, 3), 'a click on the prompt box is consumed');
        $this->assertTrue($editor->isPromptOpen(), 'and does not dismiss it — the caret stays');
        $this->assertTrue($editor->handlePopupClick(5, 3), 'a click off the prompt is consumed');
        $this->assertFalse($editor->isPromptOpen(), 'and dismisses it, like every sibling overlay');
    }

    public function testListOverlayHoverTintsWithoutMovingTheSelection(): void
    {
        $editor = $this->overlayEditor(new \ArrayObject(['picked' => null]));
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupHover(7, 35), 'hover item 1 tints it');
        $this->assertFalse($editor->handlePopupHover(7, 35), 'same row → no change');
        $this->assertTrue($editor->handlePopupHover(5, 35), 'the title row is no item: the tint clears');
        $this->assertFalse($editor->handlePopupHover(5, 35), 'still no item → no change');

        // Hover item 1; item 0 stays selected.
        $editor->handlePopupHover(7, 35);
        $rows = $editor->render(new RenderContext(80, 20));
        $body = implode("\n", $rows);
        $this->assertStringContainsString("\x1b[48;5;31m", $body, 'the selected item keeps its background');
        $this->assertStringContainsString("\x1b[48;5;236m", $body, 'the hovered item takes the hover tint');
    }

    private function completionEditor(): CodeEditorWidget
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5); // after "echo " → empty word-before-cursor
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);

        return $editor;
    }

    public function testCompletionClickAcceptsSuggestion(): void
    {
        $editor = $this->completionEditor();
        // anchorCol = gutter 4 + cursorCol 5 = 9; cursorScreenRow 2 → startRow 3;
        // items at rows 3 and 4, box cols [9, 26).
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupClick(4, 12), 'a click on a suggestion is consumed');
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionClickOutsideNotConsumed(): void
    {
        $editor = $this->completionEditor();
        $editor->render(new RenderContext(80, 20));

        $this->assertFalse($editor->handlePopupClick(0, 0), 'a click off the popup falls through to caret placement');
        $this->assertTrue($editor->isCompletionOpen());
    }

    public function testCompletionClickBeforeRenderNotConsumed(): void
    {
        $editor = $this->completionEditor();
        // No render → no captured box → not consumed.
        $this->assertFalse($editor->handlePopupClick(4, 12));
        $this->assertTrue($editor->isCompletionOpen());
    }

    public function testCompletionHoverTintsWithoutMovingTheSelection(): void
    {
        $editor = $this->completionEditor();
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupHover(4, 12), 'hover the second suggestion tints it');
        $this->assertFalse($editor->handlePopupHover(4, 12), 'same row → no change');
        $this->assertTrue($editor->handlePopupHover(2, 12), 'a row above the popup clears the tint');
        $this->assertFalse($editor->handlePopupHover(2, 12), 'still off the popup → no change');

        // Hover the second item; the first stays selected.
        $editor->handlePopupHover(4, 12);
        $rows = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $rows[3], 'the first suggestion keeps the selection background');
        $this->assertStringContainsString("\x1b[48;5;236m", $rows[4], 'the hovered second suggestion takes the hover tint, not the selection');

        // A click still accepts the row it lands on, regardless of hover.
        $editor->handlePopupClick(4, 12);
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
    }

    public function testPopupClickAndHoverNoopWhenNothingOpen(): void
    {
        $editor = $this->editor();
        $this->assertFalse($editor->handlePopupClick(3, 3));
        $this->assertFalse($editor->handlePopupHover(3, 3));
    }

    public function testPromptSubmitAndCancel(): void
    {
        $editor = $this->editor();

        $submitted = null;
        $editor->openPrompt('Go to line', '', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString('Go to line:', AnsiUtils::stripAnsiCodes($rendered[0]));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput('4');
        $editor->handleInput('2');
        $editor->handleInput("\x7f"); // backspace -> "4"
        $editor->handleInput("\r");
        $this->assertSame('4', $submitted);

        $editor->openPrompt('Rename to', 'old', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $editor->handleInput("\x1b"); // cancel
        $editor->handleInput('Z'); // goes to the buffer, not a prompt
        $this->assertSame('4', $submitted, 'cancelled prompt does not submit');
    }

    public function testGotoLineViaKeystroke(): void
    {
        $editor = $this->editor("a\nb\nc\nd\n");
        $editor->handleInput("\x07"); // ctrl+g
        $editor->handleInput('3');
        $editor->handleInput("\r");

        $this->assertSame(2, $editor->getDocument()->getCursorLine());
    }

    public function testSignatureHintRenderingAndClearing(): void
    {
        $editor = $this->editor("<?php\n\ngreet\n");
        $editor->getDocument()->setCursor(2, 5);
        $editor->setSignatureHint('greet(string $name): string');

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString('greet(string $name): string', AnsiUtils::stripAnsiCodes(implode("\n", $rendered)));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput("\x1b"); // escape clears the hint
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString('$name', AnsiUtils::stripAnsiCodes(implode("\n", $rendered)));
    }

    public function testAutoTriggerOnArrowAndSignatureOnParen(): void
    {
        $editor = $this->editor("<?php\n\$x\n");
        $completionRequests = [];
        $signatureRequests = [];
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                requestCompletion: static function (int $l, int $c) use (&$completionRequests): void {
                    $completionRequests[] = [$l, $c];
                },
                requestSignatureHelp: static function (int $l, int $c) use (&$signatureRequests): void {
                    $signatureRequests[] = [$l, $c];
                },
            ),
        ));

        $editor->getDocument()->setCursor(1, 2);
        $editor->handleInput('-');
        $editor->handleInput('>');
        $this->assertCount(1, $completionRequests, '-> auto-triggers completion');

        $editor->handleInput('(');
        $this->assertCount(1, $signatureRequests, '( triggers signature help');

        $editor->handleInput("\x00"); // ctrl+space manual trigger
        $this->assertCount(2, $completionRequests);
    }

    public function testDefinitionAndNavigateBackCallbacks(): void
    {
        $editor = $this->editor();
        $definitionAt = null;
        $navigatedBack = false;
        $editor->setCallbacks(new EditorCallbacks(
            navigation: new FakeEditorNavigation(
                requestDefinition: static function (int $l, int $c) use (&$definitionAt): void {
                    $definitionAt = [$l, $c];
                },
                navigateBack: static function () use (&$navigatedBack): void {
                    $navigatedBack = true;
                },
            ),
        ));

        $editor->getDocument()->setCursor(2, 3);
        $editor->handleInput("\x1b[24~"); // F12
        $this->assertSame([2, 3], $definitionAt);

        $editor->handleInput("\x1b[1;7D"); // ctrl+alt+left
        $this->assertTrue($navigatedBack);
    }

    public function testJumpToCentersAndConverts(): void
    {
        $lines = implode("\n", array_map(static fn (int $i) => "line {$i}", range(0, end: 99)));
        $editor = $this->editor($lines);
        $editor->jumpTo(50, 3);

        $this->assertSame(50, $editor->getDocument()->getCursorLine());
        $this->assertSame(3, $editor->getDocument()->getCursorCol());

        $rendered = $editor->render(new RenderContext(80, 20));
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $rendered));
        $this->assertStringContainsString('line 50', $plain, 'target line visible after jump');
    }

    public function testBracketedPaste(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[200~pasted text\x1b[201~");
        $this->assertSame('pasted text', $editor->getDocument()->getLine(1));

        // Chunked paste across handleInput calls
        $editor->handleInput("\x1b[200~part1 ");
        $editor->handleInput('part2');
        $editor->handleInput("\x1b[201~");
        $this->assertStringContainsString('part1 part2', $editor->getDocument()->getLine(1));
    }

    public function testToggleCommentViaKeystroke(): void
    {
        $editor = $this->editor("<?php\necho 1;\n", 'c.php');
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1f"); // ctrl+/ (legacy ctrl+- byte)
        $this->assertSame('// echo 1;', $editor->getDocument()->getLine(1));
    }

    public function testMoveLineKeystrokes(): void
    {
        $editor = $this->editor("<?php\nAAA\nBBB\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[1;4B"); // alt+shift+down
        $this->assertSame('BBB', $editor->getDocument()->getLine(1));
        $this->assertSame('AAA', $editor->getDocument()->getLine(2));

        $editor->handleInput("\x1b[1;4A"); // alt+shift+up
        $this->assertSame('AAA', $editor->getDocument()->getLine(1));
    }

    public function testBufferNavigationCallbacks(): void
    {
        $editor = $this->editor();
        $events = [];
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                openFind: static function (FindMode $r) use (&$events): void {
                    $events[] = 'find:'.var_export(FindMode::Replace === $r, return: true);
                },
                openGotoFile: static function () use (&$events): void {
                    $events[] = 'goto';
                },
            ),
            navigation: new FakeEditorNavigation(
                requestHover: static function () use (&$events): void {
                    $events[] = 'doc';
                },
                requestReferences: static function () use (&$events): void {
                    $events[] = 'usages';
                },
            ),
            buffer: new FakeBufferNavigation(
                cycleBuffer: static function (int $d) use (&$events): void {
                    $events[] = "cycle:{$d}";
                },
                openRecentFiles: static function () use (&$events): void {
                    $events[] = 'recent';
                },
                closeBuffer: static function () use (&$events): void {
                    $events[] = 'close';
                },
            ),
        ));

        $editor->handleInput("\x1b[1;5C"); // ctrl+right
        $editor->handleInput("\x1b[1;5D"); // ctrl+left
        $editor->handleInput("\x05"); // ctrl+e
        $editor->handleInput("\x1bw"); // alt+w
        $editor->handleInput("\x06"); // ctrl+f
        $editor->handleInput("\x12"); // ctrl+r
        $editor->handleInput("\x10"); // ctrl+p
        $editor->handleInput("\x1b[1;2P"); // f1
        $editor->handleInput("\x1b[18;3~"); // alt+f7

        $this->assertSame(
            ['cycle:1', 'cycle:-1', 'recent', 'close', 'find:false', 'find:true', 'goto', 'doc', 'usages'],
            $events,
        );
    }

    public function testSaveAndUndoRedoKeystrokes(): void
    {
        $editor = $this->editor();
        $saved = false;
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                save: static function () use (&$saved): void {
                    $saved = true;
                },
            ),
        ));

        $editor->handleInput("\x13"); // ctrl+s
        $this->assertTrue($saved);

        $editor->handleInput('Q');
        $editor->handleInput("\x1a"); // ctrl+z undo
        $this->assertStringStartsWith('<?php', $editor->getDocument()->getLine(0));
    }

    public function testHorizontalScrollKeepsCursorVisible(): void
    {
        $long = str_repeat('abcdefghij', times: 20);
        $editor = $this->editor("<?php\n{$long}\n");
        $editor->getDocument()->setCursor(1, 150);

        $rendered = $editor->render(new RenderContext(40, 5));
        $this->assertExactWidth($rendered, 40);
        $this->assertStringContainsString("\x1b_pi:c;", implode('', $rendered), 'cursor marker still rendered');
    }

    public function testRenameKeystrokeOpensPrefilledPrompt(): void
    {
        $editor = $this->editor("<?php\n\$subject\n");
        $renamed = null;
        $editor->setCallbacks(new EditorCallbacks(
            refactor: new FakeEditorRefactor(
                // Mirror RefactorController's flow: prompt for the new name, then rename.
                requestPrepareRename: static function (int $l, int $c, string $word) use ($editor, &$renamed): void {
                    $editor->openPrompt('Rename to', $word, static function (string $value) use ($l, $c, &$renamed): void {
                        $renamed = [trim($value), $l, $c];
                    });
                },
            ),
        ));
        $editor->getDocument()->setCursor(1, 8);

        $editor->handleInput("\x1b[17;2~"); // shift+f6
        $editor->handleInput("\r"); // accept prefilled word

        $this->assertSame(['subject', 1, 8], $renamed);
    }

    public function testRunActionEditVerbsMutateDocument(): void
    {
        $editor = $this->editor("<?php\necho 1;\n");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);

        $editor->runAction('duplicate_line');
        $this->assertSame('<?php', $doc->getLine(0));
        $this->assertSame('<?php', $doc->getLine(1), 'line duplicated');

        $editor->runAction('undo');
        $this->assertSame('echo 1;', $doc->getLine(1), 'undo reverted the duplicate');

        $editor->runAction('redo');
        $this->assertSame('<?php', $doc->getLine(1), 'redo re-applied it');

        $editor->runAction('select_all');
        $this->assertTrue($doc->hasSelection());
    }

    public function testRunActionMatchesKeypressForDuplicateLine(): void
    {
        $viaKey = $this->editor("<?php\n");
        $viaKey->getDocument()->setCursor(0, 0);
        $viaKey->handleInput("\x1b[100;5u"); // ctrl+d (kitty) — duplicate_line

        $viaMenu = $this->editor("<?php\n");
        $viaMenu->getDocument()->setCursor(0, 0);
        $viaMenu->runAction('duplicate_line');

        $this->assertSame($viaKey->getDocument()->getText(), $viaMenu->getDocument()->getText());
    }

    public function testRunActionFiresCallbackVerbs(): void
    {
        $editor = $this->editor();
        $reformatted = false;
        $usagesAt = null;
        $editor->setCallbacks(new EditorCallbacks(
            navigation: new FakeEditorNavigation(
                requestReferences: static function (int $l, int $c) use (&$usagesAt): void {
                    $usagesAt = [$l, $c];
                },
            ),
            refactor: new FakeEditorRefactor(
                requestReformat: static function () use (&$reformatted): void {
                    $reformatted = true;
                },
            ),
        ));
        $editor->getDocument()->setCursor(2, 3);

        $editor->runAction('reformat');
        $this->assertTrue($reformatted, 'no-arg callback fired');

        $editor->runAction('find_usages');
        $this->assertSame([2, 3], $usagesAt, 'positional callback fired with caret');
    }

    public function testRunActionUnknownIdIsNoOp(): void
    {
        $editor = $this->editor("<?php\n");
        $before = $editor->getDocument()->getText();
        $editor->runAction('does_not_exist');
        $this->assertSame($before, $editor->getDocument()->getText());
    }

    public function testInsertTextGoesThroughTheFullChangePipeline(): void
    {
        $editor = $this->editor("ab\ncd\n");
        $editor->getDocument()->setCursor(1, 1);
        $changed = 0;
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                scheduleDidChange: static function () use (&$changed): void {
                    ++$changed;
                },
            ),
        ));

        $editor->insertText('XY');

        $this->assertSame("ab\ncXYd\n", $editor->getDocument()->getText(), 'inserted at the caret');
        $this->assertSame(1, $changed, 'the change notification (LSP didChange path) fired');
        $editor->getDocument()->undo();
        $this->assertSame("ab\ncd\n", $editor->getDocument()->getText(), 'undoable like typed input');
    }

    public function testLineAnnotationsRenderAndYieldToDiagnostics(): void
    {
        $editor = $this->editor("alpha;\nbeta;\n", '/tmp/a.php');
        $editor->setLineAnnotations([0 => 'abc12345 tester 2026-01-01', 1 => 'abc12345 tester 2026-01-01']);

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertSame(2, substr_count($screen, needle: 'abc12345 tester'), 'both annotations render in the trailing space');

        // A diagnostic on line 0 wins the trailing slot; line 1 keeps its blame.
        $editor->enableInlineDiagnostics();
        $editor->setDiagnostics([0 => [['severity' => 1, 'message' => 'Boom', 'col' => 0]]]);
        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertStringContainsString('Boom', $screen);
        $this->assertSame(1, substr_count($screen, needle: 'abc12345 tester'), 'the diagnosed line dropped its annotation');

        // Too narrow: the annotation is dropped rather than truncated to noise.
        $editor->setDiagnostics([]);
        $narrow = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(16, 6))));
        $this->assertStringNotContainsString('abc12345', $narrow);

        // A document switch clears the annotations with the other decorations.
        $editor->openFile('/tmp/b.php', "gamma;\n");
        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertStringNotContainsString('abc12345', $screen);
    }

    public function testMovePopupSelectionDispatchesToTheOpenPopup(): void
    {
        $editor = $this->editor();
        $this->assertFalse($editor->movePopupSelection(3), 'no popup: falls through to the buffer scroll');

        // Completion popup: the wheel wraps its selection like the arrows.
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);
        $this->assertTrue($editor->movePopupSelection(3), 'the completion consumed the wheel');
        $editor->handleInput("\r");
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2), '3 % 2 steps land on the second candidate');

        // List overlay: same dispatch, wrapping selection.
        $picked = null;
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => '', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => '', 'value' => 'B'],
                ['label' => 'c.php', 'hint' => '', 'value' => 'C'],
            ],
            static function (string $value) use (&$picked): void {
                $picked = $value;
            },
        );
        $this->assertTrue($editor->movePopupSelection(-1), 'the list overlay consumed the wheel');
        $editor->handleInput("\r");
        $this->assertSame('C', $picked, 'a wheel-up from the first row wraps to the last');

        // Search Everywhere modal: the wheel reaches its selection too.
        $editor->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $this->assertTrue($editor->movePopupSelection(1), 'the search modal consumed the wheel');
        $editor->handleSearchOverlayInput("\x1b");
    }

    public function testMoveSelectionWithoutCandidatesIsInert(): void
    {
        // Directly on the collaborator: an empty candidate list must not
        // divide by zero when a stray wheel reaches it.
        $completion = new \Porta\Editor\EditorCompletion(
            new \Porta\Editor\Document(''),
            static function (): void {},
            static function (): void {},
            static fn (): bool => true,
            static function (): void {},
            static function (): void {},
        );
        $completion->moveSelection(1);
        $this->addToAssertionCount(1); // no crash, no invalidation needed
    }
}
