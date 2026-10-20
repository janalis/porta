<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Merge;

use Porta\Editor\ConflictMarkers;
use Porta\Editor\ConflictSide;

/**
 * The two sides of a merge, read from the result's own conflict markers.
 *
 * Everything outside a conflict is the same text on both sides and in the
 * result, so each side is the result with every conflict replaced by that
 * side's lines — and drawn row for row level with it: one row per result
 * line, a conflict's rows being its label, its lines, then filler to the
 * conflict's height. A side therefore scrolls with the result by the line
 * number alone. Read live from the buffer, it follows every edit, and needs no
 * git: the same rows serve a merge in progress and git's mergetool.
 *
 * What it cannot show is a change git merged by itself outside the conflicts:
 * that is already in the result, on both sides alike.
 */
final class MergeSides
{
    /**
     * @param list<string> $lines the result, one entry per line
     *
     * @return list<MergeRow> exactly one per line of $lines
     */
    public static function rows(array $lines, ConflictSide $side): array
    {
        $rows = \array_map(static fn (string $line): MergeRow => new MergeRow($line, MergeRowKind::Context), $lines);
        foreach (ConflictMarkers::find($lines) as $index => $region) {
            $marker = ConflictSide::Theirs === $side ? $lines[$region['end']] : $lines[$region['start']];
            $own = ConflictMarkers::resolution($lines, $region, $side);
            for ($line = $region['start']; $line <= $region['end']; ++$line) {
                $offset = $line - $region['start'] - 1;
                $rows[$line] = match (true) {
                    $line === $region['start'] => new MergeRow(self::label($marker), MergeRowKind::Header, $index),
                    $offset < \count($own) => new MergeRow($own[$offset], MergeRowKind::Side, $index),
                    default => new MergeRow('', MergeRowKind::Filler, $index),
                };
            }
        }

        return $rows;
    }

    /**
     * The conflict a key acts on from $line: the one $line is in, else the
     * next one below it, else the last one — or null when there is none.
     *
     * @param list<string> $lines
     */
    public static function current(array $lines, int $line): ?int
    {
        $regions = ConflictMarkers::find($lines);
        $below = \array_find_key($regions, static fn (array $region): bool => $line <= $region['end']);

        return $below ?? \array_key_last($regions);
    }

    /**
     * The result once conflict $index keeps $side's lines, or null when there
     * is no such conflict any more.
     *
     * @param list<string> $lines
     *
     * @return list<string>|null
     */
    public static function accept(array $lines, int $index, ConflictSide $side): ?array
    {
        $region = ConflictMarkers::find($lines)[$index] ?? null;
        if (null === $region) {
            return null;
        }
        \array_splice($lines, $region['start'], $region['end'] - $region['start'] + 1, ConflictMarkers::resolution($lines, $region, $side));

        return $lines;
    }

    /** Whether $text holds a conflict opener: what makes a file open in the merge view. */
    public static function conflicted(string $text): bool
    {
        return 1 === \preg_match('/^<{7}(?: |$)/m', $text);
    }

    /** A marker line's label — `HEAD` from `<<<<<<< HEAD` — or '' for a bare marker. */
    private static function label(string $marker): string
    {
        return \trim(\substr($marker, offset: 7));
    }
}
