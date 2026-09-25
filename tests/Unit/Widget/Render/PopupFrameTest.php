<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\PopupFrame;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The anchored-popup frame: ModalFrame's glyph vocabulary at tight chrome —
 * every row exactly innerWidth + CHROME_COLS, separators as tee rows, and the
 * one-slot-per-item mapping hit-testing depends on.
 */
final class PopupFrameTest extends TestCase
{
    public function testTheBordersUseTheModalGlyphVocabulary(): void
    {
        $top = AnsiUtils::stripAnsiCodes(PopupFrame::top(4, Styles::dark()));
        $bottom = AnsiUtils::stripAnsiCodes(PopupFrame::bottom(4, Styles::dark()));
        $tee = AnsiUtils::stripAnsiCodes(PopupFrame::tee(4, Styles::dark()));

        $this->assertSame(Glyphs::MODAL_TOP_LEFT.'────'.Glyphs::MODAL_TOP_RIGHT, $top, 'the rounded modal corners, one border cell each side');
        $this->assertSame(Glyphs::MODAL_BOTTOM_LEFT.'────'.Glyphs::MODAL_BOTTOM_RIGHT, $bottom);
        $this->assertSame(Glyphs::MODAL_TEE_LEFT.'────'.Glyphs::MODAL_TEE_RIGHT, $tee);
    }

    public function testRailEnforcesTheInnerWidth(): void
    {
        $short = AnsiUtils::stripAnsiCodes(PopupFrame::rail('ab', 6, Styles::dark()));
        $long = PopupFrame::rail(\str_repeat('x', times: 40), 6, Styles::dark());

        $this->assertSame('│ab    │', $short, 'short content pads to the inner width');
        $this->assertSame(6 + PopupFrame::CHROME_COLS, AnsiUtils::visibleWidth($long), 'wide content clips to the inner width');
    }

    public function testEveryWrappedRowIsExactlyTheBoxWidth(): void
    {
        $rows = PopupFrame::wrapMenu(
            [
                ['segment' => 'Open', 'separator' => false],
                ['segment' => '', 'separator' => true],
                ['segment' => \str_repeat('w', times: 50), 'separator' => false],
            ],
            12,
            Styles::dark(),
        );

        foreach ($rows as $i => $row) {
            $this->assertSame(12 + PopupFrame::CHROME_COLS, AnsiUtils::visibleWidth($row), "row {$i}");
        }
    }

    /** Framed row 1 + i maps to item i — a separator keeps its slot as a tee row. */
    public function testWrapMenuKeepsOneRowPerItemWithSeparatorsAsTees(): void
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), PopupFrame::wrapMenu(
            [
                ['segment' => 'Cut ', 'separator' => false],
                ['segment' => '', 'separator' => true],
                ['segment' => 'Copy', 'separator' => false],
            ],
            4,
            Styles::dark(),
        ));

        $this->assertSame(['╭────╮', '│Cut │', '├────┤', '│Copy│', '╰────╯'], $rows);
        $this->assertCount(3 + PopupFrame::CHROME_ROWS, $rows, 'the chrome constants match the emitted frame');
    }

    /** A degenerate width still closes the box instead of underflowing. */
    public function testADegenerateWidthKeepsTheInvariant(): void
    {
        $this->assertSame('╭╮', AnsiUtils::stripAnsiCodes(PopupFrame::top(-3, Styles::dark())));
        $this->assertSame('││', AnsiUtils::stripAnsiCodes(PopupFrame::rail('x', -3, Styles::dark())));
        // bottom() has no clamping helper underneath it (unlike rail()'s
        // AnsiText::truncate, which already tolerates negative widths) — its own
        // max(0, ...) is the only thing standing between a negative width and a
        // str_repeat() ValueError.
        $this->assertSame('╰╯', AnsiUtils::stripAnsiCodes(PopupFrame::bottom(-3, Styles::dark())));
    }

    /** Exactly zero columns of content: no horizontal filler, not one stray column. */
    public function testZeroInnerWidthAddsNoFillerToTheBottomBorder(): void
    {
        $this->assertSame('╰╯', AnsiUtils::stripAnsiCodes(PopupFrame::bottom(0, Styles::dark())));
    }

    /** A heavy frame's separators are heavy tees, joining the frame they cut across. */
    public function testAHeavyFramesSeparatorIsAHeavyTee(): void
    {
        $rows = PopupFrame::wrapMenu([['segment' => 'a', 'separator' => false], ['segment' => '', 'separator' => true]], 3, Styles::dark(), FieldState::Focused);

        $this->assertSame('┣━━━┫', AnsiUtils::stripAnsiCodes($rows[2]));
        $this->assertSame('├───┤', AnsiUtils::stripAnsiCodes(PopupFrame::tee(3, Styles::dark())), 'the idle frame keeps light tees');
    }

    /**
     * A menu dropped from a title leaves its top border open under it, the
     * title's rails running on into the frame: flush with the box's left
     * edge, in its middle, flush with its right edge.
     */
    public function testTheTopBorderOpensUnderTheTitle(): void
    {
        $top = static fn (int $offset, int $width): string => AnsiUtils::stripAnsiCodes(PopupFrame::topOpenAt(8, Styles::dark(), FieldState::Focused, $offset, $width));

        $this->assertSame('┃    ┗━━━┓', $top(0, width: 6), 'flush left: the box edge is the title\'s left rail');
        $this->assertSame('┏━┛    ┗━┓', $top(2, width: 6), 'a box pushed left of its title turns up into both rails');
        $this->assertSame('┏━━━┛    ┃', $top(4, width: 6), 'flush right: the box edge is the title\'s right rail');
        $this->assertSame('┃        ┃', $top(0, width: 10), 'a title as wide as the box leaves only the edges');
    }

    /** A title the box cannot hold gets the closed border, not a broken one. */
    public function testATitleOutsideTheBoxClosesTheBorder(): void
    {
        $closed = '┏━━━━━━━━┓';
        $top = static fn (int $offset, int $width): string => AnsiUtils::stripAnsiCodes(PopupFrame::topOpenAt(8, Styles::dark(), FieldState::Focused, $offset, $width));

        $this->assertSame($closed, $top(-1, width: 6), 'starts left of the box');
        $this->assertSame($closed, $top(5, width: 6), 'runs past its right edge');
        $this->assertSame($closed, $top(0, width: 1), 'too narrow to have two rails');
        $this->assertSame('┏━━━┛    ┃', $top(4, width: 6), 'the control: a title ending on the box edge opens');
    }

    /** The corners are the frame state's own, so an idle frame opens in its light, rounded glyphs. */
    public function testTheOpeningUsesTheStatesGlyphs(): void
    {
        $this->assertSame('╭─╯  ╰─╮', AnsiUtils::stripAnsiCodes(PopupFrame::topOpenAt(6, Styles::dark(), FieldState::Idle, 2, 4)));
    }

    public function testWrapMenuOpensItsTopBorderOnlyWhenAsked(): void
    {
        $item = [['segment' => 'a', 'separator' => false]];

        $this->assertSame('┃ ┗━┓', AnsiUtils::stripAnsiCodes(PopupFrame::wrapMenu($item, 3, Styles::dark(), FieldState::Focused, ['offset' => 0, 'width' => 3])[0]));
        $this->assertSame('┏━━━┓', AnsiUtils::stripAnsiCodes(PopupFrame::wrapMenu($item, 3, Styles::dark(), FieldState::Focused)[0]));
    }
}
