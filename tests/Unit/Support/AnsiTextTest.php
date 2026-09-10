<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class AnsiTextTest extends TestCase
{
    /**
     * A path cut from the right names nothing — `src/Some/Deep/…` is every
     * file in that directory — so the budget is spent on both ends.
     */
    public function testElideMiddleKeepsBothEndsOfAPath(): void
    {
        $path = 'src/Some/Deep/Namespace/ExampleClass.php';

        $this->assertSame($path, AnsiText::elideMiddle($path, 40), 'nothing to cut, byte-identical');
        $this->assertSame('src/Some/De…pleClass.php', AnsiText::elideMiddle($path, 24));
        $this->assertSame(24, AnsiUtils::visibleWidth(AnsiText::elideMiddle($path, 24)));
    }

    /** Below the mark's own width there are no two ends to keep, so it degrades to the ordinary cut. */
    public function testElideMiddleFallsBackWhenThereIsNoRoomForTwoEnds(): void
    {
        $this->assertSame('…', AnsiText::elideMiddle('abcdef', 1));
        $this->assertSame(1, AnsiUtils::visibleWidth(AnsiText::elideMiddle('abcdef', 1)));
    }

    /**
     * Sliced by COLUMN on both sides, so a double-width grapheme landing on
     * either cut cannot hand back a string one column wider than asked — the
     * classic way a chrome row overflows and garbles the line beneath it.
     */
    public function testElideMiddleNeverExceedsTheAskedWidthAcrossWideGraphemes(): void
    {
        $wide = str_repeat('漢', times: 12).'end.php';
        foreach (range(start: 2, end: 30) as $width) {
            $got = AnsiUtils::visibleWidth(AnsiText::elideMiddle($wide, $width));
            $this->assertLessThanOrEqual($width, $got, "width {$width}");
            // And graded from BELOW as well, which the first version was not:
            // every arithmetic slip in here makes the result NARROWER, and a
            // one-way `<= $width` passes all of them. Two columns of slack and
            // no more — BOTH cuts can land inside a double-width grapheme, and
            // each one that does costs a column. (One was the first guess here,
            // and width 16 on this string disproved it: 14.)
            $this->assertGreaterThanOrEqual($width - 2, $got, "width {$width} came back short");
        }
    }

    /** With no wide graphemes there is no boundary to lose, so the fit is exact. */
    public function testElideMiddleSpendsTheWholeBudgetOnAsciiText(): void
    {
        $path = 'src/Some/Deep/Namespace/ExampleClass.php';
        foreach (range(start: 2, end: 39) as $width) {
            $this->assertSame($width, AnsiUtils::visibleWidth(AnsiText::elideMiddle($path, $width)), "width {$width}");
        }
    }

    public function testTruncateMarksTheCutWithTheVocabularysEllipsis(): void
    {
        // The point of the wrapper: the vendor's own default is '...', so a
        // site that forgets the mark would silently render three ASCII dots.
        $this->assertSame('abc…', AnsiText::truncate('abcdefgh', 4));
        $this->assertSame('abcd', AnsiText::truncate('abcd', 4), 'a line that fits is untouched');
    }

    public function testTruncatePadsOnlyWhenAsked(): void
    {
        $this->assertSame('ab   ', AnsiText::truncate('ab', 5, pad: true));
        $this->assertSame('ab', AnsiText::truncate('ab', 5), 'unpadded by default');
    }

    public function testTruncateTakesAnEmptyMarkForTheDeliberateNoMarkSites(): void
    {
        $this->assertSame('abcd', AnsiText::truncate('abcdefgh', 4, ellipsis: ''));
        $this->assertSame('abcd', AnsiText::truncate('abcdefgh', 4, pad: true, ellipsis: ''));
    }

    public function testTruncateMeasuresDisplayColumnsNotBytes(): void
    {
        $this->assertSame(6, AnsiUtils::visibleWidth(AnsiText::truncate('日本語', 6)), 'three wide glyphs fit exactly');
        // A wide glyph cannot straddle the cut, so the result can land one
        // column SHORT of the width — never over it, which is what would
        // garble the row. Pad when the caller needs the column filled.
        $this->assertSame(3, AnsiUtils::visibleWidth(AnsiText::truncate('日本語', 4)), '日 + the mark; 本 will not fit in the remaining column');
        $this->assertSame(4, AnsiUtils::visibleWidth(AnsiText::truncate('日本語', 4, pad: true)), 'padding fills the short column');
    }

    public function testTruncateNeverExceedsTheRequestedWidth(): void
    {
        // Over-wide is the failure that garbles a row; assert it for the
        // awkward widths (around the mark, around a wide glyph's straddle).
        foreach (['abcdefgh', '日本語', 'a日b語c', ''] as $text) {
            for ($width = 0; $width <= 8; ++$width) {
                $this->assertLessThanOrEqual($width, AnsiUtils::visibleWidth(AnsiText::truncate($text, $width)), "{$text} @ {$width}");
                $this->assertLessThanOrEqual($width, AnsiUtils::visibleWidth(AnsiText::truncate($text, $width, pad: true)), "{$text} @ {$width} padded");
            }
        }
    }

    public function testCaretWindowKeepsShortTextIntactWithTheMarkerAtTheCaret(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $this->assertSame('abc'.$m, AnsiText::caretWindow('abc', 3, 10, $m), 'caret at end');
        $this->assertSame('a'.$m.'bc', AnsiText::caretWindow('abc', 1, 10, $m), 'caret mid-line');
    }

    public function testCaretWindowScrollsToTheTailWhenTheCaretIsAtTheEnd(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('0123456789', 10, 5, $m);
        $this->assertSame('6789'.$m, $out, 'shows the trailing window with the marker');
        $this->assertLessThanOrEqual(5, AnsiUtils::visibleWidth($out));
    }

    public function testCaretWindowBracketsAMidLineCaret(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('0123456789', 5, 5, $m);
        $this->assertSame('1234'.$m.'5', $out);
        $this->assertLessThanOrEqual(5, AnsiUtils::visibleWidth($out));
    }

    public function testCaretWindowHandlesWideCharacters(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('あいうえお', 10, 3, $m); // 5 wide chars = 10 columns, caret at end
        $this->assertSame(1, substr_count($out, $m), 'exactly one marker');
        $this->assertLessThanOrEqual(3, AnsiUtils::visibleWidth($out));
    }

    public function testScrollLineToCaretClipsAndPadsAMarkerlessLine(): void
    {
        $out = AnsiText::scrollLineToCaret('a short line', 20);

        $this->assertSame(20, AnsiUtils::visibleWidth($out), 'padded to width');
        $this->assertStringStartsWith('a short line', AnsiUtils::stripAnsiCodes($out), 'clipped from the start');
    }

    public function testScrollLineToCaretScrollsAMidLineCaretIntoView(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        // "Find: " + long query + marker at the caret, then trailing chrome.
        $line = 'Find: '.str_repeat('q', times: 40).$m.'  [0/0]';

        $out = AnsiText::scrollLineToCaret($line, 20);

        $this->assertSame(1, substr_count($out, $m), 'the caret survives');
        $this->assertSame(20, AnsiUtils::visibleWidth($out), 'clipped and padded to width');
        // The window scrolled past the label to keep the caret visible.
        $this->assertStringNotContainsString('Find:', AnsiUtils::stripAnsiCodes($out));
    }

    public function testScrollLineToCaretDiscoversAnyMarkerShape(): void
    {
        // The marker is found by its APC prefix, whatever the configured shape.
        $block = AnsiUtils::cursorMarker(CursorShape::Block);
        $out = AnsiText::scrollLineToCaret('ab'.$block.'cd', 10);

        $this->assertSame(1, substr_count($out, $block), 'a block marker survives untouched');
        $this->assertSame(10, AnsiUtils::visibleWidth($out));
    }

    public function testScrollLineToCaretTreatsAnUnterminatedMarkerPrefixAsPlainText(): void
    {
        $out = AnsiText::scrollLineToCaret('ab'.AnsiUtils::CURSOR_MARKER_PREFIX, 10);

        $this->assertSame(10, AnsiUtils::visibleWidth($out), 'falls back to a plain clip');
    }

    public function testPadsWithTrailingSpacesToReachTheWidth(): void
    {
        $this->assertSame('ab   ', AnsiText::padRight('ab', 5));
    }

    public function testIsANoOpWhenAlreadyAtLeastAsWide(): void
    {
        $this->assertSame('abcd', AnsiText::padRight('abcd', 4));
        $this->assertSame('abcdef', AnsiText::padRight('abcdef', 4));
    }

    public function testCountsVisibleWidthOnlyIgnoringSgrCodes(): void
    {
        // "ab" styled is two visible columns, so it pads to five.
        $this->assertSame("\x1b[31mab\x1b[0m   ", AnsiText::padRight("\x1b[31mab\x1b[0m", 5));
    }

    public function testWrapBreaksGreedilyOnSpaces(): void
    {
        $this->assertSame(['aa bb', 'cc'], AnsiText::wrap('aa bb cc', 5));
    }

    public function testWrapKeepsAnExactFitOnOneLine(): void
    {
        $this->assertSame(['aa bb'], AnsiText::wrap('aa bb', 5));
    }

    /** A single word wider than a whole line hard-breaks rather than overflows. */
    public function testWrapHardBreaksAnOverWideWord(): void
    {
        $this->assertSame(['abcde', 'fg'], AnsiText::wrap('abcdefg', 5));
        $this->assertSame(['aa', 'bbbb', 'bb', 'cc'], AnsiText::wrap('aa bbbbbb cc', 4));
    }

    public function testWrapMeasuresDisplayColumnsNotBytes(): void
    {
        // Two CJK characters are four columns, so only one fits per three-column line.
        $this->assertSame(['编', '辑'], AnsiText::wrap('编辑', 3));
    }

    /** A glyph wider than the whole line cannot break — it passes through for the caller's clip. */
    public function testWrapPassesThroughAGlyphWiderThanTheLine(): void
    {
        $this->assertSame(['编'], AnsiText::wrap('编', 1));
    }

    public function testWrapOfAnEmptyStringIsOneEmptyLine(): void
    {
        $this->assertSame([''], AnsiText::wrap('', 10));
    }

    public function testWrapClampsANonPositiveWidth(): void
    {
        $this->assertSame(['a', 'b'], AnsiText::wrap('a b', 0));
    }

    public function testDimWrapsAPlainLine(): void
    {
        $this->assertSame("\x1b[2mplain\x1b[22m", AnsiText::dim('plain'));
    }

    public function testDimReArmsAfterResets(): void
    {
        $this->assertSame(
            "\x1b[2mA\x1b[0m\x1b[2mB\x1b[22m\x1b[2mC\x1b[m\x1b[2mD\x1b[22m",
            AnsiText::dim("A\x1b[0mB\x1b[22mC\x1b[mD"),
            'faint re-arms after 0, 22 and the bare-reset sequence',
        );
        $this->assertSame(
            "\x1b[2mA\x1b[0;22m\x1b[2mB\x1b[22m",
            AnsiText::dim("A\x1b[0;22mB"),
            'params are matched as whole list entries',
        );
    }

    public function testDimLeavesNonResettingSequencesAlone(): void
    {
        $this->assertSame(
            "\x1b[2mA\x1b[1;2mB\x1b[38;5;220mC\x1b[22m",
            AnsiText::dim("A\x1b[1;2mB\x1b[38;5;220mC"),
            "a '2' inside 1;2 or a color's 5;2… must never count as a reset",
        );
    }

    /** The APC caret marker is a different escape family — byte-identical passthrough. */
    public function testDimPassesTheCaretMarkerThrough(): void
    {
        $marker = AnsiUtils::cursorMarker();
        $dimmed = AnsiText::dim('ab'.$marker.'cd');

        $this->assertStringContainsString($marker, $dimmed);
        $this->assertSame(4, AnsiUtils::visibleWidth($dimmed), 'zero-width codes only');
    }
}
