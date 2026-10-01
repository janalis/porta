<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\WindowZone;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\HighContrastTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ModalFrame;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The pooled modal frame: every row exactly the requested width, the close
 * button pinned at the box's edge, and the wrap rails enforcing the content
 * width in one place.
 */
final class ModalFrameTest extends TestCase
{
    private function plainBar(string $title, int $width, ?WindowZone $hot = null): string
    {
        return AnsiUtils::stripAnsiCodes(ModalFrame::titleBar($title, $width, Styles::dark(), $hot));
    }

    public function testTheTitleBarIsExactlyTheRequestedWidth(): void
    {
        foreach ([12, 24, 60] as $width) {
            $bar = ModalFrame::titleBar('Welcome', $width, Styles::dark());

            $this->assertSame($width, AnsiUtils::visibleWidth($bar), "width {$width}");
        }
    }

    public function testTheBarCarriesCornersTitleAndClose(): void
    {
        $bar = $this->plainBar('Welcome', 24);

        $this->assertSame('╭─ Welcome ───────────☒╮', $bar, 'the full bar layout: the rule into both corners, no Esc spelled out');
        $this->assertSame(24, \mb_strwidth($bar));
    }

    /** The ☒ sits at width − 2 in every layout — the geometry ModalBox::closeHit mirrors. */
    public function testTheCloseGlyphSitsAtTheCloseOffset(): void
    {
        foreach ([
            ['Welcome',                              40],
            ['A title too long for this narrow box', 18],
            ['T',                                    10],
        ] as [$title, $width]) {
            $bar = $this->plainBar($title, $width);

            $this->assertSame(Glyphs::WINDOW_CLOSE, \mb_substr($bar, $width - 2, length: 1), "width {$width}");
            $this->assertSame(Glyphs::MODAL_TOP_RIGHT, \mb_substr($bar, $width - 1, length: 1));
        }
    }

    /** A title too long for the box yields to the chrome, never the button. */
    public function testALongTitleYieldsToTheChrome(): void
    {
        $bar = $this->plainBar('Settings and more', 14);

        $this->assertSame('╭─ Settin… ─☒╮', $bar);
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
        $hovered = ModalFrame::titleBar('Welcome', 24, Styles::dark(), hot: WindowZone::Close);

        $this->assertNotSame($normal, $hovered, 'the ☒ restyles under the pointer');
        $this->assertSame(AnsiUtils::stripAnsiCodes($normal), AnsiUtils::stripAnsiCodes($hovered), 'same text, different shade');
        // Direction, not just difference: hovered darkens to the deep red, with
        // no fill behind it, and the resting ☒ is the faint one.
        $this->assertStringContainsString(Styles::dark()->of(Role::DiagErrorDim)->apply(Glyphs::WINDOW_CLOSE), $hovered);
        $this->assertStringContainsString(Styles::dark()->of(Role::Gutter)->apply(Glyphs::WINDOW_CLOSE), $normal);
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

    /**
     * Each resize handle heavies under the pointer, and only it: the frame of
     * a 6-wide, one-row box, as plain text, with $zone hot.
     *
     * @return iterable<string, array{WindowZone, list<string>}>
     */
    public static function hotHandles(): iterable
    {
        yield 'nothing hot' => [WindowZone::Content, ['╭─  ☒╮', '│    │', '│ x  │', '│    │', '╰────╯']];
        yield 'the top-left corner' => [WindowZone::TopLeft, ['┏─  ☒╮', '│    │', '│ x  │', '│    │', '╰────╯']];
        yield 'the top-right corner' => [WindowZone::TopRight, ['╭─  ☒┓', '│    │', '│ x  │', '│    │', '╰────╯']];
        yield 'the left rail' => [WindowZone::Left, ['╭─  ☒╮', '┃    │', '┃ x  │', '┃    │', '╰────╯']];
        yield 'the right rail' => [WindowZone::Right, ['╭─  ☒╮', '│    ┃', '│ x  ┃', '│    ┃', '╰────╯']];
        yield 'the bottom border' => [WindowZone::Bottom, ['╭─  ☒╮', '│    │', '│ x  │', '│    │', '╰━━━━╯']];
        yield 'the bottom-left corner' => [WindowZone::BottomLeft, ['╭─  ☒╮', '│    │', '│ x  │', '│    │', '┗────╯']];
        yield 'the bottom-right corner' => [WindowZone::BottomRight, ['╭─  ☒╮', '│    │', '│ x  │', '│    │', '╰────┛']];
    }

    /** @param list<string> $expected */
    #[DataProvider('hotHandles')]
    public function testOnlyTheHotHandleHeavies(WindowZone $hot, array $expected): void
    {
        $rows = [ModalFrame::titleBar('', 6, Styles::dark(), $hot), ...ModalFrame::wrap(['x'], 2, Styles::dark(), $hot)];

        $this->assertSame($expected, \array_map(AnsiUtils::stripAnsiCodes(...), $rows));
    }

    public function testAHotHandleIsBrightAndBold(): void
    {
        $hot = ModalFrame::wrap(['x'], 2, Styles::dark(), WindowZone::Left)[1];

        $this->assertStringContainsString(Styles::dark()->of(Role::Bright)->withBold()->apply(Glyphs::MODAL_GRAB_VERTICAL), $hot);
    }

    public function testOnALeftClosePlatformTheCloseLeadsTheTitleBar(): void
    {
        $bar = AnsiUtils::stripAnsiCodes(ModalFrame::titleBar('Settings', 24, Styles::dark(), closeButton: CloseButton::Mac));

        $this->assertSame('╭⊗───── Settings ──────╮', $bar, 'the button first, the title in the middle, the rule into the corner');
        $this->assertSame(24, AnsiUtils::visibleWidth($bar));

        $narrow = AnsiUtils::stripAnsiCodes(ModalFrame::titleBar('Settings', 16, Styles::dark(), closeButton: CloseButton::Mac));
        $this->assertSame('╭⊗─ Settings ──╮', $narrow, 'too little fill to centre it: the title leads');
        $this->assertSame(16, AnsiUtils::visibleWidth($narrow));
    }

    public function testTheMacButtonIsRedAndDarkensUnderThePointer(): void
    {
        $red = Styles::dark()->of(Role::DiagError);
        $resting = ModalFrame::titleBar('Settings', 24, Styles::dark(), closeButton: CloseButton::Mac);
        $hovered = ModalFrame::titleBar('Settings', 24, Styles::dark(), WindowZone::Close, closeButton: CloseButton::Mac);

        $this->assertStringContainsString($red->apply(Glyphs::WINDOW_CLOSE_MAC), $resting, 'red at rest');
        $this->assertStringContainsString(Styles::dark()->of(Role::DiagErrorDim)->apply(Glyphs::WINDOW_CLOSE_MAC), $hovered, 'darkened under the pointer, with no fill');
    }

    /** Without colour there is no deep red: the hover dims, so it still reads. */
    public function testWithoutColourTheHoverDims(): void
    {
        $plain = new Styles(MonochromeTheme::definition());
        $hovered = ModalFrame::titleBar('Settings', 24, $plain, WindowZone::Close);

        $this->assertStringContainsString($plain->of(Role::Gutter)->withDim()->apply(Glyphs::WINDOW_CLOSE), $hovered);
    }

    /** High contrast's deep red is its red: the hover dims instead, so it still reads. */
    public function testAThemeWithOneRedDarkensByDimming(): void
    {
        $contrast = new Styles(HighContrastTheme::definition());
        $hovered = ModalFrame::titleBar('Settings', 24, $contrast, WindowZone::Close, closeButton: CloseButton::Mac);

        $this->assertStringContainsString($contrast->of(Role::DiagError)->withDim()->apply(Glyphs::WINDOW_CLOSE_MAC), $hovered);
    }
}
