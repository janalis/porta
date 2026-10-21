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
 * that order) are reported. A diff3/zdiff3 base section — `||||||| base`
 * between the opener and the separator — is recorded as `base`, and belongs
 * to neither side: no resolution keeps it.
 */
final class ConflictMarkers
{
    /**
     * @param list<string> $lines
     *
     * @return list<array{start: int, base: int|null, sep: int, end: int}> 0-based marker lines; `base` is null without a diff3 section
     */
    public static function find(array $lines): array
    {
        $regions = [];
        $start = null;
        $base = null;
        $sep = null;
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, '<<<<<<<')) {
                $start = $i;
                $base = null;
                $sep = null;

                continue;
            }
            if (null !== $start && null === $base && null === $sep && \str_starts_with($line, '|||||||')) {
                $base = $i;

                continue;
            }
            if (null !== $start && null === $sep && \str_starts_with($line, '=======')) {
                $sep = $i;

                continue;
            }
            if (null !== $start && null !== $sep && \str_starts_with($line, '>>>>>>>')) {
                $regions[] = ['start' => (int) $start, 'base' => $base, 'sep' => (int) $sep, 'end' => $i];
                $start = null;
                $base = null;
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
     * @return array{start: int, base: int|null, sep: int, end: int}|null
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
     * @param array{start: int, base: int|null, sep: int, end: int} $region
     *
     * @return list<string>
     */
    public static function resolution(array $lines, array $region, ConflictSide $which): array
    {
        $oursEnd = $region['base'] ?? $region['sep'];
        $ours = \array_slice($lines, $region['start'] + 1, $oursEnd - $region['start'] - 1);
        $theirs = \array_slice($lines, $region['sep'] + 1, $region['end'] - $region['sep'] - 1);

        return match ($which) {
            ConflictSide::Ours => $ours,
            ConflictSide::Theirs => $theirs,
            ConflictSide::Both => [...$ours, ...$theirs],
        };
    }

    /**
     * Whether $lines still hold anything of a conflict: a whole region, or a
     * marker line a hand resolution left behind — an opener, a base or a
     * closer with no partner. What decides a merge tool's verdict, where
     * {@see find()} alone would pass a half-deleted block as resolved. Every
     * region {@see find()} reports opens on such a line, so it needs no
     * separate check.
     *
     * A marker is one as git writes it: seven of `<`, `|` or `>`, then a space
     * or the end of the line — an eight-deep Markdown quote is none. A lone
     * `=======` is not counted: it is a Markdown or RST underline as often as
     * a separator, and without an opener or closer it marks nothing.
     *
     * @param list<string> $lines
     */
    public static function unresolved(array $lines): bool
    {
        return null !== \array_find($lines, static fn (string $line): bool => 1 === \preg_match('/^(?:<{7}|\|{7}|>{7})(?: |$)/', $line));
    }
}
