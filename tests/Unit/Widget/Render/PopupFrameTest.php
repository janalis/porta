<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
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
        $long = PopupFrame::rail(str_repeat('x', times: 40), 6, Styles::dark());

        $this->assertSame('│ab    │', $short, 'short content pads to the inner width');
        $this->assertSame(6 + PopupFrame::CHROME_COLS, AnsiUtils::visibleWidth($long), 'wide content clips to the inner width');
    }

    public function testEveryWrappedRowIsExactlyTheBoxWidth(): void
    {
        $rows = PopupFrame::wrapMenu(
            [
                ['segment' => 'Open', 'separator' => false],
                ['segment' => '', 'separator' => true],
                ['segment' => str_repeat('w', times: 50), 'separator' => false],
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
        $rows = array_map(AnsiUtils::stripAnsiCodes(...), PopupFrame::wrapMenu(
            [
                ['segment' => 'Cut ', 'separator' => false],
                ['segment' => '', 'separator' => true],
                ['segment' => 'Copy', 'separator' => false],
            ],
            4,
            Styles::dark(),
        ));

        $this->assertSame(['╭────╮', '│Cut │', '├────┤', '│Copy│', '╰────╯'], $rows);
        $this->assertSame(3 + PopupFrame::CHROME_ROWS, \count($rows), 'the chrome constants match the emitted frame');
    }

    /** A degenerate width still closes the box instead of underflowing. */
    public function testADegenerateWidthKeepsTheInvariant(): void
    {
        $this->assertSame('╭╮', AnsiUtils::stripAnsiCodes(PopupFrame::top(-3, Styles::dark())));
        $this->assertSame('││', AnsiUtils::stripAnsiCodes(PopupFrame::rail('x', -3, Styles::dark())));
    }
}
