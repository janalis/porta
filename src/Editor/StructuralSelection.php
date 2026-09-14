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
            // A pair the range has ALREADY swallowed whole is not enclosing.
            if ($open === $start && ($close + 1) === $end) {
                // Not enclosing — but the call AROUND it is the next rung, so
                // that one candidate is offered before moving on.
                $call = self::callSpan($text, $open, $close);
                if (null !== $call) {
                    $candidates[] = $call;
                }

                continue;
            }
            if (!($open < $start && $close >= $end)) {
                continue;
            }

            // The interior WITHOUT its surrounding whitespace. Untrimmed, a
            // brace interior is a rung of its own that differs from the line
            // inside it only by a newline and some indentation — a press of
            // Ctrl+W that visibly does nothing, and two of the eleven rungs on
            // a plain method were exactly that. A pair holding nothing but
            // whitespace trims to empty and keeps its untrimmed interior,
            // which is all it has.
            [$is, $ie] = self::trimmed($text, $open + 1, $close);
            $candidates[] = $is < $ie ? [$is, $ie] : [$open + 1, $close];
            $candidates[] = [$open, $close + 1];
            // The one comma-separated segment containing the range: the
            // argument you are standing in, before the whole argument list.
            $segment = self::segmentAround($text, $open, $close, $start, $end);
            if (null !== $segment) {
                $candidates[] = $segment;
            }
            // `sprintf(...)` as well as `(...)`: a call is the name plus its
            // parens, and skipping to the enclosing statement loses a rung
            // every language server offers.
            $call = self::callSpan($text, $open, $close);
            if (null !== $call) {
                $candidates[] = $call;
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
     * `sprintf(...)` as well as `(...)`: a call is the name plus its parens,
     * and going straight to the enclosing statement loses a rung every
     * language server offers. Null when nothing names the bracket.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function callSpan(string $text, int $open, int $close): ?array
    {
        $callStart = $open;
        while ($callStart > 0 && self::isWordChar($text[$callStart - 1])) {
            --$callStart;
        }

        return $callStart < $open ? [$callStart, $close + 1] : null;
    }

    /**
     * The candidate with surrounding whitespace removed. A range that is all
     * whitespace is left alone: there is nothing to trim it to.
     *
     * @return array{0: int, 1: int}
     */
    private static function trimmed(string $text, int $start, int $end): array
    {
        while ($start < $end && '' === \trim($text[$start], characters: " \t\n\r")) {
            ++$start;
        }
        while ($end > $start && '' === \trim($text[$end - 1], characters: " \t\n\r")) {
            --$end;
        }

        return [$start, $end];
    }

    /**
     * The comma-separated segment of `(a, b, c)` that contains the range —
     * one argument, in other words. Commas nested in inner brackets or in
     * string literals do not split, which is why this re-walks rather than
     * calling explode.
     *
     * Null when the pair holds no top-level comma (the interior IS the single
     * argument, and {@see expand} already offers that).
     *
     * @return array{0: int, 1: int}|null
     */
    private static function segmentAround(string $text, int $open, int $close, int $start, int $end): ?array
    {
        $segmentStart = $open + 1;
        $depth = 0;
        $found = false;
        for ($i = $open + 1; $i < $close; ++$i) {
            $ch = $text[$i];
            if ("'" === $ch || '"' === $ch) {
                $i = self::endOfLiteral($text, $i, $close);

                continue;
            }
            if (\array_key_exists($ch, self::OPENERS)) {
                ++$depth;

                continue;
            }
            if (\in_array($ch, self::OPENERS, strict: true)) {
                --$depth;

                continue;
            }
            if (',' !== $ch || 0 !== $depth) {
                continue;
            }
            $found = true;
            if ($segmentStart <= $start && $i >= $end) {
                return self::trimmed($text, $segmentStart, $i);
            }
            $segmentStart = $i + 1;
        }

        return $found && $segmentStart <= $start && $close >= $end ? self::trimmed($text, $segmentStart, $close) : null;
    }

    /** The offset of a literal's closing quote, or $limit when it never closes. */
    private static function endOfLiteral(string $text, int $at, int $limit): int
    {
        $quote = $text[$at];
        for ($j = $at + 1; $j < $limit; ++$j) {
            if ('\\' === $text[$j]) {
                ++$j;

                continue;
            }
            if ($text[$j] === $quote) {
                return $j;
            }
        }

        return $limit;
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

            if (\array_key_exists($ch, self::OPENERS)) {
                $stack[] = [$ch, $i];

                continue;
            }
            if (\in_array($ch, self::OPENERS, strict: true) && [] !== $stack) {
                [$openCh, $openPos] = $stack[\count($stack) - 1];
                if (self::OPENERS[$openCh] === $ch) {
                    \array_pop($stack);
                    $pairs[] = [$openPos, $i];
                }
            }
        }

        return $pairs;
    }

    private static function isWordChar(string $byte): bool
    {
        return 1 === \preg_match('/[A-Za-z0-9_$]/', $byte);
    }
}
