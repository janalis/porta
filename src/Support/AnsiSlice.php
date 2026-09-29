<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The vendor's column slicers, made right for characters that are more than
 * one codepoint: a decomposed accent (`e` + U+0301), Hebrew niqqud, any base
 * with combining marks.
 *
 * The vendor sizes a whole run of text with `mb_strwidth()` whenever the run
 * fits in what is left of the slice, and `mb_strwidth()` counts every combining
 * mark as a column. So `sliceByColumn()`, `sliceWithWidth()` and
 * `truncateToWidth()` cut short — one column per mark — as soon as such a run
 * is followed by an escape, which in a styled row is always. `visibleWidth()`
 * and the vendor's own per-grapheme path are right; only the fast path is not.
 * `sliceToWidth()` is unaffected and has no wrapper here.
 *
 * A line with no mark goes straight to the vendor, byte for byte. Otherwise
 * each multi-codepoint character is swapped for ONE codepoint of the same
 * width — a plane-16 private-use character for one column, a CJK Extension B
 * ideograph for two — chosen from those the line does not already hold, the
 * vendor slices that, and the swap is undone. A character wider than two
 * columns (a ZWJ family emoji, four by the vendor's count) has no single
 * stand-in and is left to the vendor as it is.
 *
 * `Ui\Graphics\KittyPlaceholders` solves the same problem for image cells a
 * cheaper way, because it owns the characters it paints.
 */
final class AnsiSlice
{
    /** One escape sequence: CSI, a string command (OSC/DCS/APC/PM/SOS), or a two-byte one. */
    private const string ESCAPE = '/(\x1b\[[0-?]*[ -\/]*[@-~]|\x1b[\]P_^X][^\x07\x1b]*(?:\x07|\x1b\\\\)?|\x1b.)/s';

    /** {@see AnsiUtils::sliceByColumn()}, counting a combining mark as no column. */
    public static function byColumn(string $line, int $startCol, int $length, bool $strict = false): string
    {
        [$masked, $restore] = self::mask($line);

        return \strtr(AnsiUtils::sliceByColumn($masked, $startCol, $length, $strict), $restore);
    }

    /**
     * {@see AnsiUtils::sliceWithWidth()}, counting a combining mark as no column.
     *
     * @return array{text: string, width: int}
     */
    public static function withWidth(string $line, int $startCol, int $length, bool $strict = false): array
    {
        [$masked, $restore] = self::mask($line);
        $slice = AnsiUtils::sliceWithWidth($masked, $startCol, $length, $strict);

        return ['text' => \strtr($slice['text'], $restore), 'width' => $slice['width']];
    }

    /** {@see AnsiUtils::truncateToWidth()}, counting a combining mark as no column. */
    public static function truncate(string $text, int $maxWidth, string $ellipsis = '...', bool $pad = false): string
    {
        [$masked, $restore] = self::mask($text);

        return \strtr(AnsiUtils::truncateToWidth($masked, $maxWidth, $ellipsis, $pad), $restore);
    }

    /**
     * $line with every one- or two-column multi-codepoint character swapped for
     * a single codepoint of its width, and the map that swaps them back.
     *
     * @return array{string, array<string, string>}
     */
    private static function mask(string $line): array
    {
        // The common line — and any line that is not valid UTF-8, which the
        // vendor handles its own way — has nothing to swap.
        if (1 !== \preg_match('/[\p{M}\x{200D}]/u', $line)) {
            return [$line, []];
        }
        /** @var array<string, string> $standIns cluster => stand-in */
        $standIns = [];
        $next = [1 => 0x10_0000, 2 => 0x2_0000];
        $parts = (array) \preg_split(self::ESCAPE, $line, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $masked = '';
        foreach ($parts as $index => $part) {
            $part = (string) $part;
            // Odd parts are the captured escapes, even ones the text between.
            $masked .= 1 === ($index % 2)
                ? $part
                : (string) \preg_replace_callback(
                    '/\X/u',
                    static function (array $match) use (&$standIns, &$next, $line): string {
                        $cluster = $match[0];
                        if (\mb_strlen($cluster) < 2) {
                            return $cluster;
                        }
                        $width = AnsiUtils::visibleWidth($cluster);
                        if (1 !== $width && 2 !== $width) {
                            return $cluster;
                        }
                        if (!\array_key_exists($cluster, $standIns)) {
                            do {
                                $standIn = (string) \mb_chr($next[$width]++);
                            } while (\str_contains($line, $standIn));
                            $standIns[$cluster] = $standIn;
                        }

                        return $standIns[$cluster];
                    },
                    $part,
                );
        }

        $restore = [];
        foreach ($standIns as $cluster => $standIn) {
            $restore[$standIn] = $cluster;
        }

        return [$masked, $restore];
    }
}
