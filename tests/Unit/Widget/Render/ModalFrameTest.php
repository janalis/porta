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
        $this->assertSame(24, mb_strwidth($bar));
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

            $this->assertSame(Glyphs::CLOSE, mb_substr($bar, $width - 2, 1), "width {$width}");
            $this->assertSame(Glyphs::MODAL_TOP_RIGHT, mb_substr($bar, $width - 1, 1));
        }
    }

    /** A narrow box drops the hint before it crushes the title. */
    public function testTheHintYieldsOnANarrowBox(): void
    {
        $bar = $this->plainBar('Setup', 14);

        $this->assertStringNotContainsString('Esc', $bar);
        $this->assertStringContainsString('Setup', $bar);
        $this->assertSame(14, mb_strwidth($bar));
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
    }

    public function testWrapPadsRailsTheContentAndClosesTheBox(): void
    {
        $rows = array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrap(['hello', 'a line that is far too wide for ten'], 10, Styles::dark()));

        $this->assertSame('│            │', $rows[0], 'a padding row under the title bar');
        $this->assertSame('│ hello      │', $rows[1], 'content padded to the inner width, one column off each rail');
        $this->assertSame(14, mb_strwidth($rows[2]), 'wide content clips to the inner width');
        $this->assertSame('│            │', $rows[3], 'a padding row above the border');
        $this->assertSame('╰────────────╯', $rows[4], 'the bottom border closes the box');
    }

    public function testEveryWrappedRowIsExactlyTheBoxWidth(): void
    {
        foreach (ModalFrame::wrap(['a', str_repeat('x', 50)], 20, Styles::dark()) as $i => $row) {
            $this->assertSame(20 + ModalFrame::CHROME_COLS, AnsiUtils::visibleWidth($row), "row {$i}");
        }
    }

    /** Sections joined by tee rows — the zones read as zones of one box. */
    public function testWrapSectionsJoinsSectionsWithTeeRows(): void
    {
        $rows = array_map(AnsiUtils::stripAnsiCodes(...), ModalFrame::wrapSections([['top'], ['mid'], ['end']], 6, Styles::dark()));

        $this->assertSame(
            ['│        │', '│ top    │', '├────────┤', '│ mid    │', '├────────┤', '│ end    │', '│        │', '╰────────╯'],
            $rows,
        );
    }

    /** The chrome constants match what wrap() actually emits — consumers derive from them. */
    public function testChromeConstantsMatchTheEmittedFrame(): void
    {
        $body = ModalFrame::wrap(['x'], 8, Styles::dark());

        $this->assertSame(1 + ModalFrame::CHROME_ROWS, 1 + \count($body), 'title bar + body = content + chrome rows');
        $this->assertSame(8 + ModalFrame::CHROME_COLS, AnsiUtils::visibleWidth($body[0]));
        $this->assertSame('x', trim(AnsiUtils::stripAnsiCodes($body[ModalFrame::CHROME_ROWS_TOP - 1]), ' │'), 'content starts CHROME_ROWS_TOP below the box top');
    }
}
