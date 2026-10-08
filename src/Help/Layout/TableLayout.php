<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\Alignment;
use Porta\Markdown\Node\Table;
use Porta\Markdown\Node\TableCell;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A table as GitHub draws one, within a pane: column rules, the header bold
 * over a rule, each column aligned as its delimiter cell says. The columns
 * are budgeted against the pane — the widest gives up a column at a time, so a
 * column of keys keeps every key while a prose column narrows — and each cell
 * wraps inside its own column: nothing is lost, a row is as tall as its
 * tallest cell. Below the floor the table is wider than the pane, and the
 * page cuts the row.
 */
final readonly class TableLayout
{
    /** Narrowest a column may be squeezed to before the row is cut instead. */
    private const int MIN_COLUMN = 8;

    public function __construct(
        private StylePen $pen,
    ) {}

    /** @return list<PageLine> */
    public function lines(Table $table, int $width, LayoutState $state): array
    {
        $inlines = new InlineFragments($state);
        $wrapper = new Wrapper($this->pen);
        $rows = [];
        foreach ([$table->header, ...$table->rows] as $index => $row) {
            $base = 0 === $index ? new InlineStyle(InlineStyle::BOLD) : new InlineStyle();
            $rows[] = \array_map(static fn (TableCell $cell): array => $inlines->of($cell->inlines, $base), $row->cells);
        }
        $widths = self::columnWidths($rows, $width, $wrapper);
        $lines = [];
        foreach ([$table->header, ...$table->rows] as $index => $row) {
            \array_push($lines, ...$this->row($rows[$index], $widths, $table->alignments, $wrapper, $state->lineOf($row->range->start)));
            if (0 === $index) {
                $lines[] = $this->rule($widths, $state->lineOf($row->range->start));
            }
        }

        return $lines;
    }

    /**
     * Each column as wide as its widest cell, then the widest narrowed a
     * column at a time until the row fits — or every column is at the floor.
     *
     * @param list<list<list<Fragment>>> $rows
     *
     * @return list<int>
     */
    private static function columnWidths(array $rows, int $width, Wrapper $wrapper): array
    {
        $widths = [];
        foreach ($rows as $cells) {
            foreach ($cells as $column => $fragments) {
                $natural = $wrapper->line(Wrapper::packWords(Wrapper::toWords($fragments, \PHP_INT_MAX), \PHP_INT_MAX, 0, 0)[0])->width;
                $widths[$column] = \max($widths[$column] ?? 1, $natural);
            }
        }
        $widths = \array_values($widths);
        while ([] !== $widths && self::rowWidth($widths) > $width) {
            $widest = \array_keys($widths, \max($widths), strict: true)[0];
            if ($widths[$widest] <= self::MIN_COLUMN) {
                break;
            }
            --$widths[$widest];
        }

        return $widths;
    }

    /** @param list<int> $widths a leading space, each column, and ` │ ` between columns */
    private static function rowWidth(array $widths): int
    {
        return 1 + \array_sum($widths) + (3 * \max(0, \count($widths) - 1));
    }

    /**
     * @param list<list<Fragment>> $cells
     * @param list<int>            $widths
     * @param list<Alignment>      $alignments
     *
     * @return list<PageLine>
     */
    private function row(array $cells, array $widths, array $alignments, Wrapper $wrapper, int $origin): array
    {
        $wrapped = [];
        $height = 1;
        foreach ($widths as $column => $columnWidth) {
            $fragments = $cells[$column] ?? [];
            $wrapped[$column] = [] === $fragments ? [new PageLine()] : $wrapper->wrap($fragments, $columnWidth, new PageLine(), new PageLine());
            $height = \max($height, \count($wrapped[$column]));
        }
        $lines = [];
        for ($physical = 0; $physical < $height; ++$physical) {
            $line = new PageLine(' ', 1, $origin);
            foreach ($widths as $column => $columnWidth) {
                $cell = $wrapped[$column][$physical] ?? new PageLine();
                $line = $line->then($this->separator($column))->then(self::aligned($cell, $columnWidth, $alignments[$column] ?? Alignment::None));
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function separator(int $column): PageLine
    {
        return 0 === $column ? new PageLine() : new PageLine(' '.$this->pen->role(Glyphs::MD_TABLE_BAR, Role::Muted).' ', 3);
    }

    private static function aligned(PageLine $cell, int $width, Alignment $alignment): PageLine
    {
        $room = \max(0, $width - $cell->width);
        $left = match ($alignment) {
            Alignment::Right => $room,
            Alignment::Center => \intdiv($room, num2: 2),
            default => 0,
        };

        return $cell->after(PageLine::spaces($left))->then(PageLine::spaces($room - $left));
    }

    /** @param list<int> $widths */
    private function rule(array $widths, int $origin): PageLine
    {
        $text = '';
        foreach ($widths as $column => $columnWidth) {
            $text .= (0 === $column ? Glyphs::MD_RULE : Glyphs::MD_RULE.Glyphs::MD_TABLE_CROSS.Glyphs::MD_RULE).\str_repeat(Glyphs::MD_RULE, \max(0, $columnWidth));
        }

        return new PageLine($this->pen->role($text, Role::Muted), self::rowWidth($widths), $origin);
    }
}
