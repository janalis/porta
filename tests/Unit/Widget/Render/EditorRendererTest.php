<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\CursorVisibility;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\PaneFocus;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class EditorRendererTest extends TestCase
{
    private function styles(): Styles
    {
        return Styles::dark();
    }

    public function testInlayShiftAtCountsOnlyHintsAtOrBeforeTheTarget(): void
    {
        $doc = new Document("abcdefgh\n");
        // One hint before the target display column, one after it.
        $hints = [['col' => 2, 'text' => '»'], ['col' => 6, 'text' => ': int']];

        // Target display column 4: the col-6 hint sits past it and is skipped
        // (the `continue`), so only the col-2 hint contributes to the shift.
        $this->assertSame(mb_strwidth('»'), EditorRenderer::inlayShiftAt($doc, 0, $hints, 4));

        // A target past both hints includes both widths.
        $this->assertSame(mb_strwidth('»') + mb_strwidth(': int'), EditorRenderer::inlayShiftAt($doc, 0, $hints, 10));
    }

    public function testInlayShiftAtIncludesAHintExactlyAtTheTargetColumn(): void
    {
        $doc = new Document("abcdefgh\n");
        // The hint sits exactly ON the target column: "at or before" must
        // include it (a `>` vs `>=` boundary the same-column case distinguishes).
        $this->assertSame(mb_strwidth('»'), EditorRenderer::inlayShiftAt($doc, 0, [['col' => 2, 'text' => '»']], 2));
    }

    public function testMatchingBracketsFindsTheCloseOnTheSameLine(): void
    {
        $doc = new Document("(x)\n");
        $doc->setCursor(0, 0);
        $this->assertSame([[0, 0], [0, 2]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsScansBackwardFromJustAfterACloseBracket(): void
    {
        // Cursor sits right after the ')', not on it: the naive scan steps
        // back one column first (the col>=0 boundary) before it can see the
        // close bracket and scan backward to its match.
        $doc = new Document("()\n");
        $doc->setCursor(0, 2);
        $this->assertSame([[0, 1], [0, 0]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsBackStepLandsExactlyOnColumnZero(): void
    {
        // Cursor is on 'x' (not a bracket), so the back-step decrements to
        // column 0 exactly — the col>=0 vs col>0 boundary only a bracket AT
        // column 0 (reached via the decrement, not directly) distinguishes.
        $doc = new Document("(x)\n");
        $doc->setCursor(0, 1);
        $this->assertSame([[0, 0], [0, 2]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsStepsByOneNotTwoAcrossNestedPairs(): void
    {
        // A direction magnitude of 2 would jump straight past the inner pair
        // and run off the end of the line without ever finding the match.
        $doc = new Document("(())\n");
        $doc->setCursor(0, 0);
        $this->assertSame([[0, 0], [0, 3]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsWrapsToThePreviousLineWithTheRightStartColumn(): void
    {
        $doc = new Document("(\nx)\n");
        $doc->setCursor(0, 0);
        $this->assertSame([[0, 0], [1, 1]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsForwardCrossingStartsANewLineAtColumnZero(): void
    {
        // The close bracket sits at column 0 of the next line: starting there
        // (not at the new line's last column) is the only way to find it.
        $doc = new Document("(\n)y\n");
        $doc->setCursor(0, 0);
        $this->assertSame([[0, 0], [1, 0]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsScanningBackwardAcrossALineStartsFromThePreviousLinesEnd(): void
    {
        // Backward scan (from a close bracket) that doesn't resolve on the
        // current line must resume from the END of the previous line, not
        // column 0 — the `?:` branch only a backward-crossing test reaches.
        $doc = new Document("(\nx)\n");
        $doc->setCursor(1, 2); // just after the ')' on line 1
        $this->assertSame([[1, 1], [0, 0]], EditorRenderer::matchingBrackets($doc));
    }

    public function testMatchingBracketsReturnsNullWhenNoMatchExistsAnywhere(): void
    {
        $doc = new Document("(\n");
        $doc->setCursor(0, 0);
        $this->assertNull(EditorRenderer::matchingBrackets($doc));
    }

    public function testIdentifierUnderCaretIncludesTheLeadingDollarSign(): void
    {
        $doc = new Document("\$needle = 1;\n");
        $doc->setCursor(0, 3); // inside "needle"
        $this->assertSame('$needle', EditorRenderer::identifierUnderCaret($doc));
    }

    public function testIdentifierUnderCaretIsNullWhenCaretIsNotOnAWordChar(): void
    {
        $doc = new Document("a = b;\n");
        $doc->setCursor(0, 2); // the space between "a" and "="
        $this->assertNull(EditorRenderer::identifierUnderCaret($doc));
    }

    public function testIdentifierUnderCaretRespectsAnIndentedStartColumn(): void
    {
        // start (2) is nonzero here: an end-start vs end+start mix-up only
        // shows up once the identifier doesn't begin at column 0.
        $doc = new Document("  \$needle;\n");
        $doc->setCursor(0, 5); // inside "needle"
        $this->assertSame('$needle', EditorRenderer::identifierUnderCaret($doc));
    }

    public function testIdentifierUnderCaretRejectsAWordStartingWithADigit(): void
    {
        // Not a valid PHP identifier as a whole, even though "abc" alone
        // would match — the leading anchor must reject it, not just search
        // for a valid suffix anywhere inside the run.
        $doc = new Document("3abc;\n");
        $doc->setCursor(0, 1);
        $this->assertNull(EditorRenderer::identifierUnderCaret($doc));
    }

    public function testIdentifierOccurrencesRequiresAtLeastTwoMatches(): void
    {
        $doc = new Document("\$needle = 1;\n");
        $doc->setCursor(0, 3);
        $this->assertSame([], EditorRenderer::identifierOccurrences($doc, PaneFocus::Focused, 0, 0));
    }

    public function testIdentifierOccurrencesFindsBothMatchesWhenThereAreTwo(): void
    {
        $doc = new Document("\$needle = \$needle + 1;\n");
        $doc->setCursor(0, 3);
        $occurrences = EditorRenderer::identifierOccurrences($doc, PaneFocus::Focused, 0, 0);
        $this->assertCount(2, $occurrences);
    }

    public function testIdentifierOccurrencesIsEmptyWhenThePaneIsBlurred(): void
    {
        $doc = new Document("\$needle = \$needle + 1;\n");
        $doc->setCursor(0, 3);
        $this->assertSame([], EditorRenderer::identifierOccurrences($doc, PaneFocus::Blurred, 0, 0));
    }

    public function testManyInlayHintsOnOneLineStayLinearInSize(): void
    {
        // Same disease as the guide fold: per-hint insertAt re-sliced the
        // accumulated line, multiplying escapes per hint — a parameter-hint-
        // happy LSP line went exponential. One pass stays linear.
        $doc = new Document(str_repeat('fn(x), ', 12)."\n");
        $hints = [];
        for ($i = 0; $i < 12; ++$i) {
            $hints[] = ['col' => 3 + ($i * 7), 'text' => 'p:'];
        }
        $highlighter = new \Porta\Highlight\CodeHighlighter();
        $highlighter->setFile('a.php');
        $hlLine = $highlighter->highlight([str_repeat('fn(x), ', 12)], 1)[0];

        $out = EditorRenderer::applyInlayHints($doc, 0, $hints, $hlLine, $this->styles());

        $this->assertLessThan(4_096, \strlen($out), 'hint insertion must stay linear, never multiply per hint');
        $this->assertSame(12, substr_count(AnsiUtils::stripAnsiCodes($out), 'p:'), 'every hint landed');
        $this->assertStringContainsString('fn(p:x)', AnsiUtils::stripAnsiCodes($out), 'hints insert at their column, pushing content right');
    }

    public function testInlayHintsAtTheSameColumnKeepTheFoldOrder(): void
    {
        // Two hints at one column: the fold inserted rightmost-first, so the
        // LATER-listed hint ended up left. The single pass must match.
        $doc = new Document("abcd\n");
        $out = EditorRenderer::applyInlayHints(
            $doc,
            0,
            [
                ['col' => 2, 'text' => 'A'],
                ['col' => 2, 'text' => 'B'],
            ],
            'abcd',
            $this->styles(),
        );

        $this->assertSame('abBAcd', AnsiUtils::stripAnsiCodes($out));
    }

    public function testAnInlayHintAtColumnZeroPrependsCleanly(): void
    {
        $doc = new Document("abcd\n");
        $out = EditorRenderer::applyInlayHints(
            $doc,
            0,
            [
                ['col' => 0, 'text' => 'H'],
                ['col' => 2, 'text' => 'I'],
            ],
            'abcd',
            $this->styles(),
        );

        // Exact: a stale segment cursor would duplicate or drop characters.
        $this->assertSame('HabIcd', AnsiUtils::stripAnsiCodes($out));

        // Interior gaps with a non-zero start: an over-long segment slice
        // would duplicate the characters after the gap.
        $mid = EditorRenderer::applyInlayHints(
            $doc,
            0,
            [
                ['col' => 1, 'text' => 'H'],
                ['col' => 3, 'text' => 'I'],
            ],
            'abcd',
            $this->styles(),
        );
        $this->assertSame('aHbcId', AnsiUtils::stripAnsiCodes($mid));
    }

    public function testIndentGuidesOnADeeplyIndentedLineStayLinearInSize(): void
    {
        // The regression this pins: overlaying one guide glyph at a time
        // re-sliced the accumulated line, and the slicer's escape replay
        // DOUBLED the escapes per glyph — 16 indent levels made a 2^20-byte
        // row and a 5MB frame (a deeply indented ASCII-art block in a README).
        $indent = str_repeat(' ', 32); // 8 guide columns at the default indent width of 4
        $doc = new Document($indent."x\n");
        $doc->setCursor(0, 0);
        $styles = $this->styles();
        $highlighter = new \Porta\Highlight\CodeHighlighter();
        $highlighter->setFile('a.md');
        $hlLine = $highlighter->highlight([$indent.'x'], 1)[0];

        $guided = EditorRenderer::applyIndentGuides($doc, $hlLine, 0, $styles);

        $this->assertLessThan(2_048, \strlen($guided), 'guide overlay must stay linear, never double per glyph');
        $this->assertSame(
            str_repeat(\Porta\Ui\Glyphs::INDENT_GUIDE.'   ', 8).'x',
            AnsiUtils::stripAnsiCodes($guided),
            'one guide per indent level, visible text otherwise untouched',
        );
    }

    public function testIdentifierOccurrencesSkipsAnOverDenseLineButKeepsItsSiblings(): void
    {
        // 9 same-word matches on one line = the caret parked on a prose
        // stopword; that LINE contributes nothing (per-line skip), while a
        // code-like sibling line keeps its highlighting.
        $dense = implode(' ', array_fill(0, EditorRenderer::MAX_OCCURRENCES_PER_LINE + 1, 'the'));
        $doc = new Document($dense."\nthe end of the\n");
        $doc->setCursor(0, 1); // caret on the first 'the'

        $occurrences = EditorRenderer::identifierOccurrences($doc, PaneFocus::Focused, 0, 1);

        $this->assertCount(2, $occurrences, 'only line 1\'s two matches survive');
        $this->assertSame([1, 1], array_column($occurrences, 0), 'both from the sibling line');
    }

    public function testIdentifierOccurrencesKeepsALineExactlyAtTheCap(): void
    {
        $atCap = implode(' ', array_fill(0, EditorRenderer::MAX_OCCURRENCES_PER_LINE, 'the'));
        $doc = new Document($atCap."\n");
        $doc->setCursor(0, 1);

        $this->assertCount(
            EditorRenderer::MAX_OCCURRENCES_PER_LINE,
            EditorRenderer::identifierOccurrences($doc, PaneFocus::Focused, 0, 0),
            'the cap is exclusive: exactly MAX matches still highlight',
        );
    }

    public function testIdentifierOccurrencesAcceptsATwoCharacterIdentifier(): void
    {
        // ltrim($word, '$') === "ab", length exactly 2 — the boundary between
        // "too short" and "long enough" the < vs <= mutant lands on.
        $doc = new Document("\$ab = \$ab + 1;\n");
        $doc->setCursor(0, 1);
        $this->assertCount(2, EditorRenderer::identifierOccurrences($doc, PaneFocus::Focused, 0, 0));
    }

    public function testApplyInlayHintsIsANoOpWithNoHints(): void
    {
        $doc = new Document("x\n");
        $this->assertSame('x', EditorRenderer::applyInlayHints($doc, 0, [], 'x', $this->styles()));
    }

    public function testApplyIndentGuidesIsANoOpWithNoLeadingWhitespace(): void
    {
        $doc = new Document("x\n");
        $this->assertSame('x', EditorRenderer::applyIndentGuides($doc, 'x', 0, $this->styles()));
    }

    public function testApplyIndentGuidesOverlaysAGuideInsideLeadingWhitespace(): void
    {
        $doc = new Document("  x\n");
        $result = EditorRenderer::applyIndentGuides($doc, '  x', 0, $this->styles());
        $this->assertStringContainsString(Glyphs::INDENT_GUIDE, $result);
    }

    public function testRenderContentLineClampsTheCursorOffsetAtZero(): void
    {
        // hScroll (5) past the cursor's own display column (0): the real code
        // clamps the offset to 0 rather than going negative, so the marker
        // lands at the very start of the sliced (empty) window.
        $result = EditorRenderer::renderContentLine(hScroll: 5, highlightedLine: 'abcdefgh', contentWidth: 3, withCursor: CursorVisibility::Shown, cursorDisplayCol: 0, cursorMarker: '¦');
        $this->assertStringStartsWith('¦', AnsiUtils::stripAnsiCodes($result));
    }

    public function testRenderContentLineMarkerFollowsTheCursorRelativeToHScroll(): void
    {
        // cursorDisplayCol(6) - hScroll(4) = 2: the marker must land after the
        // 2nd visible character, not at hScroll+cursorDisplayCol (10, past the
        // window) and not clamped to 0 (a Minus-vs-Plus and floor mix-up).
        $result = EditorRenderer::renderContentLine(hScroll: 4, highlightedLine: 'abcdefgh', contentWidth: 4, withCursor: CursorVisibility::Shown, cursorDisplayCol: 6, cursorMarker: '¦');
        $plain = AnsiUtils::stripAnsiCodes($result);
        $this->assertSame('ef¦gh', $plain);
    }

    public function testAppendInlineDiagnosticDefaultsAMissingSeverityToError(): void
    {
        $content = str_pad('code', length: 20);
        $result = EditorRenderer::appendInlineDiagnostic(hScroll: 0, textDisplayWidth: 4, diags: [['message' => 'oops']], content: $content, contentWidth: 20, styles: $this->styles());
        $this->assertStringContainsString('oops', AnsiUtils::stripAnsiCodes($result));
    }

    public function testAppendInlineDiagnosticStopsAtTheFirstMatchingSeverityMessage(): void
    {
        $content = str_pad('code', length: 30);
        $diags = [
            ['message' => 'first error', 'severity' => 1],
            ['message' => 'second error', 'severity' => 1],
        ];
        $result = EditorRenderer::appendInlineDiagnostic(hScroll: 0, textDisplayWidth: 4, diags: $diags, content: $content, contentWidth: 30, styles: $this->styles());
        $plain = AnsiUtils::stripAnsiCodes($result);
        $this->assertStringContainsString('first error', $plain);
        $this->assertStringNotContainsString('second error', $plain);
    }

    public function testAppendInlineDiagnosticSkipsWhenNotEnoughRoomIsAvailable(): void
    {
        $content = str_pad('x', length: 10);
        // textDisplayWidth (8) leaves only 10-8-2=0 columns available, well
        // under the 8-column usefulness threshold.
        $result = EditorRenderer::appendInlineDiagnostic(
            hScroll: 0,
            textDisplayWidth: 8,
            diags: [['message' => 'oops', 'severity' => 1]],
            content: $content,
            contentWidth: 10,
            styles: $this->styles(),
        );
        $this->assertSame($content, $result);
    }

    public function testAppendInlineDiagnosticAccountsForHorizontalScrollWhenPlacingTheMessage(): void
    {
        // textDisplayWidth(20) - hScroll(15) = 5: the message must be placed
        // right after column 5 (+2 gap), not at 20+15=35 (a Minus-vs-Plus bug)
        // and not clamped to the 0 floor either (a max(0,x) floor mutant).
        $content = str_pad('', length: 30);
        $result = EditorRenderer::appendInlineDiagnostic(
            hScroll: 15,
            textDisplayWidth: 20,
            diags: [['message' => 'oops', 'severity' => 1]],
            content: $content,
            contentWidth: 30,
            styles: $this->styles(),
        );
        $plain = AnsiUtils::stripAnsiCodes($result);
        $this->assertStringContainsString('oops', substr($plain, offset: 7));
        $this->assertSame('       ', substr($plain, offset: 0, length: 7), 'the message starts at col 5 + a 2-space gap, not earlier');
    }

    public function testAppendInlineDiagnosticClampsTextEndAtZeroNotBelow(): void
    {
        // textDisplayWidth(0) - hScroll(5) is negative: textEnd must clamp to
        // 0 (not -1), so the message starts after a 0+2 = 2-column head, not 1.
        $content = str_pad('ABCDE', length: 20);
        $result = EditorRenderer::appendInlineDiagnostic(
            hScroll: 5,
            textDisplayWidth: 0,
            diags: [['message' => 'oops', 'severity' => 1]],
            content: $content,
            contentWidth: 20,
            styles: $this->styles(),
        );
        $plain = AnsiUtils::stripAnsiCodes($result);
        $this->assertSame('AB', substr($plain, offset: 0, length: 2));
        $this->assertStringContainsString('oops', substr($plain, offset: 2));
    }

    public function testAppendInlineDiagnosticAllowsExactlyEightAvailableColumns(): void
    {
        // contentWidth(10) - start(2) = 8 available: the boundary between
        // "not enough room" and "just enough".
        $content = str_pad('', length: 10);
        $result = EditorRenderer::appendInlineDiagnostic(hScroll: 0, textDisplayWidth: 0, diags: [['message' => 'x', 'severity' => 1]], content: $content, contentWidth: 10, styles: $this->styles());
        $this->assertNotSame($content, $result);
    }

    public function testAppendAnnotationSkipsWhenNotEnoughRoomIsAvailable(): void
    {
        $content = str_pad('x', length: 10);
        $result = EditorRenderer::appendAnnotation(hScroll: 0, textDisplayWidth: 8, text: 'blame', content: $content, contentWidth: 10, styles: $this->styles());
        $this->assertSame($content, $result);
    }

    public function testAppendAnnotationPlacesTheTextAfterATwoSpaceGap(): void
    {
        $content = str_pad('ABCDEF', length: 30);
        $result = EditorRenderer::appendAnnotation(hScroll: 0, textDisplayWidth: 4, text: 'blame info', content: $content, contentWidth: 30, styles: $this->styles());
        $plain = AnsiUtils::stripAnsiCodes($result);
        // start = min(30, 4) + 2 = 6: the head keeps exactly "ABCDEF" (6 real
        // content columns), not a slice starting one column early or late.
        $this->assertSame('ABCDEF', substr($plain, offset: 0, length: 6));
        $this->assertStringContainsString('blame info', substr($plain, offset: 6));
    }

    public function testAppendAnnotationAccountsForHorizontalScrollWhenPlacingTheText(): void
    {
        $content = str_pad('', length: 30);
        $result = EditorRenderer::appendAnnotation(hScroll: 15, textDisplayWidth: 20, text: 'blame', content: $content, contentWidth: 30, styles: $this->styles());
        $plain = AnsiUtils::stripAnsiCodes($result);
        $this->assertSame('       ', substr($plain, offset: 0, length: 7), 'the text starts at col 5 + a 2-space gap, not earlier');
        $this->assertStringContainsString('blame', substr($plain, offset: 7));
    }

    public function testSignatureHintDrawsTheFramedPopupAboveTheCaretWhenThereIsHeadroom(): void
    {
        $lines = array_fill(start_index: 0, count: 10, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 5, hint: 'function foo(int $x)', styles: $this->styles());
        // Framed above: the border top row lands 3 rows above the caret.
        $this->assertStringContainsString('╭', $lines[2]);
    }

    public function testSignatureHintFrameWidthIsCappedByTheAvailableColumns(): void
    {
        // frameMax = columns(10) - gutterWidth(2) - 1 - CHROME_COLS(2) = 5, well
        // under the hint's own 10-column width: frameMax must be the binding
        // cap (any sign/operand mistake in its formula changes where the
        // frame's right border lands).
        $lines = array_fill(start_index: 0, count: 10, value: str_pad('', length: 10));
        EditorRenderer::signatureHint($lines, columns: 10, gutterWidth: 2, cursorScreenRow: 5, hint: 'abcdefgh', styles: $this->styles());
        $plain = AnsiUtils::stripAnsiCodes($lines[2]);
        // col(2) + width(5) + CHROME_COLS(2) - 1 = the right border at index 8.
        $this->assertSame('╮', mb_substr($plain, start: 8, length: 1));
    }

    public function testSignatureHintAllowsAWidthOfExactlyFour(): void
    {
        $lines = array_fill(start_index: 0, count: 10, value: str_pad('', length: 40));
        // text = " ab " (width 4): the >=4 vs >4 boundary.
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 5, hint: 'ab', styles: $this->styles());
        $this->assertStringContainsString('╭', $lines[2]);
    }

    public function testSignatureHintAllowsCursorScreenRowOfExactlyThree(): void
    {
        $lines = array_fill(start_index: 0, count: 10, value: str_pad('', length: 40));
        // cursorScreenRow(3) >= 3: top = 3 - 3 = 0, framed above starting at row 0.
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 3, hint: 'function foo()', styles: $this->styles());
        $this->assertStringContainsString('╭', $lines[0]);
    }

    public function testSignatureHintAllowsTopOfExactlyZero(): void
    {
        $lines = array_fill(start_index: 0, count: 3, value: str_pad('', length: 40));
        // cursorScreenRow(3) -> top = 0; exactly 3 lines available (top+3<=3).
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 3, hint: 'function foo()', styles: $this->styles());
        $this->assertStringContainsString('╭', $lines[0]);
        $this->assertStringContainsString('╰', $lines[2]);
    }

    public function testSignatureHintRejectsAFrameThatWouldNotFullyFit(): void
    {
        // Only 2 lines for a 3-row frame (top+3=3 > count(lines)=2): must
        // reject the framed placement outright, not accept it because a
        // laxer top+2 check would pass — the fallback path then also has no
        // room (row 2 is out of range), so nothing renders at all.
        $lines = array_fill(start_index: 0, count: 2, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 3, hint: 'function foo()', styles: $this->styles());
        $this->assertCount(2, $lines, 'must not have grown past the given lines');
        $this->assertSame(str_pad('', length: 40), $lines[0]);
        $this->assertSame(str_pad('', length: 40), $lines[1]);
    }

    public function testSignatureHintFallsBackToTheBareRowWhenThereIsNoHeadroomForTheFrame(): void
    {
        // Only 2 rows total: the framed slot needs 3, so neither above nor
        // below fits, and it must fall back to the unframed one-liner.
        $lines = array_fill(start_index: 0, count: 2, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 0, hint: 'abcdefgh', styles: $this->styles());
        $this->assertStringContainsString('abcdefgh', AnsiUtils::stripAnsiCodes($lines[1]));
    }

    public function testSignatureHintFallbackRowIsOneAboveTheCaretWhenTheCaretIsNotAtTheTop(): void
    {
        // 5 lines, cursorScreenRow=2: framed-above needs top=3 (since
        // cursorScreenRow<3) and top+3=6 > 5, so it doesn't fit — falls back
        // to row = cursorScreenRow - 1 = 1, not - 0 or - 2 or + 1.
        $lines = array_fill(start_index: 0, count: 5, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 2, hint: 'abcdefgh', styles: $this->styles());
        $this->assertStringContainsString('abcdefgh', AnsiUtils::stripAnsiCodes($lines[1]));
        $this->assertSame(str_pad('', length: 40), $lines[2], 'row 2 (cursorScreenRow itself) must stay untouched');
    }

    public function testSignatureHintFallbackAllowsARowOfExactlyZero(): void
    {
        // 4 lines, cursorScreenRow=1: framed-above needs top=2, top+3=5>4
        // doesn't fit; fallback row = 1-1 = 0 exactly.
        $lines = array_fill(start_index: 0, count: 4, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 1, hint: 'abcdefgh', styles: $this->styles());
        $this->assertStringContainsString('abcdefgh', AnsiUtils::stripAnsiCodes($lines[0]));
    }

    public function testSignatureHintFallbackAllowsAWidthOfExactlyFour(): void
    {
        $lines = array_fill(start_index: 0, count: 2, value: str_pad('', length: 40));
        EditorRenderer::signatureHint($lines, columns: 40, gutterWidth: 2, cursorScreenRow: 0, hint: 'xy', styles: $this->styles());
        $this->assertStringContainsString('xy', AnsiUtils::stripAnsiCodes($lines[1]));
    }

    public function testSignatureHintFallbackWidthIsCappedByTheAvailableColumns(): void
    {
        // columns(8) - gutterWidth(2) - 1 = 5, well under the hint's own
        // 10-column width: this expression must be the binding cap.
        $lines = array_fill(start_index: 0, count: 2, value: str_pad('', length: 8));
        EditorRenderer::signatureHint($lines, columns: 8, gutterWidth: 2, cursorScreenRow: 0, hint: 'abcdefgh', styles: $this->styles());
        $plain = AnsiUtils::stripAnsiCodes($lines[1]);
        // col(2) + width(5) = 7: the last painted column is 6, and 7 is blank.
        $this->assertNotSame(' ', mb_substr($plain, start: 6, length: 1));
        $this->assertSame(' ', mb_substr($plain, start: 7, length: 1));
    }

    public function testCompletionPopupWindowSlidesToKeepTheSelectedItemVisible(): void
    {
        $items = array_map(static fn (int $i): CompletionItem => new CompletionItem("item{$i}", "item{$i}", 'var', ''), range(start: 0, end: 9));
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 9, hovered: null, styles: $this->styles());
        // 10 items, 8-item window, selected=9 (last): window must have
        // slid so the selection is the last visible row, not window offset 0.
        $this->assertSame(2, $box->windowOffset);
        $this->assertSame(9, $box->itemAt($box->startRow + $box->count - 1, $box->col));
    }

    public function testCompletionPopupHoverIndexIsRelativeToTheWindow(): void
    {
        // 10 items, selected=9 slides the window to offset 2; hovered(3) is
        // absolute, so its window-relative row is 3-2=1, not row 3 or row 5
        // (a minus-vs-plus mix-up would land on 5). PopupRow and the hover
        // role share a background in this theme, so the foreground color
        // (250, PopupRow-only) is what actually distinguishes "hovered" from
        // "plain unselected".
        $items = array_map(static fn (int $i): CompletionItem => new CompletionItem("item{$i}", "item{$i}", 'var', ''), range(start: 0, end: 9));
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 9, hovered: 3, styles: $this->styles());
        $this->assertStringNotContainsString("\x1b[38;5;250m", $lines[$box->startRow + 1], 'row 1 (hovered) must not carry the plain-row foreground');
        $this->assertStringContainsString("\x1b[38;5;250m", $lines[$box->startRow + 2], 'row 2 (plain, unselected, unhovered) carries it');
    }

    public function testCompletionPopupPlacesItselfBelowTheCaretWhenThereIsRoom(): void
    {
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 2, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(4, $box->startRow); // borderTop (cursorScreenRow(2) + 1) + 1 for the box's own top border
    }

    public function testCompletionPopupFlipsAboveTheCaretWhenThereIsNoRoomBelow(): void
    {
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 6, value: str_pad('', length: 40));
        // cursorScreenRow(4): below would need rows>=4+1+(1+2)=8, but rows=6.
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 6, cursorScreenRow: 4, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(2, $box->startRow, 'flipped above: max(0, cursorScreenRow - framedHeight) + 1 for the border');
    }

    public function testCompletionPopupBorderColSitsOneCellLeftOfTheAnchor(): void
    {
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 10, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(10, $box->col); // borderCol (anchorCol(10) - 1) + 1 for the box's own left border
    }

    public function testCompletionPopupSelectedInWindowClampsAtTheLastVisibleRow(): void
    {
        $items = array_map(static fn (int $i): CompletionItem => new CompletionItem("item{$i}", "item{$i}", 'var', ''), range(start: 0, end: 9));
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 9, hovered: null, styles: $this->styles());
        // selectedInWindow = min(9, 7) = 7: the LAST visible row (index 7)
        // must carry the selection background, not row 6 or 8 or 9.
        $selectedLine = $lines[$box->startRow + 7];
        $this->assertStringContainsString("\x1b[48;5;31m", $selectedLine, 'row 7 (last visible) carries the selection background');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $lines[$box->startRow + 6], 'row 6 must not be marked selected');
    }

    public function testCompletionPopupWidthDefaultsToTwelveWhenNoItemNeedsMore(): void
    {
        $items = [new CompletionItem('a', 'a', 'v', '')]; // tiny label+kind, needs far less than 12
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(12, $box->width);
    }

    public function testCompletionPopupWidthIsCappedByTheAvailableColumns(): void
    {
        // columns(20) - 2 - CHROME_COLS(2) = 16, well under an item wide
        // enough to otherwise want more room.
        $items = [new CompletionItem(str_repeat('x', times: 30), 'insert', 'variable-long-kind', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 20, rows: 20, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(16, $box->width);
    }

    public function testCompletionPopupSitsExactlyBelowWhenTheHeightFitsExactly(): void
    {
        // 1 item -> framedHeight = 1 + CHROME_ROWS(2) = 3. rows(4): the
        // cursorScreenRow(0)+1+3 = 4 <= 4 boundary — must still be "below".
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 4, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 4, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(2, $box->startRow); // below: borderTop (cursorScreenRow+1=1) + 1 for the box's own border
    }

    public function testCompletionPopupIsNotBelowWhenTheHeightFallsShortByOne(): void
    {
        // rows(3): cursorScreenRow(0)+1+framedHeight(3) = 4 > 3 — a "+0"
        // mutant would wrongly compute 3<=3 and stay "below" instead of
        // flipping above.
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 3, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 3, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(1, $box->startRow); // flipped above: max(0, 0-3)=0, +1 for the border
    }

    public function testCompletionPopupBorderColClampsAtZeroNotBelow(): void
    {
        // anchorCol(0) - 1 = -1: must clamp up to 0, not stay negative.
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 0, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(1, $box->col); // borderCol(0) + 1
    }

    public function testCompletionPopupBorderColIsNotClampedWhenAlreadyNonNegative(): void
    {
        // anchorCol(1) - 1 = 0: already at the floor, must not be pushed to 1.
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 40, rows: 20, cursorScreenRow: 0, anchorCol: 1, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(1, $box->col); // borderCol(0) + 1
    }

    public function testCompletionPopupBorderColClampsAtTheRightEdge(): void
    {
        // anchorCol(19) - 1 = 18, but framedWidth(14) leaves only col 6 as
        // the rightmost valid border column in a 20-column pane.
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 20, value: str_pad('', length: 40));
        $box = EditorRenderer::completionPopup($lines, columns: 20, rows: 20, cursorScreenRow: 0, anchorCol: 19, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertSame(7, $box->col); // borderCol(6) + 1
    }

    public function testCompletionPopupTopBorderRowIsNotSkippedWhenBorderTopIsZero(): void
    {
        // Flips above with no headroom at all: borderTop = max(0, 0-3) = 0.
        $items = [new CompletionItem('a', 'a', 'var', '')];
        $lines = array_fill(start_index: 0, count: 1, value: str_pad('', length: 40));
        EditorRenderer::completionPopup($lines, columns: 40, rows: 1, cursorScreenRow: 0, anchorCol: 2, items: $items, selected: 0, hovered: null, styles: $this->styles());
        $this->assertStringContainsString('╭', $lines[0]);
    }
}
