<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\SelectionBar;

final class SelectionBarTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool, SelectionBar}> */
    public static function states(): iterable
    {
        yield 'the cursor, focused' => [true, true, false, SelectionBar::Focused];
        yield 'the cursor, unfocused' => [true, false, false, SelectionBar::Unfocused];
        yield 'the cursor outranks its own mark' => [true, true, true, SelectionBar::Focused];
        yield 'and does unfocused too' => [true, false, true, SelectionBar::Unfocused];
        yield 'marked, focused or not' => [false, true, true, SelectionBar::Marked];
        yield 'marked, unfocused' => [false, false, true, SelectionBar::Marked];
        yield 'an ordinary row' => [false, true, false, SelectionBar::None];
    }

    #[DataProvider('states')]
    public function testEachStateHasItsShape(bool $selected, bool $focused, bool $marked, SelectionBar $expected): void
    {
        $this->assertSame($expected, SelectionBar::of($selected, $focused, $marked));
    }

    /** Four states, four shapes: what a screen without colour still tells apart. */
    public function testNoTwoStatesShareAGlyph(): void
    {
        $glyphs = \array_map(static fn (SelectionBar $bar): string => $bar->value, SelectionBar::cases());

        $this->assertSame($glyphs, \array_unique($glyphs));
        foreach ($glyphs as $glyph) {
            $this->assertSame(1, \mb_strwidth($glyph), "{$glyph} is one column");
        }
    }

    /** A sidebar's cursor wears no bar, focused or not; only a row marked without it does. */
    public function testASidebarCursorWearsNoBar(): void
    {
        $this->assertSame(SelectionBar::None, SelectionBar::sidebar(true));
        $this->assertSame(SelectionBar::None, SelectionBar::sidebar(true, marked: true), 'the cursor outranks its own mark');
        $this->assertSame(SelectionBar::Marked, SelectionBar::sidebar(false, marked: true));
        $this->assertSame(SelectionBar::None, SelectionBar::sidebar(false));
    }
}
