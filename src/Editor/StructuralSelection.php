<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\IntMath;

/**
 * Pure "expand selection" engine (PhpStorm's Ctrl+W). Given the document text
 * and a byte-offset range, it returns the next-larger syntactic range that
 * strictly encloses it, following the hierarchy
 *
 *   caret → word → inside brackets/quotes → whole bracket/quote → line → document
 *
 * It works purely on byte offsets into the joined text (lines separated by
 * "\n"), so the caller converts to/from (line, col). All scanning is a single
 * linear pass, so it is cheap even on large files and fully unit-testable in
 * isolation from the widget.
 */
final class StructuralSelection
{
    private const array OPENERS = ['(' => ')', '[' => ']', '{' => '}'];

    /**
     * The next range strictly enclosing [$start, $end], or null when the range
     * already spans the whole document (nothing larger to select).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function expand(string $text, int $start, int $end): ?array
    {
        $n = \strlen($text);
        $start = IntMath::clamp($start, 0, $n);
        $end = IntMath::clamp($end, $start, $n);

        /** @var list<array{0: int, 1: int}> $candidates */
        $candidates = [];

        // Word under the caret (only relevant when nothing is selected yet).
        if ($start === $end) {
            $ws = $start;
            while ($ws > 0 && self::isWordChar($text[$ws - 1])) {
                --$ws;
            }
            $we = $start;
            while ($we < $n && self::isWordChar($text[$we])) {
                ++$we;
            }
            if ($we > $ws) {
                $candidates[] = [$ws, $we];
            }
        }

        // Enclosing bracket pairs and string literals: both the inner contents
        // and the delimiter-inclusive span become candidates.
        foreach (self::scan($text) as [$open, $close]) {
            if ($open < $start && $close >= $end) {
                $candidates[] = [$open + 1, $close];
                $candidates[] = [$open, $close + 1];
            }
        }

        // The line(s) the range sits on.
        $ls = $start;
        while ($ls > 0 && "\n" !== $text[$ls - 1]) {
            --$ls;
        }
        $le = $end;
        while ($le < $n && "\n" !== $text[$le]) {
            ++$le;
        }
        $candidates[] = [$ls, $le];

        // The whole document.
        $candidates[] = [0, $n];

        // Smallest candidate that strictly encloses the current range.
        $best = null;
        foreach ($candidates as [$cs, $ce]) {
            $encloses = $cs <= $start && $ce >= $end && ($cs < $start || $ce > $end);
            if ($encloses && (null === $best || ($ce - $cs) < ($best[1] - $best[0]))) {
                $best = [$cs, $ce];
            }
        }

        return $best;
    }

    /**
     * Single linear pass returning every matched bracket pair and string
     * literal as [openOffset, closeOffset]. Brackets are ignored inside string
     * literals; escaped quotes inside strings are skipped.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function scan(string $text): array
    {
        $n = \strlen($text);
        $pairs = [];
        /** @var list<array{0: string, 1: int}> $stack */
        $stack = [];

        for ($i = 0; $i < $n; ++$i) {
            $ch = $text[$i];

            if ("'" === $ch || '"' === $ch) {
                $j = $i + 1;
                while ($j < $n) {
                    if ('\\' === $text[$j]) {
                        $j += 2;

                        continue;
                    }
                    if ($text[$j] === $ch) {
                        break;
                    }
                    ++$j;
                }
                if ($j < $n) {
                    $pairs[] = [$i, $j];
                    $i = $j;
                }

                continue;
            }

            if (isset(self::OPENERS[$ch])) {
                $stack[] = [$ch, $i];
            } elseif (\in_array($ch, self::OPENERS, true) && [] !== $stack) {
                [$openCh, $openPos] = $stack[\count($stack) - 1];
                if (self::OPENERS[$openCh] === $ch) {
                    array_pop($stack);
                    $pairs[] = [$openPos, $i];
                }
            }
        }

        return $pairs;
    }

    private static function isWordChar(string $byte): bool
    {
        return 1 === preg_match('/[A-Za-z0-9_$]/', $byte);
    }
}
