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
        \sort($sorted, \SORT_STRING);

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
        $joined = \implode("\n", $block);
        $upper = \mb_strtoupper($joined);

        return \explode("\n", $joined === $upper ? \mb_strtolower($joined) : $upper);
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
        $joined = \rtrim($lines[$first]);
        for ($i = $first + 1; $i <= $last; ++$i) {
            $joined = \rtrim($joined.' '.\ltrim($lines[$i]));
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
            $trimmed = \ltrim($line);
            if ('' !== $trimmed && !\str_starts_with($trimmed, $prefix)) {
                $allCommented = false;
                break;
            }
        }

        $out = [];
        foreach ($block as $line) {
            $trimmed = \ltrim($line);
            if ('' === $trimmed) {
                $out[] = $line;

                continue;
            }
            $indent = \strlen($line) - \strlen($trimmed);
            if ($allCommented) {
                $rest = \substr($trimmed, \strlen($prefix));
                // One space: the one commenting adds. The rest is the line's own
                // indent, kept when a comment at column 0 sits on indented code.
                $out[] = \substr($line, offset: 0, length: $indent).(\str_starts_with($rest, ' ') ? \substr($rest, offset: 1) : $rest);

                continue;
            }
            $out[] = \substr($line, offset: 0, length: $indent).$prefix.' '.$trimmed;
        }

        return $out;
    }

    /**
     * The block with every non-blank line WRAPPED in $open/$close, or unwrapped
     * when they all already are.
     *
     * This is what makes `Ctrl+/` work in a language whose only comment form is
     * a block — Twig, HTML, Markdown, CSS. It is deliberately per-line rather
     * than one pair around the whole range, because that is what the LINE verb
     * means: each line is independently commented out, so deleting one of them
     * afterwards cannot orphan a closer.
     *
     * @param string[] $block
     *
     * @return list<string>
     */
    public static function blockCommentToggled(array $block, string $open, string $close): array
    {
        $allCommented = true;
        foreach ($block as $line) {
            $trimmed = \trim($line);
            if ('' !== $trimmed && null === self::blockUnwrapped($trimmed, $open, $close)) {
                $allCommented = false;
                break;
            }
        }

        $out = [];
        foreach ($block as $line) {
            $trimmed = \trim($line);
            if ('' === $trimmed) {
                $out[] = $line;

                continue;
            }
            $indent = \substr($line, offset: 0, length: \strlen($line) - \strlen(\ltrim($line)));
            // Asked only while uncommenting, so the null branch below reads as
            // one condition rather than as `$allCommented` plus a null check
            // the loop above has already proved cannot fire.
            $unwrapped = $allCommented ? self::blockUnwrapped($trimmed, $open, $close) : null;
            $out[] = null !== $unwrapped
                ? $indent.$unwrapped
                : $indent.$open.' '.$trimmed.' '.$close;
        }

        return $out;
    }

    /**
     * $text with its enclosing block comment removed, or null when it is not
     * one.
     *
     * The length clause is the whole reason this is a function rather than a
     * `str_starts_with` beside a `str_ends_with`: `<!-->` starts with `<!--`
     * AND ends with `-->` while being neither — five characters cannot hold a
     * four- and a three-character delimiter — so the naive test computes a
     * negative inner length and hands `substr` a garbage range.
     */
    public static function blockUnwrapped(string $text, string $open, string $close): ?string
    {
        if (!\str_ends_with($text, $close) || !\str_starts_with($text, $open) || \strlen($text) < (\strlen($open) + \strlen($close))) {
            return null;
        }

        return \trim(\substr($text, \strlen($open), \strlen($text) - \strlen($open) - \strlen($close)));
    }

    /**
     * The closers $line opened and never shut, in the order they must be typed.
     *
     * A stack, not a count per bracket kind: `foo([a` needs `])` and not `)]`,
     * and only a stack knows which. A closer with no opener (`a)b`) pops
     * nothing and is ignored — the line is already wrong in a way this verb
     * cannot fix, and inventing an opener for it would make it worse.
     *
     * Quotes are NOT tracked, so `"a("` counts an unclosed paren. Recognising
     * that needs a lexer; {@see \Porta\Editor\Document::completeStatement()}
     * carries the limit in its docblock.
     */
    public static function missingClosers(string $line): string
    {
        $pairs = ['(' => ')', '[' => ']'];
        $stack = [];
        foreach (\str_split($line) as $char) {
            if (\array_key_exists($char, $pairs)) {
                $stack[] = $pairs[$char];

                continue;
            }
            if ([] !== $stack && $char === $stack[\count($stack) - 1]) {
                \array_pop($stack);
            }
        }

        return \implode('', \array_reverse($stack));
    }

    /**
     * Whether $line still needs $terminator on the end.
     *
     * Not merely "does it already end in one". A line ending in an opening or
     * separating character is a statement that CONTINUES — `foo(`, `[1,`,
     * `a &&` — and terminating it there would cut it in half. So would adding
     * one after a block brace, which is not a statement at all.
     */
    public static function wantsTerminator(string $line, string $terminator): bool
    {
        $trimmed = \rtrim($line);
        if ('' === $trimmed || \str_ends_with($trimmed, $terminator)) {
            return false;
        }

        return !\in_array(\substr($trimmed, offset: -1), ['{', '}', ',', ':', '(', '[', '&', '|', '+', '-', '*', '/', '.', '=', '?'], strict: true);
    }

    /**
     * Whether $line still needs its block opened.
     *
     * The mirror of {@see wantsTerminator()}, and missing from the first
     * version of complete-statement — so `if ($x) {` gained a SECOND brace
     * (`if ($x) { {`) and Python's `if x:` a second colon. Pressing the key
     * twice is all it took.
     *
     * A line already ending in the opener has its block; so has one ending in
     * the CLOSER, which is the whole one-line form `if ($x) { run(); }`. The
     * opener is trimmed because the table stores it as it is appended, leading
     * space and all.
     */
    public static function wantsBlockOpener(string $line, string $opener, ?string $closer): bool
    {
        $trimmed = \rtrim($line);
        if ('' === $trimmed || \str_ends_with($trimmed, \trim($opener))) {
            return false;
        }

        return null === $closer || !\str_ends_with($trimmed, $closer);
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
        $up = $delta < 0;
        if ($up ? 0 === $first : $last === ($n - 1)) {
            return null;
        }
        $step = 1;
        if ($up && '' === \trim($lines[$first - 1])) {
            $g = $first - 1;
            while ($g > 0 && '' === \trim($lines[$g - 1])) {
                --$g;
            }
            $step = $first - $g;
        }
        if (!$up && '' === \trim($lines[$last + 1])) {
            $g = $last + 1;
            while ($g < ($n - 1) && '' === \trim($lines[$g + 1])) {
                ++$g;
            }
            $step = $g - $last;
        }

        return $up ? \max(0, $first - $step) : \min($first + $step, $first + ($n - 1 - $last));
    }
}
