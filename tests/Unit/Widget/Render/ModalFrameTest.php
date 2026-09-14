<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ModalFrame;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The pooled modal frame: every row exactly the requested width, the × pinned
 * at the box's right edge, the hint yielding on narrow boxes, and the wrap
 * rails enforcing the content width in one place.
 */
final class ModalFrameTest extends TestCase
{
    private function plainBar(string $title, int $width, bool $closeHovered = false, string $hint = 'Esc'): string
    {
        return AnsiUtils::stripAnsiCodes(ModalFrame::titleBar($title, $width, Styles::dark(), $closeHovered, $hint));
    }

    public function testTheTitleBarIsExactlyTheRequestedWidth(): void
    {
        foreach ([12, 24, 60] as $width) {
            $bar = ModalFrame::titleBar('Welcome', $width, Styles::dark());

            $this->assertSame($width, AnsiUtils::visibleWidth($bar), "width {$width}");
        }
    }

    public function testTheBarCarriesCornersTitleHintAndClose(): void
    {
        $bar = $this->plainBar('Welcome', 24);

        $this->assertSame('╭ Welcome ─────── Esc ×╮', $bar, 'the full bar layout');
        $this->assertSame(24, \mb_strwidth($bar));
    }

    /** The × sits at width − 2 in every layout — the geometry ModalBox::closeHit mirrors. */
    public function testTheCloseGlyphSitsAtTheCloseOffset(): void
    {
        foreach ([
            ['Welcome',                              40],
            ['A title too long for this narrow box', 18],
            ['T',                                    10],
        ] as [$title, $width]) {
            $bar = $this->plainBar($title, $width);

            $this->assertSame(Glyphs::CLOSE, \mb_substr($bar, $width - 2, length: 1), "width {$width}");
            $this->assertSame(Glyphs::MODAL_TOP_RIGHT, \mb_substr($bar, $width - 1, length: 1));
        }
    }

    /** room==8 exactly is the boundary: still wide enough to keep the hint. */
    public function testRoomExactlyAtTheHintThresholdKeepsTheHint(): void
    {
        $bar = $this->plainBar('Setup', 18); // room = 18-6-4 = 8
        $this->assertStringContainsString('Esc', $bar);

        $dropped = $this->plainBar('Setup', 17); // room = 7: one column under
        $this->assertStringNotContainsString('Esc', $dropped);
    }

    /** An explicitly empty hint must not itself steal a column from room. */
    public function testAnEmptyHintReservesNoSeparatorColumn(): void
    {
        $withEmptyHint = $this->plainBar('Setup', 18, hint: '');
        $withDefaultHint = $this->plainBar('Setup', 18);

        // Same box width either way, but the empty-hint bar has one more fill
        // column where the "Esc" hint and its separator would have sat.
        $this->assertSame(18, \mb_strwidth($withEmptyHint));
        $this->assertStringNotContainsString('Esc', $withEmptyHint);
        $this->assertSame(\mb_substr_count($withEmptyHint, needle: '─'), \mb_substr_count($withDefaultHint, needle: '─') + 4, 'the reclaimed hint+separator+chrome columns become fill');
    }

    /** A narrow box drops the hint before it crushes the title. */
    public function testTheHintYieldsOnANarrowBox(): void
    {
        $bar = $this->plainBar('Setup', 14);

        $this->assertStringNotContainsString('Esc', $bar);
        $this->assertStringContainsString('Setup', $bar);
        $this->assertSame(14, \mb_strwidth($bar));
    }

    /** A degenerate width still returns exactly that many columns. */
    public function testADegenerateWidthKeepsTheInvariant(): void
    {
        $this->assertSame(4, AnsiUtils::visibleWidth(ModalFrame::titleBar('X', 4, Styles::dark())));
    }

    /** A wide-char (CJK) title truncates by display columns, not characters. */
    public function testAWideCharTitleKeepsTheWidth(): void
    {
        $bar = ModalFrame::titleBar('ようこそポルタへ', 16, Styles::dark());

        $this->assertSame(16, AnsiUtils::visibleWidth($bar));
    }

    public function testHoveringTheCloseBrightensIt(): void
    {
        $normal = ModalFrame::titleBar('Welcome', 24, Styles::dark());
        $hovered = ModalFrame::titleBar('Welcome', 24, Styles::dark(), closeHovered: true);

        $this->assertNotSame($normal, $hovered, 'the × restyles under the pointer');
        $this->assertSame(AnsiUtils::stripAnsiCodes($normal), AnsiUtils::stripAnsiCodes($hovered), 'same text, different shade');
        // Direction, not just difference: hovered must be the BRIGHT variant, not
        // merely "a different one" (which a swapped active/inactive would also be).
        $this->assertStringContainsString(\Porta\Widget\Render\TabCloseGlyph::active(Styles::dark()), $hovered);
        $this->assertStringContainsString(\Porta\Widget\Render\TabCloseGlyph::inactive(Styles::dark()), $normal);
    }

    public function testWrapPadsRailsTheContentAndClosesTheBox(): void
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrap(['hello', 'a line that is far too wide for ten'], 10, Styles::dark()));

        $this->assertSame('│            │', $rows[0], 'a padding row under the title bar');
        $this->assertSame('│ hello      │', $rows[1], 'content padded to the inner width, one column off each rail');
        $this->assertSame(14, \mb_strwidth($rows[2]), 'wide content clips to the inner width');
        $this->assertSame('│            │', $rows[3], 'a padding row above the border');
        $this->assertSame('╰────────────╯', $rows[4], 'the bottom border closes the box');
    }

    public function testEveryWrappedRowIsExactlyTheBoxWidth(): void
    {
        foreach (ModalFrame::wrap(['a', \str_repeat('x', times: 50)], 20, Styles::dark()) as $i => $row) {
            $this->assertSame(20 + ModalFrame::CHROME_COLS, AnsiUtils::visibleWidth($row), "row {$i}");
        }
    }

    /** Sections joined by tee rows — the zones read as zones of one box. */
    public function testWrapSectionsJoinsSectionsWithTeeRows(): void
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrapSections([['top'], ['mid'], ['end']], 6, Styles::dark()));

        $this->assertSame(
            ['│        │', '│ top    │', '├────────┤', '│ mid    │', '├────────┤', '│ end    │', '│        │', '╰────────╯'],
            $rows,
        );
    }

    /** A negative inner width still closes the tee row and bottom border, not a str_repeat() crash. */
    public function testDegenerateInnerWidthKeepsTheeAndBottomBorderClosed(): void
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrapSections([['a'], ['b']], -3, Styles::dark()));

        $this->assertSame('├┤', $rows[2], 'the tee row still closes at a negative inner width');
        $this->assertSame('╰╯', \array_last($rows), 'the bottom border still closes at a negative inner width');
    }

    /** innerWidth + CHROME_COLS-worth-of-fill == 0: no horizontal filler on either the tee row or the bottom border. */
    public function testZeroFillerWidthAddsNoFillerToTeeOrBottomBorder(): void
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrapSections([['a'], ['b']], -2, Styles::dark()));

        $this->assertSame('├┤', $rows[2]);
        $this->assertSame('╰╯', \array_last($rows));
    }

    /** The chrome constants match what wrap() actually emits — consumers derive from them. */
    public function testChromeConstantsMatchTheEmittedFrame(): void
    {
        $body = ModalFrame::wrap(['x'], 8, Styles::dark());

        $this->assertSame(1 + ModalFrame::CHROME_ROWS, 1 + \count($body), 'title bar + body = content + chrome rows');
        $this->assertSame(8 + ModalFrame::CHROME_COLS, AnsiUtils::visibleWidth($body[0]));
        $this->assertSame('x', \trim(AnsiUtils::stripAnsiCodes($body[ModalFrame::CHROME_ROWS_TOP - 1]), characters: ' │'), 'content starts CHROME_ROWS_TOP below the box top');
    }
}
