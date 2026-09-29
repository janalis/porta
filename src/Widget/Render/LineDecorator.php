<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiSlice;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Applies visual spans (selection, search matches, current line, bracket
 * highlight, diagnostic underlines) onto an ANSI-highlighted line.
 *
 * Spans are expressed in absolute display columns. Decoration only inserts
 * zero-width SGR sequences, so the visible width of the line is unchanged
 * and spans can be applied sequentially.
 */
final class LineDecorator
{
    public const string BRACKET_SGR = "\x1b[1m\x1b[7m";
    public const string UNDERLINE_SGR = Sgr::UNDERLINE;

    /**
     * Apply one SGR span over [$startCol, $endCol) display columns.
     *
     * @param string      $openSgr   SGR sequence starting the effect (e.g. background)
     * @param string      $closeSgr  SGR sequence ending it (e.g. Sgr::DEFAULT_BG or "\x1b[24m")
     * @param string|null $bgReapply When the effect is a background, pass its SGR code
     *                               again so it is re-applied after any reset inside the span
     */
    public static function apply(string $line, int $startCol, int $endCol, string $openSgr, string $closeSgr, ?string $bgReapply = null): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        $startCol = \max(0, $startCol);
        $endCol = \min($endCol, \max($lineWidth, $startCol));
        if ($endCol <= $startCol) {
            return $line;
        }

        $before = 0 === $startCol ? '' : AnsiSlice::byColumn($line, 0, $startCol);
        $middle = AnsiSlice::byColumn($line, $startCol, $endCol - $startCol);
        $after = $endCol >= $lineWidth ? '' : AnsiSlice::byColumn($line, $endCol, $lineWidth - $endCol);

        if (null !== $bgReapply) {
            $middle = AnsiUtils::reapplyBackgroundAfterResets($middle, $bgReapply);
        }

        return $before.$openSgr.$middle.$closeSgr.$after;
    }

    /**
     * Apply many spans in ONE traversal of the line — never re-slicing already
     * decorated output. Sequentially folding {@see apply()} over N spans is
     * O(3^N) in escape bytes: each apply re-slices the previous result, and the
     * slicer replays every escape it skipped into both slices, tripling them
     * per pass (measured: 8 same-word occurrence spans on one prose line =
     * 120KB and 3ms; 12 = OOM). A boundary sweep slices the RAW line once per
     * segment instead: O(N·len), with closes emitted in reverse application
     * order, still-active spans re-opened after a close (which also restores
     * an outer background an inner close's reset used to lose), and active
     * backgrounds re-applied across resets inside each segment.
     *
     * Byte-identical to a sequential apply() for the zero- and one-span cases.
     * $spans in application (layering) order, each [startCol, endCol, openSgr,
     * closeSgr, bgReapply].
     *
     * @param list<array{0: int, 1: int, 2: string, 3: string, 4: ?string}> $spans
     */
    public static function applyAll(string $line, array $spans): string
    {
        if ([] === $spans) {
            return $line;
        }
        $lineWidth = AnsiUtils::visibleWidth($line);
        $clamped = [];
        foreach ($spans as $i => [$startCol, $endCol, $openSgr, $closeSgr, $bgReapply]) {
            $startCol = \max(0, $startCol);
            $endCol = \min($endCol, \max($lineWidth, $startCol));
            if ($endCol <= $startCol) {
                continue;
            }
            $clamped[$i] = [$startCol, $endCol, $openSgr, $closeSgr, $bgReapply];
        }
        if ([] === $clamped) {
            return $line;
        }

        $bounds = [];
        foreach ($clamped as [$start, $end]) {
            $bounds[$start] = true;
            $bounds[$end] = true;
        }
        $bounds = \array_keys($bounds);
        \sort($bounds);

        $out = $bounds[0] > 0 ? AnsiSlice::byColumn($line, 0, $bounds[0]) : '';
        /** @var array<int, array{0: int, 1: int, 2: string, 3: string, 4: ?string}> $active keyed by application index */
        $active = [];
        foreach ($bounds as $bi => $col) {
            $closed = false;
            foreach (\array_reverse($active, preserve_keys: true) as $i => $span) {
                if ($span[1] !== $col) {
                    continue;
                }

                $out .= $span[3];
                unset($active[$i]);
                $closed = true;
            }
            if ($closed && [] !== $active) {
                // A close's reset codes may have cancelled an outer span's
                // effect (e.g. \x1b[49m under a selection): re-open survivors.
                foreach ($active as $span) {
                    $out .= $span[2];
                }
            }
            foreach ($clamped as $i => $span) {
                if ($span[0] !== $col) {
                    continue;
                }

                $active[$i] = $span;
                $out .= $span[2];
            }
            \ksort($active); // application order for the restore and bg chains

            $next = $bounds[$bi + 1] ?? $lineWidth;
            if ($next <= $col) {
                // `break` here is an EQUIVALENT mutant, and the loop-skip census
                // carried it as a gap for three rounds. $bounds is deduplicated
                // through an array KEY and then sorted, so it is strictly
                // increasing: every iteration with a successor has $next > $col,
                // and the only one that can reach this line is the last, where
                // continuing and breaking are the same act. Measured rather than
                // argued — 21,000 span configurations over seven lines, mutant
                // and original byte-identical.
                continue;
            }
            $segment = AnsiSlice::byColumn($line, $col, $next - $col);
            $bgChain = '';
            foreach ($active as $span) {
                $bgChain .= $span[4] ?? '';
            }
            $out .= '' === $bgChain ? $segment : AnsiUtils::reapplyBackgroundAfterResets($segment, $bgChain);
        }

        return $out;
    }

    /**
     * Apply a background over the whole line, padding it to $width first so
     * the background covers trailing space (current-line highlight). The
     * style's own apply() re-establishes the background after any reset
     * inside the line and closes it with the targeted background reset.
     */
    public static function applyFullWidth(string $line, int $width, Style $bg): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        if ($lineWidth < $width) {
            $line .= \str_repeat(' ', \max(0, $width - $lineWidth));
        }

        return $bg->apply($line);
    }
}
