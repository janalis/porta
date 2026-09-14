<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Pure detection and resolution of git merge-conflict regions, i.e. blocks
 * delimited by
 *
 *   <<<<<<< ours
 *   ...our lines...
 *   =======
 *   ...their lines...
 *   >>>>>>> theirs
 *
 * Only well-formed regions (an opener, then a separator, then a closer, in
 * that order) are reported. Diff3-style base sections (`|||||||`) are treated
 * as part of the "ours" side.
 */
final class ConflictMarkers
{
    /**
     * @param list<string> $lines
     *
     * @return list<array{start: int, sep: int, end: int}> 0-based marker lines
     */
    public static function find(array $lines): array
    {
        $regions = [];
        $start = null;
        $sep = null;
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, '<<<<<<<')) {
                $start = $i;
                $sep = null;

                continue;
            }
            if (null !== $start && null === $sep && \str_starts_with($line, '=======')) {
                $sep = $i;

                continue;
            }
            if (null !== $start && null !== $sep && \str_starts_with($line, '>>>>>>>')) {
                $regions[] = ['start' => (int) $start, 'sep' => (int) $sep, 'end' => $i];
                $start = null;
                $sep = null;
            }
        }

        return $regions;
    }

    /**
     * The region enclosing $line (caret line), or null if the caret is not
     * inside any conflict.
     *
     * @param list<string> $lines
     *
     * @return array{start: int, sep: int, end: int}|null
     */
    public static function regionAt(array $lines, int $line): ?array
    {
        return \array_find(self::find($lines), static fn ($region): bool => $line >= $region['start'] && $line <= $region['end']);
    }

    /**
     * The replacement lines when resolving a region by keeping "ours",
     * "theirs" or "both".
     *
     * @param list<string>                            $lines
     * @param array{start: int, sep: int, end: int}   $region
     *
     * @return list<string>
     */
    public static function resolution(array $lines, array $region, ConflictSide $which): array
    {
        $ours = \array_slice($lines, $region['start'] + 1, $region['sep'] - $region['start'] - 1);
        $theirs = \array_slice($lines, $region['sep'] + 1, $region['end'] - $region['sep'] - 1);

        return match ($which) {
            ConflictSide::Ours => $ours,
            ConflictSide::Theirs => $theirs,
            ConflictSide::Both => [...$ours, ...$theirs],
        };
    }
}
