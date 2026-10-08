<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Case-insensitive subsequence fuzzy matching, shared by the go-to-file
 * palette and the completion popup.
 *
 * A higher score is a better match. Bonuses reward consecutive runs, matches
 * inside a "primary" segment (e.g. a path's basename), matches at a word
 * boundary (start, or after `/ _ - .`) and camelCase humps (an upper-case
 * letter following a lower-case one).
 */
final class FuzzyMatcher
{
    /**
     * @param string $haystack     the candidate to match against (original case)
     * @param string $query        the user's query (matched case-insensitively)
     * @param int    $boundaryStart offset past which matches earn the primary-segment
     *                              bonus; pass 0 for plain identifiers
     *
     * @return int|null the score, or null when $query is not a subsequence of $haystack
     */
    public static function score(string $haystack, string $query, int $boundaryStart = 0): ?int
    {
        return self::scoreLowered(\strtolower($haystack), \strtolower($query), $boundaryStart, $haystack);
    }

    /**
     * Scoring core for pre-lowered operands, so a caller filtering thousands of
     * candidates per keystroke lowers each candidate once, not per keystroke.
     * $original (case-preserved) enables the camelCase-hump bonus; null keeps
     * it off — the go-to-file palette lowers its haystacks deliberately so
     * paths never earn hump noise.
     */
    public static function scoreLowered(string $lowerHaystack, string $lowerQuery, int $boundaryStart = 0, ?string $original = null): ?int
    {
        $score = 0;
        $length = \strlen($lowerHaystack);
        $pos = -1;
        $prevPos = -2;

        for ($i = 0, $queryLen = \strlen($lowerQuery); $i < $queryLen; ++$i) {
            $pos = \strpos($lowerHaystack, $lowerQuery[$i], $pos + 1);
            if (false === $pos) {
                return null;
            }
            ++$score;
            if ($pos === ($prevPos + 1)) {
                $score += 5; // consecutive
            }
            if ($pos >= $boundaryStart) {
                $score += 3; // inside the primary segment
            }
            $prev = $pos > 0 ? $lowerHaystack[$pos - 1] : '';
            $wordBoundary = 0 === $pos || '/' === $prev || '_' === $prev || '-' === $prev || '.' === $prev;
            if ($wordBoundary) {
                $score += 4; // word-boundary start
            }
            if (!$wordBoundary && null !== $original && \ctype_lower($original[$pos - 1]) && \ctype_upper($original[$pos])) {
                $score += 4; // camelCase hump
            }
            $prevPos = $pos;
        }

        return $score - \intdiv($length, num2: 10);
    }

    /**
     * Which characters of $haystack a reader should see matched by $query, as
     * character offsets: the query as one run where it occurs whole (a grep
     * hit, a filter's substring), else the same greedy first-occurrence
     * subsequence {@see scoreLowered()} scores; [] when it matches neither.
     *
     * For display only — a picker marks these so the reader sees WHY a row
     * matched. Case-insensitive, and multibyte-safe, since a label may be in
     * any script.
     *
     * @return list<int>
     */
    public static function positions(string $haystack, string $query): array
    {
        $hay = \mb_str_split(\mb_strtolower($haystack));
        $needle = \mb_str_split(\mb_strtolower($query));
        if ([] === $needle || [] === $hay) {
            return [];
        }
        $run = \mb_strpos(\implode('', $hay), \implode('', $needle));
        if (false !== $run) {
            return \range($run, $run + \count($needle) - 1);
        }
        $positions = [];
        $at = 0;
        foreach ($needle as $char) {
            while ($at < \count($hay) && $hay[$at] !== $char) {
                ++$at;
            }
            if (\count($hay) === $at) {
                return [];
            }
            $positions[] = $at++;
        }

        return $positions;
    }
}
