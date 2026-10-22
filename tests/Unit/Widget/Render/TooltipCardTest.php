<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\TooltipBody;
use Porta\Ui\TooltipLine;
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

        $this->assertEquals([new TooltipLine('one two'), new TooltipLine('three'), new TooltipLine('four')], $lines);
    }

    /** A path longer than the card breaks after a slash, not mid-name. */
    public function testALongPathWrapsAtItsSlashes(): void
    {
        $lines = TooltipCard::lines('installed at ~/.porta/bin/symfony-lsp', 16);

        $this->assertEquals([new TooltipLine('installed at'), new TooltipLine('~/.porta/bin/'), new TooltipLine('symfony-lsp')], $lines);
    }

    /** A line carrying a key is cut, never wrapped: the key must stay on its row. */
    public function testALineWithAKeyIsCutToKeepTheKey(): void
    {
        $lines = TooltipCard::lines("Select all matches\tAlt+Enter", 20);

        $this->assertSame('Alt+Enter', $lines[0]->key);
        $this->assertSame(20, AnsiUtils::visibleWidth($lines[0]->text) + 2 + AnsiUtils::visibleWidth($lines[0]->key));
        $this->assertStringEndsWith('…', $lines[0]->text);
    }

    public function testAnEmptyKeyIsNoKey(): void
    {
        $this->assertEquals([new TooltipLine('Close')], TooltipCard::lines("Close\t", 48));
        $this->assertSame(9, TooltipCard::width([new TooltipLine('Close')]));
    }

    public function testATallTextIsCutWithAnEllipsis(): void
    {
        $lines = TooltipCard::lines(\implode("\n", \range(1, end: 12)), 48);

        $this->assertCount(TooltipCard::MAX_LINES, $lines);
        $this->assertSame('8 …', $lines[TooltipCard::MAX_LINES - 1]->text);
    }

    public function testTheWidestLineSetsTheWidth(): void
    {
        $this->assertSame(4 + 2 + 2, TooltipCard::width([new TooltipLine('a'), new TooltipLine('abcd')]), 'the widest text, its padding, the frame');
        $this->assertSame(5, TooltipCard::width([]), 'an empty card still frames a cell');
    }

    /**
     * A problem's card: the severity glyph leads in its own colour, a line
     * that wraps keeps its words under the first ones, and a faint line stays
     * faint — no span bleeds across a wrap.
     */
    public function testABodyKeepsEachLinesLeadAndRole(): void
    {
        $styles = Styles::dark();
        $body = new TooltipBody([
            new TooltipLine('Undefined variable $user here', lead: '✗ ', leadRole: Role::DiagError),
            new TooltipLine('phpstan · variable.undefined', role: Role::Faint),
            new TooltipLine('Fix: Import class', 'Alt+Shift+Enter'),
        ], maxText: 60);
        $lines = TooltipCard::layout($body, 20);
        $rows = TooltipCard::render($lines, $styles);

        $this->assertSame(
            [
                '╭──────────────────────╮',
                '│ ✗ Undefined variable │',
                '│   $user here         │',
                '│ phpstan ·            │',
                '│ variable.undefined   │',
                '│ Fi…  Alt+Shift+Enter │',
                '╰──────────────────────╯',
            ],
            self::plain($rows),
        );
        $opens = static fn (Role $role, string $text): string => \explode($text, $styles->merge(Role::PopupRow, $role)->apply($text))[0].$text;
        $this->assertStringContainsString($opens(Role::DiagError, text: '✗ '), $rows[1], 'the glyph in the severity colour');
        $this->assertStringContainsString($opens(Role::Faint, text: 'variable.undefined'), $rows[4], 'a faint line is faint on every row');
        foreach ($rows as $row) {
            $this->assertSame(TooltipCard::width($lines), AnsiUtils::visibleWidth($row));
        }
    }

    public function testABodyIsCutAtItsOwnLineLimit(): void
    {
        $lines = TooltipCard::layout(new TooltipBody([new TooltipLine('a'), new TooltipLine('b'), new TooltipLine('c', lead: '› ')], maxLines: 2), 40);

        $this->assertSame(['a', 'b …'], \array_map(static fn (TooltipLine $line): string => $line->text, $lines));
    }

    public function testPlainTextIsAPlainBodyAndBack(): void
    {
        $body = TooltipBody::fromText("Match case\tAlt+C\nWhole word");

        $this->assertEquals([new TooltipLine('Match case', 'Alt+C'), new TooltipLine('Whole word')], $body->lines);
        $this->assertSame("Match case\tAlt+C\nWhole word", $body->plain());
        $this->assertSame('› x', new TooltipBody([new TooltipLine('x', lead: '› ')])->plain());
    }
}
