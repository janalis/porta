<?php

declare(strict_types=1);

namespace Porta\I18n;

/**
 * A pragmatic UAX#9-subset reorderer for a single already-composed terminal
 * line: it splits the visible text into directional runs (Hebrew/Arabic → RTL,
 * Latin/CJK/digits → LTR), resolves neutral characters and reorders to visual
 * order for a chosen base direction, preserving SGR colour spans and wide-cell
 * widths. Applied as a final post-process on rtl-locale render output, it makes
 * Hebrew read right-to-left while paths, code and numbers stay left-to-right, and
 * right-anchors LTR content (trailing pad becomes leading pad) under an RTL base.
 *
 * Scope: no explicit embedding controls (LRE/RLE/PDF), Arabic shaping, or number
 * post-processing (W-rules) — European digits are reordered as left-to-right.
 * These cover the IDE's chrome; richer bidi is out of scope.
 */
final class Bidi
{
    /**
     * Neutral characters that mirror when rendered in a right-to-left run (L4).
     *
     * @var array<string, string>
     */
    private const array MIRROR = [
        '(' => ')',
        ')' => '(',
        '[' => ']',
        ']' => '[',
        '{' => '}',
        '}' => '{',
        '<' => '>',
        '>' => '<',
        '«' => '»',
        '»' => '«',
        '‹' => '›',
        '›' => '‹',
    ];

    /**
     * Reorder one composed line to visual order for the given base direction.
     * A line with no strong RTL content and an LTR base is returned unchanged.
     */
    public static function reorder(string $line, BaseDirection $direction): string
    {
        $matches = [];
        // Tokens: an SGR sequence, the zero-width APC cursor marker
        // (\x1b_…\x07), or a single grapheme cluster.
        if (!preg_match_all('/\x1b\[[0-9;]*m|\x1b_[^\x07]*\x07|\X/u', $line, $matches)) {
            return $line;
        }

        /** @var list<array{g: string, sgr: string}> $cells */
        $cells = [];
        $active = '';
        foreach ($matches[0] as $seq) {
            if (str_starts_with($seq, "\x1b[")) { // SGR: updates the active style
                $active = "\x1b[0m" === $seq || "\x1b[m" === $seq ? '' : $active.$seq;
                continue;
            }
            // A grapheme, or the zero-width cursor marker carried as a neutral cell
            // so the reorder lands it at the caret's visual column.
            $cells[] = ['g' => $seq, 'sgr' => $active];
        }

        if ([] === $cells) {
            return $line; // pure escape sequence(s); nothing to reorder
        }

        $classes = array_map(static fn (array $c): string => self::classify($c['g']), $cells);
        if (BaseDirection::Ltr === $direction && !\in_array('R', $classes, strict: true)) {
            return $line; // LTR base with no RTL runs: already in visual order
        }

        $levels = self::levels($classes, $direction);
        $order = self::visualOrder($levels);

        return self::serialize($cells, $levels, $order);
    }

    /**
     * Map a visual column (as clicked on screen) back to the logical column in
     * the pre-reorder line, inverting {@see reorder()} for the same base. A line
     * with no reordering (ltr base, no rtl runs) maps each column to itself, so
     * mouse hit-testing stays correct for untouched content.
     */
    public static function visualToLogicalColumn(string $line, int $visualCol, BaseDirection $direction): int
    {
        $matches = [];
        if ($visualCol < 0 || !preg_match_all('/\x1b\[[0-9;]*m|\x1b_[^\x07]*\x07|\X/u', $line, $matches)) {
            return max(0, $visualCol);
        }

        /** @var list<array{g: string, w: int}> $glyphs */
        $glyphs = [];
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, "\x1b[")) {
                continue; // SGR carries no column
            }
            // The APC cursor marker prints nothing; only real graphemes take width.
            $width = str_starts_with($token, "\x1b") ? 0 : mb_strwidth($token, encoding: 'UTF-8');
            $glyphs[] = ['g' => $token, 'w' => $width];
        }
        if ([] === $glyphs) {
            return $visualCol;
        }

        $classes = array_map(static fn (array $c): string => self::classify($c['g']), $glyphs);
        if (BaseDirection::Ltr === $direction && !\in_array('R', $classes, strict: true)) {
            return $visualCol;
        }

        $levels = self::levels($classes, $direction);
        $order = self::visualOrder($levels);

        $logicalStart = [];
        $acc = 0;
        foreach ($glyphs as $i => $glyph) {
            $logicalStart[$i] = $acc;
            $acc += $glyph['w'];
        }

        $visual = 0;
        foreach ($order as $logicalIndex) {
            $width = $glyphs[$logicalIndex]['w'];
            if (0 === $width) {
                continue; // the zero-width cursor marker occupies no column
            }
            if ($visualCol < ($visual + $width)) {
                return $logicalStart[$logicalIndex] + ($visualCol - $visual);
            }
            $visual += $width;
        }

        return $acc; // a click past the content maps to the logical line end
    }

    /** Bidi class of a grapheme's base codepoint: R (rtl), EN (digit), L, or N (neutral). */
    private static function classify(string $grapheme): string
    {
        // The zero-width cursor marker (and any stray control sequence) is neutral.
        if (str_starts_with($grapheme, "\x1b")) {
            return 'N';
        }
        // A grapheme cluster may carry combining marks; classify by its base
        // codepoint (always present — \X matches at least one).
        $cp = mb_ord(mb_substr($grapheme, start: 0, length: 1, encoding: 'UTF-8'), encoding: 'UTF-8');
        // Hebrew, Arabic and their presentation forms → right-to-left.
        if ($cp >= 0x0590 && $cp <= 0x05FF || $cp >= 0x0600 && $cp <= 0x06FF || $cp >= 0x0700 && $cp <= 0x08FF || $cp >= 0xFB1D && $cp <= 0xFDFF || $cp >= 0xFE70 && $cp <= 0xFEFF) {
            return 'R';
        }
        if ($cp >= 0x0030 && $cp <= 0x0039) {
            return 'EN';
        }
        // ASCII space/punctuation/symbols and the common Unicode spaces are neutral.
        if (0x20 === $cp || 0xA0 === $cp || $cp >= 0x21 && $cp <= 0x2F || $cp >= 0x3A && $cp <= 0x40 || $cp >= 0x5B && $cp <= 0x60 || $cp >= 0x7B && $cp <= 0x7E || $cp >= 0x2000 && $cp <= 0x206F) {
            return 'N';
        }

        return 'L';
    }

    /**
     * Embedding level per cell: base 1 (rtl) or 0 (ltr); L/EN embed at the next
     * even level, R at the next odd level, and neutral runs resolve to the
     * matching side (N1) or the base direction (N2), numbers counting as R (N1).
     *
     * @param list<string> $classes
     *
     * @return list<int>
     */
    private static function levels(array $classes, BaseDirection $direction): array
    {
        $baseRtl = BaseDirection::Rtl === $direction;
        $base = $baseRtl ? 1 : 0;
        $count = \count($classes);

        // Resolve each neutral run to 'L' or 'R' from its strong neighbours,
        // treating numbers as R for the boundary test and the base at the edges.
        $resolved = $classes;
        $boundary = static fn (string $c): string => 'L' === $c ? 'L' : 'R'; // R, EN, (resolved) → R
        $baseDir = $baseRtl ? 'R' : 'L';
        $i = 0;
        while ($i < $count) {
            if ('N' !== $classes[$i]) {
                ++$i;
                continue;
            }
            $start = $i;
            while ($i < $count && 'N' === $classes[$i]) {
                ++$i;
            }
            $before = $start > 0 ? $boundary($classes[$start - 1]) : $baseDir;
            $after = $i < $count ? $boundary($classes[$i]) : $baseDir;
            $dir = $before === $after ? $before : $baseDir;
            for ($j = $start; $j < $i; ++$j) {
                $resolved[$j] = $dir;
            }
        }

        $levels = [];
        foreach ($resolved as $class) {
            $levels[] = match ($class) {
                'R' => $baseRtl ? $base : $base + 1,
                default => $baseRtl ? $base + 1 : $base, // L / EN embed one above an rtl base
            };
        }

        return $levels;
    }

    /**
     * Visual cell order (indices) from embedding levels via rule L2: from the
     * highest level down to the lowest odd level, reverse every contiguous run
     * at that level or above.
     *
     * @param list<int> $levels
     *
     * @return list<int>
     */
    private static function visualOrder(array $levels): array
    {
        $order = range(0, \count($levels) - 1);
        $max = max($levels);
        // Descend to the lowest odd level (1) even when no cell sits exactly
        // there, so an LTR run under an RTL base is reversed back to readable
        // order after the whole-line reversal positions it.
        for ($level = $max; $level >= 1; --$level) {
            $i = 0;
            $n = \count($order);
            while ($i < $n) {
                if ($levels[$order[$i]] < $level) {
                    ++$i;
                    continue;
                }
                $start = $i;
                while ($i < $n && $levels[$order[$i]] >= $level) {
                    ++$i;
                }
                $slice = array_reverse(\array_slice($order, $start, $i - $start));
                array_splice($order, $start, $i - $start, $slice);
            }
        }

        return $order;
    }

    /**
     * Re-emit the reordered cells, re-applying each cell's SGR state when it
     * changes and mirroring paired punctuation inside right-to-left runs (L4).
     *
     * @param list<array{g: string, sgr: string}> $cells
     * @param list<int>                           $levels
     * @param list<int>                           $order
     */
    private static function serialize(array $cells, array $levels, array $order): string
    {
        $out = '';
        $emitted = '';
        foreach ($order as $index) {
            $sgr = $cells[$index]['sgr'];
            if ($sgr !== $emitted) {
                $out .= '' === $sgr ? "\x1b[0m" : "\x1b[0m".$sgr;
                $emitted = $sgr;
            }
            $glyph = $cells[$index]['g'];
            if (1 === ($levels[$index] % 2) && array_key_exists($glyph, self::MIRROR)) {
                $glyph = self::MIRROR[$glyph];
            }
            $out .= $glyph;
        }
        if ('' !== $emitted) {
            $out .= "\x1b[0m";
        }

        return $out;
    }
}
