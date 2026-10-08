<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\MappedText;

/**
 * A table row split into its cells: an optional leading and trailing pipe
 * dropped, the rest split at every pipe a backslash does not escape, each
 * cell trimmed and its `\|` turned into `|` — still mapped to the source.
 */
final class TableRows
{
    /** @return list<MappedText> */
    public static function cells(MappedText $row): array
    {
        $text = $row->text;
        $start = \strspn($text, characters: " \t");
        $start += '|' === ($text[$start] ?? '') ? 1 : 0;
        $cells = [];
        $cellStart = $start;
        $length = \strlen($text);
        for ($i = $start; $i < $length; ++$i) {
            if ('\\' === $text[$i]) {
                ++$i;
                continue;
            }
            if ('|' === $text[$i]) {
                $cells[] = [$cellStart, $i];
                $cellStart = $i + 1;
            }
        }
        if ('' !== \trim(\substr($text, $cellStart), characters: " \t")) {
            $cells[] = [$cellStart, $length];
        }

        return \array_map(static fn (array $cell): MappedText => self::cell($row, $cell[0], $cell[1]), $cells);
    }

    private static function cell(MappedText $row, int $from, int $to): MappedText
    {
        $raw = \substr($row->text, $from, $to - $from);
        $lead = \strspn($raw, characters: " \t");
        $trimmed = \rtrim(\substr($raw, $lead), characters: " \t");
        $cell = $row->from($from + $lead)->head(\strlen($trimmed));

        return self::unescapePipes($cell);
    }

    /** `\|` becomes `|`: the backslash only kept the pipe from splitting the row. */
    private static function unescapePipes(MappedText $cell): MappedText
    {
        $at = \strpos($cell->text, needle: '\\|');
        if (false === $at) {
            return $cell;
        }
        $head = $cell->head($at);
        $rest = self::unescapePipes($cell->from($at + 1));

        return 0 === $at ? $rest : $head->append($rest);
    }
}
