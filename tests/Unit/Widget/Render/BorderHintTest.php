<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\BorderHint;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class BorderHintTest extends TestCase
{
    /**
     * The hint sits near the right end with a stroke on each side, and the row
     * is exactly the run plus its two corners however long the hint is.
     */
    public function testTheHintSitsBetweenStrokesAndTheRowKeepsItsWidth(): void
    {
        $styles = Styles::dark();
        $row = BorderHint::bottom(Glyphs::MODAL_BOTTOM_LEFT, Glyphs::MODAL_BOTTOM_RIGHT, Glyphs::FRAME_HORIZONTAL, 12, $styles->of(Role::TabAccent), '3/40', $styles);

        $this->assertSame('╰───── 3/40 ─╯', AnsiUtils::stripAnsiCodes($row));
        $this->assertSame(14, AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString($styles->of(Role::Faint)->apply('3/40'), $row, 'faint, like the title bar\'s Esc');
    }

    /**
     * The narrowest run that seats a hint keeps one stroke before it; one
     * column less and the hint is left out whole — a cut hint names half a key.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function runs(): iterable
    {
        yield 'room for one stroke before it' => [9, '╰─ Alt+9 ─╯'];
        yield 'no stroke left before it' => [8, '╰────────╯'];
    }

    #[DataProvider('runs')]
    public function testAHintThatCannotKeepAStrokeBeforeItIsLeftOut(int $run, string $expected): void
    {
        $styles = Styles::dark();
        $row = BorderHint::bottom('╰', '╯', '─', $run, $styles->of(Role::TabAccent), 'Alt+9', $styles);

        $this->assertSame($expected, AnsiUtils::stripAnsiCodes($row));
        $this->assertSame($run + 2, AnsiUtils::visibleWidth($row));
    }

    public function testNoHintIsAPlainBorderAndANegativeRunIsNone(): void
    {
        $styles = Styles::dark();
        $border = $styles->of(Role::TabAccent);

        $this->assertSame($border->apply('╰───').'╯', BorderHint::bottom('╰', '╯', '─', 3, $border, '', $styles));
        $this->assertSame('╰╯', AnsiUtils::stripAnsiCodes(BorderHint::bottom('╰', '╯', '─', -2, $border, 'x', $styles)));
    }
}
