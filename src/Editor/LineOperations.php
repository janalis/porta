<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The pure algorithms behind {@see Document}'s whole-line verbs: sorting a
 * block, toggling its case, joining it, toggling its comment prefix, and
 * finding the line a statement-move lands on.
 *
 * Deliberately transforms only. Every one of these verbs has the shape
 * "read the selected span → transform the block → write it back in one undo
 * step", and only the middle third is interesting; the rest is cursor clamping,
 * selection bookkeeping and undo, which stay in Document. Splitting there keeps
 * this side free of state and leaves the undo path — the costliest thing in the
 * buffer to get wrong — untouched.
 *
 * {@see Document::indentSelection()} and {@see Document::dedentSelection()} are
 * NOT here: they track cursor and anchor deltas *inside* the mutation loop, so
 * carving out a pure transform would mean restructuring that loop rather than
 * moving it.
 */
final class LineOperations
{
    /**
     * The block sorted as plain strings.
     *
     * @param string[] $block
     *
     * @return list<string>
     */
    public static function sorted(array $block): array
    {
        $sorted = $block;
        sort($sorted, \SORT_STRING);

        return $sorted;
    }

    /**
     * The block with its case flipped: all-uppercase becomes lowercase,
     * anything else becomes uppercase. Decided over the joined block so a
     * multi-line selection flips as one unit.
     *
     * @param string[] $block
     *
     * @return list<string>
     */
    public static function caseToggled(array $block): array
    {
        $joined = implode("\n", $block);
        $upper = mb_strtoupper($joined);

        return explode("\n", $joined === $upper ? mb_strtolower($joined) : $upper);
    }

    /**
     * Lines $first..$last joined into one, single-spaced: each piece is
     * left-trimmed onto the running result and the result right-trimmed, so
     * indentation and trailing blanks collapse.
     *
     * @param string[] $lines
     */
    public static function joined(array $lines, int $first, int $last): string
    {
        $joined = rtrim($lines[$first]);
        for ($i = $first + 1; $i <= $last; ++$i) {
            $joined = rtrim($joined.' '.ltrim($lines[$i]));
        }

        return $joined;
    }

    /**
     * The block with $prefix added to every non-blank line, or removed from
     * every line when they all already carry it. Indentation is preserved (the
     * prefix goes after it) and blank lines are left alone.
     *
     * @param string[] $block
     *
     * @return list<string>
     */
    public static function commentToggled(array $block, string $prefix): array
    {
        $allCommented = true;
        foreach ($block as $line) {
            $trimmed = ltrim($line);
            if ('' !== $trimmed && !str_starts_with($trimmed, $prefix)) {
                $allCommented = false;
                break;
            }
        }

        $out = [];
        foreach ($block as $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed) {
                $out[] = $line;

                continue;
            }
            $indent = \strlen($line) - \strlen($trimmed);
            if ($allCommented) {
                $rest = substr($trimmed, \strlen($prefix));
                $out[] = substr($line, offset: 0, length: $indent).ltrim($rest, characters: ' ');

                continue;
            }
            $out[] = substr($line, offset: 0, length: $indent).$prefix.' '.$trimmed;
        }

        return $out;
    }

    /**
     * Where a statement-move of $delta lands the block at $first..$last, or null
     * when it would run off the buffer. A run of blank lines counts as one step,
     * so a statement hops over the gap rather than into it.
     *
     * @param string[] $lines
     */
    public static function statementTarget(array $lines, int $delta, int $first, int $last): ?int
    {
        $n = \count($lines);
        if ($delta < 0) {
            if (0 === $first) {
                return null;
            }
            $step = 1;
            if ('' === trim($lines[$first - 1])) {
                $g = $first - 1;
                while ($g > 0 && '' === trim($lines[$g - 1])) {
                    --$g;
                }
                $step = $first - $g;
            }

            return max(0, $first - $step);
        }
        if ($last === ($n - 1)) {
            return null;
        }
        $step = 1;
        if ('' === trim($lines[$last + 1])) {
            $g = $last + 1;
            while ($g < ($n - 1) && '' === trim($lines[$g + 1])) {
                ++$g;
            }
            $step = $g - $last;
        }

        return min($first + $step, $first + ($n - 1 - $last));
    }
}
