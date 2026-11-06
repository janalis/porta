<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\TextMetrics;
use Porta\Widget\Render\CursorVisibility;
use Porta\Widget\Render\EditorRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TextMetricsTest extends TestCase
{
    public function testCharBoundariesWalkMultibyte(): void
    {
        $line = 'aé好b'; // a(1) é(2) 好(3) b(1)
        $this->assertSame(1, TextMetrics::nextCharBoundary($line, 0));
        $this->assertSame(3, TextMetrics::nextCharBoundary($line, 1), 'skips the 2-byte é');
        $this->assertSame(6, TextMetrics::nextCharBoundary($line, 3), 'skips the 3-byte 好');

        $this->assertSame(3, TextMetrics::prevCharBoundary($line, 6));
        $this->assertSame(1, TextMetrics::prevCharBoundary($line, 3));
        $this->assertSame(0, TextMetrics::prevCharBoundary($line, 1));
        // Already at the start: the floor is max(0, ...), not max(-1, ...).
        $this->assertSame(0, TextMetrics::prevCharBoundary($line, 0));
    }

    /** A line that is not UTF-8 is a character a byte: Latin-1's `°` (0xB0) is no continuation byte. */
    public function testCharBoundaryAtOrBeforeTakesANonUtf8LineByteByByte(): void
    {
        $this->assertSame(3, TextMetrics::charBoundaryAtOrBefore("20\xB0C", 3));
        $this->assertSame(2, TextMetrics::charBoundaryAtOrBefore("x\x93y\x94", 2), 'Windows-1252 quotes');
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore("\x80\x80", 1));
    }

    public function testCharBoundaryAtOrBeforeRecognizesA0x81ContinuationByte(): void
    {
        // U+0081 encodes as C2 81: the continuation byte 0x81 must still match
        // the 10xxxxxx mask (checked against 0xC0, not a shifted bit pattern).
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore("a\xC2\x81b", 2));
    }

    public function testDisplayWidthExpandsTabs(): void
    {
        $this->assertSame(0, TextMetrics::displayWidth('', 4));
        $this->assertSame(5, TextMetrics::displayWidth('hello', 4), 'ascii fast path');
        // "a" then tab → next stop at 4, so width 4; plus "b" → 5
        $this->assertSame(5, TextMetrics::displayWidth("a\tb", 4));
        $this->assertSame(4, TextMetrics::displayWidth("\t", 4));
        // Minimum legal tab width (IndentModel clamps to at least 1): the
        // running width must start at 0, not -1.
        $this->assertSame(1, TextMetrics::displayWidth("\t", 1));
    }

    public function testExpandTabsProducesSpacesToStops(): void
    {
        $this->assertSame('a   b', TextMetrics::expandTabs("a\tb", 4));
        $this->assertSame('    x', TextMetrics::expandTabs("\tx", 4));
        // A second tab must be measured from the running width left by the
        // first, not overwrite/undo it.
        $this->assertSame('a       b', TextMetrics::expandTabs("a\t\tb", 4));
    }

    public function testByteColForDisplayColHandlesTabsAndAscii(): void
    {
        $this->assertSame(0, TextMetrics::byteColForDisplayCol('', 5, 4));
        $this->assertSame(3, TextMetrics::byteColForDisplayCol('abcdef', 3, 4), 'ascii');
        // "a\tb": display col 4 lands just after the tab (byte 2)
        $this->assertSame(2, TextMetrics::byteColForDisplayCol("a\tb", 4, 4));
        // Negative display column on a non-empty line: still clamps to 0
        // (the guard is `|| '' === $line`, not `&& '' === $line`).
        $this->assertSame(0, TextMetrics::byteColForDisplayCol('abc', -3, 4));
        // Non-ASCII text forces the manual scan (not the ASCII fast path):
        // 'é'(w1) + tab-to-stop-4(w3) + 'x'(w1) = width 5 at byte 4.
        $this->assertSame(4, TextMetrics::byteColForDisplayCol("é\tx", 5, 4));
        // The scan starts at col 0, not -1: asking for a column inside the
        // first character must not skip past it.
        $this->assertSame(2, TextMetrics::byteColForDisplayCol("é\tx", 1, 4));
        // Asking past the achievable width clamps at the string's actual
        // length; the loop must stop there, not scan one byte beyond it.
        $this->assertSame(4, TextMetrics::byteColForDisplayCol("é\tx", 100, 4));
    }

    public function testUtf16RoundTrip(): void
    {
        $line = 'x😀y'; // 😀 is 4 UTF-8 bytes / 2 UTF-16 code units
        $u = TextMetrics::byteColToUtf16($line, \strlen($line));
        $this->assertSame(4, $u, 'x(1) + 😀(2) + y(1) = 4 UTF-16 units');
        $this->assertSame(\strlen($line), TextMetrics::utf16ColToByteCol($line, $u));
        $this->assertSame(0, TextMetrics::byteColToUtf16($line, 0));
        $this->assertSame(0, TextMetrics::utf16ColToByteCol($line, 0));
        // Negative UTF-16 column on a non-empty line: still clamps to 0 (the
        // guard is `|| '' === $line`, not `&& '' === $line`).
        $this->assertSame(0, TextMetrics::utf16ColToByteCol('abc', -3));
        // Non-ASCII text forces the manual scan (not the ASCII fast path).
        $this->assertSame(4, TextMetrics::utf16ColToByteCol("é\tx", 5));
    }

    /**
     * offsetAt / positionAt on Document round-trip for every position — the
     * property the widget's structural selection relies on.
     */
    public function testDocumentOffsetPositionRoundTrip(): void
    {
        $doc = new Document("abc\n\ndéf\nghi");
        $text = $doc->getText();

        for ($offset = 0, $length = \strlen($text); $offset <= $length; ++$offset) {
            $pos = $doc->positionAt($offset);
            $back = $doc->offsetAt($pos['line'], $pos['col']);
            // Offsets that land mid-multibyte-char clamp to a boundary, but every
            // offset that IS a valid boundary must round-trip exactly.
            $this->assertSame(
                $doc->positionAt($back),
                $pos,
                "position stable through offset {$offset}",
            );
        }
    }

    public function testDocumentOffsetAtClampsOutOfRange(): void
    {
        $doc = new Document("ab\ncd");
        $this->assertSame(0, $doc->offsetAt(-5, -5));
        // Beyond the end clamps to the last line's end (offset of 'cd' end = 5).
        $this->assertSame(5, $doc->offsetAt(99, 99));
    }

    public function testCharBoundaryAtOrBeforeSnapsOffMidCharacterBytes(): void
    {
        // "a—b": 'a' (1) + em-dash (3 bytes: 2..4) + 'b' (5).
        $line = "a\u{2014}b";
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 1), 'already a boundary');
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 2), 'mid em-dash → its start');
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 3), 'mid em-dash → its start');
        $this->assertSame(4, TextMetrics::charBoundaryAtOrBefore($line, 4), 'after the em-dash');
        $this->assertSame(5, TextMetrics::charBoundaryAtOrBefore($line, 99), 'past the end clamps to length');
        $this->assertSame(0, TextMetrics::charBoundaryAtOrBefore($line, -3), 'before the start clamps to 0');
    }

    public function testGraphemeStepsCrossAWholeDrawnCharacter(): void
    {
        $accent = "ae\u{0301}b"; // e + combining acute: bytes 1-3
        $this->assertSame(4, TextMetrics::nextGrapheme($accent, 1));
        $this->assertSame(1, TextMetrics::prevGrapheme($accent, 4));

        $family = "a\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}b"; // one ZWJ grapheme, bytes 1-18
        $this->assertSame(19, TextMetrics::nextGrapheme($family, 1));
        $this->assertSame(1, TextMetrics::prevGrapheme($family, 19));
    }

    public function testGraphemeStepsStopAtTheLineEnds(): void
    {
        $this->assertSame(2, TextMetrics::nextGrapheme('ab', 2));
        $this->assertSame(0, TextMetrics::prevGrapheme('ab', 0));
    }

    public function testGraphemeStepsFallBackToCodePointsOnInvalidUtf8(): void
    {
        $this->assertSame(2, TextMetrics::prevGrapheme("ab\xff", 3));
        $this->assertSame(3, TextMetrics::nextGrapheme("ab\xffc", 2), 'the bad byte alone, not it and the c after it');
    }

    public function testADisplayColumnNeverMapsInsideAGrapheme(): void
    {
        // é written as e + U+0301: the mark is zero columns wide, so column 2
        // ends at the e — the byte must come after the mark, not between.
        $this->assertSame(4, TextMetrics::byteColForDisplayCol("ae\u{0301}b", 2, 4));
        $this->assertSame(1, TextMetrics::byteColForDisplayCol("ae\u{0301}b", 1, 4), 'a boundary already between graphemes stays');
        $this->assertSame(3, TextMetrics::byteColForDisplayCol("ab\xffc", 3, 4), 'invalid UTF-8 keeps the code-point answer');
    }

    /** @return iterable<string, array{string}> graphemes that summing code points measures too wide */
    public static function clusters(): iterable
    {
        yield 'a decomposed accent' => ["e\u{0301}"];
        yield 'hebrew niqqud' => ["\u{05E9}\u{05B8}"];
        yield 'a ZWJ family' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
        yield 'a skin tone' => ["\u{1F44D}\u{1F3FD}"];
        yield 'hangul jamo' => ["\u{1100}\u{1161}\u{11A8}"];
    }

    #[DataProvider('clusters')]
    public function testAGraphemeIsAsWideAsTheTerminalDrawsIt(string $cluster): void
    {
        $drawn = AnsiUtils::visibleWidth($cluster);

        $this->assertSame($drawn + 1, TextMetrics::displayWidth("a{$cluster}", 4));
        $this->assertSame(4 + $drawn + 1, TextMetrics::displayWidth("\t{$cluster}b", 4), 'the slow path, taken for the tab');
        $this->assertSame("    {$cluster}".\str_repeat(' ', times: 4 - $drawn).'b', TextMetrics::expandTabs("\t{$cluster}\tb", 4), 'the next tab stop counts the grapheme as drawn');
        $this->assertSame(1 + \strlen($cluster), TextMetrics::byteColForDisplayCol("a{$cluster}bc", 1 + $drawn, 4), 'the column after it is the byte after it');
    }

    #[DataProvider('clusters')]
    public function testTheCaretIsPaintedWhereTheDocumentPutsIt(string $cluster): void
    {
        // The symptom: a caret before the `x` was painted after it, one column
        // late per mark and six after a ZWJ family.
        $line = "{$cluster}xyz";
        $caret = TextMetrics::displayWidth($cluster, 4);

        $painted = AnsiUtils::stripAnsiCodes(EditorRenderer::renderContentLine(0, $line, 20, CursorVisibility::Shown, $caret, '|'));

        $this->assertSame("{$cluster}|xyz", \rtrim($painted));
    }
}
