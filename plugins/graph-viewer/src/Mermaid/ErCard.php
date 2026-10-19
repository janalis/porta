<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Blank label rows reserve room for dividers, including after wrapped headers and attributes. */
final class ErCard
{
    /** The space between columns; its middle holds the column rule. */
    private const string GAP = '   ';

    /**
     * @param list<array{text: string, origin: int}> $attributes
     * @return \Generator<int, null, mixed, array{label: string, shape: string}>
     */
    public static function label(string $name, array $attributes): \Generator
    {
        $wrap = MermaidWrap::text($name, width: 48);
        yield from $wrap;
        $label = $wrap->getReturn();
        $dividers = [];
        foreach ($attributes as $attribute) {
            $dividers[] = \substr_count($label, needle: "\n") + 1;
            $wrap = MermaidWrap::text($attribute['text'], width: 48);
            yield from $wrap;
            $label .= "\n\n".$wrap->getReturn();
        }

        return ['label' => $label, 'shape' => [] === $dividers ? 'rect' : 'er-card:'.\implode(',', $dividers)];
    }

    /**
     * Pinned `erBox` tables: the name over one row per attribute, its type, name, keys and
     * comment each left-aligned in a column as wide as its widest cell; a column no attribute
     * fills is left out. The shape carries `;c=WIDTH=OFFSET,…` for the column rules.
     *
     * @param list<array{text: string, origin: int, cells: list<string>}> $attributes
     * @return \Generator<int, null, mixed, array{label: string, shape: string, runs: list<list<array{string, GraphFont}>>}>
     */
    public static function table(string $name, array $attributes): \Generator
    {
        $wrap = MermaidWrap::text($name, width: 48);
        yield from $wrap;
        $header = \explode("\n", $wrap->getReturn());
        $widths = [];
        foreach ([0, 1, 2, 3] as $column) {
            $widths[$column] = \max([0, ...\array_map(static fn (array $attribute): int => AnsiUtils::visibleWidth($attribute['cells'][$column] ?? ''), $attributes)]);
        }
        $columns = \array_keys(\array_filter($widths));
        $runs = \array_map(static fn (string $line): array => [[$line, GraphFont::Regular]], $header);
        $lines = $header;
        $dividers = [];
        foreach ($attributes as $attribute) {
            $dividers[] = \count($lines);
            $row = [];
            $text = '';
            foreach ($columns as $index => $column) {
                $cell = $attribute['cells'][$column] ?? '';
                // Every row spans the whole table, so centering it aligns the columns.
                $padded = ($index > 0 ? self::GAP : '').$cell.\str_repeat(' ', \max(0, $widths[$column] - AnsiUtils::visibleWidth($cell)));
                $row[] = [$padded, GraphFont::Regular];
                $text .= $padded;
            }
            $runs[] = [['', GraphFont::Regular]];
            $runs[] = $row;
            $lines[] = '';
            // Rows keep their padding, so the cells, which center each line, align the columns too.
            $lines[] = $text;
            yield null;
        }
        $offsets = [];
        $total = 0;
        foreach ($columns as $index => $column) {
            $total += $widths[$column] + ($index > 0 ? \strlen(self::GAP) : 0);
            $offsets[] = $total + (\strlen(self::GAP) / 2);
        }
        \array_pop($offsets);
        $shape = [] === $dividers ? 'rect' : 'er-card:'.\implode(',', $dividers).([] === $offsets ? '' : ';c='.$total.'='.\implode(',', $offsets));

        return ['label' => \implode("\n", $lines), 'shape' => $shape, 'runs' => $runs];
    }

    /**
     * Row stripes, when the shape carries `|odd,even` fills, then the row dividers.
     *
     * @return list<MermaidMark>
     */
    public static function dividers(GraphNode $node, string $shape): array
    {
        [$spec, $fills] = \array_pad(\explode('|', \substr($shape, offset: 8), limit: 2), length: 2, value: null);
        // A class card lists its static members' underlines after `;`.
        $rows = \explode(',', \explode(';', (string) $spec, limit: 2)[0]);
        $marks = null === $fills ? [] : self::stripes($node, $rows, \explode(',', $fills, limit: 2));
        \array_push($marks, ...self::columns($node, (string) $spec, $rows));
        foreach ($rows as $row) {
            $y = $node->y - ($node->h / 2) + 2 + (((2 * (int) $row) + 1) * $node->fontScale);
            $marks[] = new MermaidMark(
                'polyline',
                [[$node->x - ($node->w / 2), $y], [$node->x + ($node->w / 2), $y]],
                stroke: $node->color ?? '#333333',
                dashed: $node->dashed,
                strokeWidth: $node->strokeWidth,
                dashes: $node->dashes,
            );
        }

        return $marks;
    }

    /**
     * The table's column rules, from the first row divider down, where `;c=WIDTH=OFFSET,…` puts them.
     *
     * @param list<string> $rows
     * @return list<MermaidMark>
     */
    private static function columns(GraphNode $node, string $spec, array $rows): array
    {
        $match = [];
        if (1 !== \preg_match('/;c=(\d+)=([\d.,]+)/', $spec, $match)) {
            return [];
        }
        $left = $node->x - (((int) $match[1] * $node->fontScale) / 2);
        $top = $node->y - ($node->h / 2) + 2 + (((2 * (int) $rows[0]) + 1) * $node->fontScale);

        $rules = [];
        foreach (\explode(',', $match[2]) as $offset) {
            // @mago-expect analysis:invalid-type-cast -- the shape's pattern admits only decimal offsets
            $x = $left + ((float) $offset * $node->fontScale);
            $rules[] = new MermaidMark(
                'polyline',
                [[$x, $top], [$x, $node->y + ($node->h / 2)]],
                stroke: $node->color ?? '#333333',
                dashed: $node->dashed,
                strokeWidth: $node->strokeWidth,
                dashes: $node->dashes,
            );
        }

        return $rules;
    }

    /**
     * Pinned `erBox`: each attribute row from its divider down, the first one odd.
     *
     * @param list<string> $rows
     * @param list<string> $fills
     * @return list<MermaidMark>
     */
    private static function stripes(GraphNode $node, array $rows, array $fills): array
    {
        $marks = [];
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $bottom = $node->y + ($node->h / 2);
        foreach ($rows as $index => $row) {
            $top = $node->y - ($node->h / 2) + 2 + (((2 * (int) $row) + 1) * $node->fontScale);
            $next = $rows[$index + 1] ?? null;
            $end = null === $next ? $bottom : $node->y - ($node->h / 2) + 2 + (((2 * (int) $next) + 1) * $node->fontScale);
            $marks[] = new MermaidMark('polygon', [[$left, $top], [$right, $top], [$right, $end], [$left, $end]], stroke: 'none', fill: $fills[$index % 2] ?? $fills[0], strokeWidth: 0.0);
        }

        return $marks;
    }
}
