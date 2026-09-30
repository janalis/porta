<?php

declare(strict_types=1);

namespace Porta\I18n;

use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A pragmatic UAX#9-subset reorderer for a single already-composed terminal
 * line: it splits the visible text into directional runs (Hebrew/Arabic → RTL,
 * Latin/CJK/digits → LTR), resolves neutral characters and reorders to visual
 * order for a chosen base direction, preserving SGR colour spans and wide-cell
 * widths. Applied as a final post-process on rtl-locale render output, it makes
 * Hebrew read right-to-left while paths, code and numbers stay left-to-right, and
 * right-anchors LTR content (trailing pad becomes leading pad) under an RTL base.
 *
 * Scope: W4 to W7 of the W-rules, and N0. W4 folds a single separator between
 * two European numbers into the number, so a line:column indicator, a decimal
 * and a thousands group survive; W5 binds a terminator to its number (`42%`)
 * and W6 leaves any other one neutral; W7 makes a number preceded by Latin
 * text Latin, so a path with a line number, a charset like `UTF-8` and a
 * version stay in one piece. N0 pairs brackets, so a parenthesised group keeps
 * both halves on one side of the text around it. Absent: W1-W3, explicit
 * embedding controls (LRE/RLE/PDF), isolates (LRI/RLI/FSI/PDI), Arabic
 * shaping, and paragraph-level base detection (P2/P3 — the base is always
 * passed in).
 * These cover the IDE's chrome; richer bidi is out of scope.
 *
 * Where a run still needs pinning, the directional marks work: U+200E and
 * U+200F are strong here, measure no column, and are spent on the way out.
 * A caller that wants a gap beside one writes the space itself.
 */
final class Bidi
{
    /**
     * The zero-width marks that pin a run's direction where the text around it
     * cannot. Strong here, zero-width, and spent on the way out, so a caller
     * can place one without changing what anything measures.
     */
    public const string LTR_MARK = "\u{200E}";
    public const string RTL_MARK = "\u{200F}";

    /**
     * A right-to-left ISOLATE and its terminator.
     *
     * An isolate is the only construct that says "lay this span out
     * right-to-left" WITHOUT letting it reorder anything around it: from the
     * outside the whole span counts as one neutral object. That is exactly a
     * pane. An embedding (RLE) or a bare mark would set the paragraph direction
     * and reorder the panes against each other instead — which is the bug these
     * are being added to avoid.
     */
    public const string RTL_ISOLATE = "\u{2067}";
    public const string POP_ISOLATE = "\u{2069}";

    /**
     * A left-to-right OVERRIDE and its pop.
     *
     * The strongest statement available: every character inside is treated as
     * strong left-to-right, whatever it actually is, so nothing reorders and
     * nothing reverses. For a pane Porta has ALREADY laid out visually that is
     * the whole requirement — "draw these cells in this order" — and unlike an
     * isolate it does not depend on the terminal implementing embedding levels
     * correctly, only on it honouring an override at all.
     */
    public const string LTR_OVERRIDE = "\u{202D}";
    public const string POP_DIRECTIONAL = "\u{202C}";

    /**
     * Zero-width directional marks, dropped once they have been read. They
     * measure no column and draw none, but they have already steered this
     * reorder, and a terminal running UAX#9 would steer again with them.
     *
     * @var array<string, true>
     */
    private const array SPENT = [
        self::LTR_MARK => true,
        self::RTL_MARK => true,
        self::RTL_ISOLATE => true,
        self::POP_ISOLATE => true,
        self::LTR_OVERRIDE => true,
        self::POP_DIRECTIONAL => true,
        "\u{061C}" => true,
    ];

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
        // Glyphs that POINT. An arrow keeping its left-to-right sense inside a
        // mirrored run points away from the thing it introduces — a rename's
        // ← away from the new name, a tree's ▸ away from the children it opens.
        // ▲ ▼ ▾ are deliberately absent: mirroring is horizontal, and a scroll
        // indicator that flipped would point the wrong way up.
        '←' => '→',
        '→' => '←',
        '▸' => '◂',
        '◂' => '▸',
        '▶' => '◀',
        '◀' => '▶',
        '▌' => '▐',
        '▐' => '▌',
        '▎' => '▕',
        '▕' => '▎',
        '┛' => '┗',
        '┗' => '┛',
        // Frame corners and tees: a border row caught inside an RTL run must
        // keep its box shape when the run reverses (rounded modal family and
        // the square focus-frame family alike).
        '╭' => '╮',
        '╮' => '╭',
        '╰' => '╯',
        '╯' => '╰',
        '├' => '┤',
        '┤' => '├',
        '┌' => '┐',
        '┐' => '┌',
        '└' => '┘',
        '┘' => '└',
        // The text fields' heavy (focused) and double (danger) families: the
        // field's border SHAPE is its state, so a focused box in a mirrored
        // pane must still close — ┛┗ are above, as the resize grabs.
        '┏' => '┓',
        '┓' => '┏',
        '╔' => '╗',
        '╗' => '╔',
        '╚' => '╝',
        '╝' => '╚',
        '┍' => '┑',
        '┑' => '┍',
        '┕' => '┙',
        '┙' => '┕',
        '┣' => '┫',
        '┫' => '┣',
        // The focused folder tab's rails landing on the strip's baseline.
        '┚' => '┖',
        '┖' => '┚',
        '┒' => '┎',
        '┎' => '┒',
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
        if (!\preg_match_all('/\x1b\[[0-9;]*m|\x1b_[^\x07]*\x07|\X/u', $line, $matches)) {
            return $line;
        }

        /** @var list<array{g: string, sgr: string}> $cells */
        $cells = [];
        $active = '';
        foreach ($matches[0] as $seq) {
            if (\str_starts_with($seq, "\x1b[")) { // SGR: updates the active style
                $active = Sgr::RESET === $seq || "\x1b[m" === $seq ? '' : $active.$seq;
                continue;
            }
            // A grapheme, or the zero-width cursor marker carried as a neutral cell
            // so the reorder lands it at the caret's visual column.
            $cells[] = ['g' => $seq, 'sgr' => $active];
        }

        if ([] === $cells) {
            return $line; // pure escape sequence(s); nothing to reorder
        }

        $classes = \array_map(static fn (array $c): string => self::classify($c['g']), $cells);
        if (BaseDirection::Ltr === $direction && !\in_array('R', $classes, strict: true)) {
            return $line; // LTR base with no RTL runs: already in visual order
        }

        $levels = self::levels($classes, \array_column($cells, column_key: 'g'), $direction);
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
        if ($visualCol < 0 || !\preg_match_all('/\x1b\[[0-9;]*m|\x1b_[^\x07]*\x07|\X/u', $line, $matches)) {
            return \max(0, $visualCol);
        }

        /** @var list<array{g: string, w: int}> $glyphs */
        $glyphs = [];
        foreach ($matches[0] as $token) {
            if (\str_starts_with($token, "\x1b[")) {
                continue; // SGR carries no column
            }
            // The APC cursor marker prints nothing; only real graphemes take width.
            $width = \str_starts_with($token, "\x1b") ? 0 : AnsiUtils::visibleWidth($token);
            $glyphs[] = ['g' => $token, 'w' => $width];
        }
        if ([] === $glyphs) {
            return $visualCol;
        }

        $classes = \array_map(static fn (array $c): string => self::classify($c['g']), $glyphs);
        if (BaseDirection::Ltr === $direction && !\in_array('R', $classes, strict: true)) {
            return $visualCol;
        }

        $levels = self::levels($classes, \array_column($glyphs, column_key: 'g'), $direction);
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

    /** Bidi class of a grapheme's base codepoint: R (rtl), EN (digit), ET, CS, L, or N (neutral). */
    private static function classify(string $grapheme): string
    {
        // The zero-width cursor marker (and any stray control sequence) is neutral.
        if (\str_starts_with($grapheme, "\x1b")) {
            return 'N';
        }
        // A grapheme cluster may carry combining marks; classify by its base
        // codepoint (always present — \X matches at least one).
        $cp = \mb_ord(\mb_substr($grapheme, start: 0, length: 1, encoding: 'UTF-8'), encoding: 'UTF-8');
        // Hebrew, Arabic and their presentation forms → right-to-left.
        if ($cp >= 0x0590 && $cp <= 0x05FF || $cp >= 0x0600 && $cp <= 0x06FF || $cp >= 0x0700 && $cp <= 0x08FF || $cp >= 0xFB1D && $cp <= 0xFDFF || $cp >= 0xFE70 && $cp <= 0xFEFF) {
            return 'R';
        }
        if ($cp >= 0x0030 && $cp <= 0x0039) {
            return 'EN';
        }
        // ASCII space/punctuation/symbols, the common Unicode spaces, and the
        // symbol blocks the interface draws from — all Unicode class ON.
        //
        // The fallback below is `L`, so anything missing from this list is
        // declared Latin TEXT: a marker classified that way glues itself into
        // the adjacent Latin run rather than taking the direction of the text
        // around it, and lands on the wrong side of the name it belongs to.
        // Forty of Glyphs' seventy-six constants did exactly that — arrows,
        // triangles, blocks, ticks and the close ×. The list is held honest by
        // a test that derives its set from {@see \Porta\Ui\Glyphs} rather than
        // repeating it, since a list of glyphs is wrong the first time someone
        // adds one.
        // The directional marks carry no glyph and no width; their whole job is
        // to give the neutral text beside them a direction. Classified as
        // neutral — which the U+2000-206F range below would do — they achieve
        // exactly nothing, and the Hebrew catalog already ships nine strings
        // using them. They are spent in serialize(); they take no column, so
        // dropping them leaves the row the width every measurer gave it.
        if (0x200E === $cp) {
            return 'L';
        }
        // U+061C ARABIC LETTER MARK needs no branch here — it already falls in
        // the Arabic block above and classifies R. It appears in SPENT below
        // because it is zero-width like the other two.
        if (0x200F === $cp) {
            return 'R';
        }

        // UAX#9 ET: a terminator that sticks to the number beside it (W5
        // below) — `42%`, `$5`, `#3`, `20°` — and is neutral anywhere else.
        if (0x23 === $cp || 0x24 === $cp || 0x25 === $cp || 0xB0 === $cp || 0xB1 === $cp || 0x2030 === $cp) {
            return 'ET';
        }

        // UAX#9 CS/ES: the separators that can JOIN two numbers (W4 below).
        // Neutral everywhere else, which is what the W4 pass falls back to —
        // they are listed here only so that pass can tell them from a bracket.
        if (0x2B === $cp || 0x2C === $cp || 0x2D === $cp || 0x2E === $cp || 0x2F === $cp || 0x3A === $cp) {
            return 'CS';
        }

        // Split in two, and kept in locals rather than a helper call: the
        // analyzer stops applying type assertions to a condition this long, and
        // this runs per grapheme per row per frame.
        $asciiNeutral =
            0x20 === $cp
            || 0xA0 === $cp
            || 0xAB === $cp
            || 0xBB === $cp // « » — in MIRROR, so they must be able to reach L4
            || 0xB7 === $cp // · MIDDLE DOT (the hint severity, and a shown space)
            || 0xD7 === $cp // × MULTIPLICATION SIGN (the close button)
            || $cp >= 0x21 && $cp <= 0x2F
            || $cp >= 0x3A && $cp <= 0x40
            || $cp >= 0x5B && $cp <= 0x60
            || $cp >= 0x7B && $cp <= 0x7E;
        $symbolNeutral =
            $cp >= 0x2000 && $cp <= 0x206F
            || $cp >= 0x2190 && $cp <= 0x21FF // arrows
            || $cp >= 0x2200 && $cp <= 0x22FF // mathematical operators (the find bar's ∀)
            || $cp >= 0x2300 && $cp <= 0x23FF // misc technical (⏸)
            || $cp >= 0x2500 && $cp <= 0x257F // box drawing (frame borders)
            || $cp >= 0x2580 && $cp <= 0x259F // block elements (the error stripe)
            || $cp >= 0x25A0 && $cp <= 0x25FF // geometric shapes (▸ ▾ ● ◆)
            || $cp >= 0x2600 && $cp <= 0x27BF; // misc symbols and dingbats (✓ ✗)
        if ($asciiNeutral || $symbolNeutral) {
            return 'N';
        }

        // Everything else — Latin, CJK, and the Private Use Area, which Unicode
        // assigns to L and where the branch icon lives beside its Latin name.
        return 'L';
    }

    /**
     * Embedding level per cell: base 1 (rtl) or 0 (ltr); L/EN embed at the next
     * even level, R at the next odd level, and neutral runs resolve to the
     * matching side (N1) or the base direction (N2), numbers counting as R (N1).
     *
     * @param list<string> $classes
     * @param list<string> $glyphs  the cells the classes were read from, for N0
     *
     * @return list<int>
     */
    private static function levels(array $classes, array $glyphs, BaseDirection $direction): array
    {
        $baseRtl = BaseDirection::Rtl === $direction;
        $base = $baseRtl ? 1 : 0;
        $count = \count($classes);

        // Resolve each neutral run to 'L' or 'R' from its strong neighbours,
        // treating numbers as R for the boundary test and the base at the edges.
        $classes = self::pairBrackets(self::numbersAfterLatinReadAsLatin(self::numberTerminators(self::joinNumberSeparators($classes)), $direction), $glyphs, $direction);
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
     * UAX#9 W4: a SINGLE separator between two European numbers is part of the
     * number; every other separator is an ordinary neutral.
     *
     * Without this the `:` in a line:column indicator resolved to the
     * surrounding right-to-left direction, split the two digit runs and swapped
     * them — the status bar read `5:12` with the caret at line 12, column 5.
     *
     * Judged against the classes as they arrived rather than as this loop
     * leaves them, which makes the pass order-independent by construction. It
     * cannot currently matter — for a separator to be swayed by its neighbour
     * having just joined a number, that neighbour would need a number on BOTH
     * sides, and one of its sides is this separator. Mutating the copy away
     * changes no output, and it is kept for the shape rather than the effect.
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private static function joinNumberSeparators(array $classes): array
    {
        $original = $classes;
        $last = \count($original) - 1;
        foreach ($original as $i => $class) {
            if ('CS' !== $class) {
                continue;
            }

            $joins = $i > 0 && $i < $last && 'EN' === $original[$i - 1] && 'EN' === $original[$i + 1];
            $classes[$i] = $joins ? 'EN' : 'N';
        }

        return $classes;
    }

    /**
     * UAX#9 W5 and W6: a run of terminators touching a European number is
     * part of it; any other terminator is an ordinary neutral.
     *
     * Without it the `%` of a progress figure resolved alone, took the
     * paragraph direction and left for the far side: symfony-lsp's download
     * read `מוריד %42`. Found by running every Hebrew catalog string through
     * fribidi beside this class.
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private static function numberTerminators(array $classes): array
    {
        $count = \count($classes);
        $i = 0;
        while ($i < $count) {
            if ('ET' !== $classes[$i]) {
                ++$i;
                continue;
            }
            $start = $i;
            while ($i < $count && 'ET' === $classes[$i]) {
                ++$i;
            }
            $touches = $start > 0 && 'EN' === $classes[$start - 1] || $i < $count && 'EN' === $classes[$i];
            for ($j = $start; $j < $i; ++$j) {
                $classes[$j] = $touches ? 'EN' : 'N';
            }
        }

        return $classes;
    }

    /**
     * UAX#9 W7: a European number preceded by Latin text IS Latin.
     *
     * Search back from each number to the first STRONG type — neutrals and
     * other numbers do not count, and the run starts at the paragraph direction
     * — and an `L` there makes the number `L` too.
     *
     * Without it a digit stays a number, and numbers act as right-to-left for
     * neutral resolution (N1), so the separator in front of one takes the
     * paragraph direction and splits the token it belongs to: `UTF-8` renders
     * `8-UTF`, `src/Foo.php:42` renders `42:src/Foo.php`.
     *
     * Runs AFTER W4, as the rules are numbered: W4 has already folded the
     * separator between two numbers into the number, so `12:5` is one run by
     * the time this looks at it — and finds the paragraph direction behind it,
     * not Latin, so a caret indicator keeps reading as a number.
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private static function numbersAfterLatinReadAsLatin(array $classes, BaseDirection $direction): array
    {
        $strong = BaseDirection::Rtl === $direction ? 'R' : 'L';
        foreach ($classes as $i => $class) {
            if ('L' === $class || 'R' === $class) {
                $strong = $class;
                continue;
            }
            if ('EN' === $class && 'L' === $strong) {
                $classes[$i] = 'L';
            }
        }

        return $classes;
    }

    /** The opening bracket each closing one pairs with (UAX#9 BD16, Bidi_Paired_Bracket). */
    private const array BRACKET_PAIRS = [')' => '(', ']' => '[', '}' => '{'];

    /** BD16's stack depth: past it, the rest of the paragraph pairs nothing. */
    private const int BRACKET_STACK_LIMIT = 63;

    /**
     * UAX#9 N0: a matched bracket pair takes ONE direction, from what it holds.
     *
     * Holding strong text of the paragraph direction, both halves take it.
     * Holding only the opposite direction, they take that when the strong text
     * before the opening bracket agrees, and the paragraph direction when it
     * does not. Holding nothing strong, they stay neutral for N1.
     *
     * Without it each half resolved alone against its own neighbours, and a
     * Hebrew label ending `(LSP)` came out `LSP))`: the opening bracket sat
     * between Hebrew and Latin and took the paragraph's side, the closing one
     * sat between Latin and whatever followed and took another.
     *
     * Pairs resolve in the order they open, and a resolved pair counts as
     * strong for the ones after it, as the rule says.
     *
     * @param list<string> $classes
     * @param list<string> $glyphs
     *
     * @return list<string>
     */
    private static function pairBrackets(array $classes, array $glyphs, BaseDirection $direction): array
    {
        $embedding = BaseDirection::Rtl === $direction ? 'R' : 'L';
        foreach (self::bracketPairs($glyphs) as [$open, $close]) {
            $pairDirection = self::pairDirection($classes, $open, $close, $embedding);
            if (null === $pairDirection) {
                continue;
            }
            $classes[$open] = $pairDirection;
            $classes[$close] = $pairDirection;
        }

        return $classes;
    }

    /**
     * The direction N0 gives the pair at $open and $close; null when it holds
     * nothing strong and is left to N1.
     *
     * @param list<string> $classes
     */
    private static function pairDirection(array $classes, int $open, int $close, string $embedding): ?string
    {
        $inside = self::strongIn($classes, $open + 1, $close);
        if (\in_array($embedding, $inside, strict: true)) {
            return $embedding;
        }
        if ([] === $inside) {
            return null;
        }

        // Only the opposite direction inside: it wins when the text before agrees.
        $opposite = 'R' === $embedding ? 'L' : 'R';

        return $opposite === self::strongBefore($classes, $open, $embedding) ? $opposite : $embedding;
    }

    /**
     * Matched bracket pairs by BD16, ordered by their opening bracket.
     *
     * @param list<string> $glyphs
     *
     * @return list<array{int, int}>
     */
    private static function bracketPairs(array $glyphs): array
    {
        /** @var list<array{string, int}> $stack the open brackets, innermost last */
        $stack = [];
        /** @var list<array{int, int}> $pairs */
        $pairs = [];
        foreach ($glyphs as $i => $glyph) {
            if (\in_array($glyph, self::BRACKET_PAIRS, strict: true)) {
                if (self::BRACKET_STACK_LIMIT === \count($stack)) {
                    break;
                }
                $stack[] = [$glyph, $i];
                continue;
            }
            $opener = self::BRACKET_PAIRS[$glyph] ?? null;
            if (null === $opener) {
                continue;
            }
            for ($depth = \count($stack) - 1; $depth >= 0; --$depth) {
                [$bracket, $at] = $stack[$depth];
                if ($opener !== $bracket) {
                    continue;
                }
                $pairs[] = [$at, $i];
                $stack = \array_slice($stack, offset: 0, length: $depth);
                break;
            }
        }
        \sort($pairs);

        return $pairs;
    }

    /**
     * The strong directions in [$from, $to), a number counting as R (N0).
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private static function strongIn(array $classes, int $from, int $to): array
    {
        $found = [];
        for ($i = $from; $i < $to; ++$i) {
            if ('N' === $classes[$i]) {
                continue;
            }
            $found[] = 'L' === $classes[$i] ? 'L' : 'R';
        }

        return $found;
    }

    /**
     * The first strong direction before $index, the paragraph's when none is.
     *
     * @param list<string> $classes
     */
    private static function strongBefore(array $classes, int $index, string $embedding): string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            if ('N' !== $classes[$i]) {
                return 'L' === $classes[$i] ? 'L' : 'R';
            }
        }

        return $embedding;
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
        $order = \range(0, \count($levels) - 1);
        $max = \max($levels);
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
                $slice = \array_reverse(\array_slice($order, $start, $i - $start));
                \array_splice($order, $start, $i - $start, $slice);
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
                $out .= '' === $sgr ? Sgr::RESET : Sgr::RESET.$sgr;
                $emitted = $sgr;
            }
            $glyph = $cells[$index]['g'];
            if (1 === ($levels[$index] % 2) && \array_key_exists($glyph, self::MIRROR)) {
                $glyph = self::MIRROR[$glyph];
            }
            // The mark has done its work in levels(); it takes no column, so
            // dropping it changes nothing any measurer counted.
            $out .= \array_key_exists($glyph, self::SPENT) ? '' : $glyph;
        }
        if ('' !== $emitted) {
            $out .= Sgr::RESET;
        }

        return $out;
    }
}
