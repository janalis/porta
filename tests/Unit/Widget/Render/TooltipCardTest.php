<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TooltipCard;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The tooltip card: its lines, its width, its frame.
 */
final class TooltipCardTest extends TestCase
{
    /** @return list<string> */
    private static function plain(array $rows): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $rows);
    }

    public function testEveryRowIsTheCardsWidth(): void
    {
        $lines = TooltipCard::lines("Match case\tAlt+C\nand a second line", 48);
        $width = TooltipCard::width($lines);

        foreach (TooltipCard::render($lines, Styles::dark()) as $row) {
            $this->assertSame($width, AnsiUtils::visibleWidth($row));
        }
    }

    public function testThePlainFrame(): void
    {
        $this->assertSame(['╭───────╮', '│ Close │', '╰───────╯'], self::plain(TooltipCard::render(TooltipCard::lines('Close', 48), Styles::dark())));
    }

    public function testAKeySitsApartAtTheEndOfItsLine(): void
    {
        $rows = self::plain(TooltipCard::render(TooltipCard::lines("Match case\tAlt+C\nWhole word", 48), Styles::dark()));

        $this->assertSame('│ Match case  Alt+C │', $rows[1]);
        $this->assertSame('│ Whole word        │', $rows[2]);
    }

    public function testTheKeyIsDimmedApartFromTheWords(): void
    {
        $row = TooltipCard::render(TooltipCard::lines("Match case\tAlt+C", 48), Styles::dark())[1];

        $this->assertStringContainsString(Styles::dark()->of(Role::Muted)->apply('Alt+C'), $row);
    }

    public function testALineWithoutAKeyWraps(): void
    {
        $lines = TooltipCard::lines('one two three four', 9);

        $this->assertSame([['one two', ''], ['three', ''], ['four', '']], $lines);
    }

    /** A line carrying a key is cut, never wrapped: the key must stay on its row. */
    public function testALineWithAKeyIsCutToKeepTheKey(): void
    {
        $lines = TooltipCard::lines("Select all matches\tAlt+Enter", 20);

        $this->assertSame('Alt+Enter', $lines[0][1]);
        $this->assertSame(20, AnsiUtils::visibleWidth($lines[0][0]) + 2 + AnsiUtils::visibleWidth($lines[0][1]));
        $this->assertStringEndsWith('…', $lines[0][0]);
    }

    public function testAnEmptyKeyIsNoKey(): void
    {
        $this->assertSame([['Close', '']], TooltipCard::lines("Close\t", 48));
        $this->assertSame(9, TooltipCard::width([['Close', '']]));
    }

    public function testATallTextIsCutWithAnEllipsis(): void
    {
        $lines = TooltipCard::lines(\implode("\n", \range(1, end: 12)), 48);

        $this->assertCount(TooltipCard::MAX_LINES, $lines);
        $this->assertSame('8 …', $lines[TooltipCard::MAX_LINES - 1][0]);
    }

    public function testTheWidestLineSetsTheWidth(): void
    {
        $this->assertSame(4 + 2 + 2, TooltipCard::width([['a', ''], ['abcd', '']]), 'the widest text, its padding, the frame');
        $this->assertSame(5, TooltipCard::width([]), 'an empty card still frames a cell');
    }
}
