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
        $lowerHaystack = strtolower($haystack);
        $lowerQuery = strtolower($query);

        $score = 0;
        $length = \strlen($haystack);
        $pos = -1;
        $prevPos = -2;

        for ($i = 0, $queryLen = \strlen($lowerQuery); $i < $queryLen; ++$i) {
            $pos = strpos($lowerHaystack, $lowerQuery[$i], $pos + 1);
            if (false === $pos) {
                return null;
            }
            $score += 1;
            if ($pos === ($prevPos + 1)) {
                $score += 5; // consecutive
            }
            if ($pos >= $boundaryStart) {
                $score += 3; // inside the primary segment
            }
            $prev = $pos > 0 ? $haystack[$pos - 1] : '';
            if (0 === $pos || '/' === $prev || '_' === $prev || '-' === $prev || '.' === $prev) {
                $score += 4; // word-boundary start
            } elseif (ctype_lower($prev) && ctype_upper($haystack[$pos])) {
                $score += 4; // camelCase hump
            }
            $prevPos = $pos;
        }

        return $score - intdiv($length, 10);
    }
}
