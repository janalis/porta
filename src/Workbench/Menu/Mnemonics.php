<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * Access letters for one menu's labels — the underlined letter a key press
 * picks, as a desktop menu offers. Worked out per render from the labels as
 * translated, so every locale gets letters of its own and none is hand-kept
 * in a catalogue.
 *
 * A label prefers the first letter of one of its words, in order, then any
 * other letter; letters are compared case-folded, and no two labels of the
 * menu share one. Letters are dealt in that order of preference, and a label
 * the deal leaves without one is then matched: a long menu dealt greedily ran
 * out — its early items took letters a later one had no alternative to — so
 * an earlier label gives way to another of its own letters when that frees
 * one. A label left with no letter even so has none: it is reached with the
 * arrows, as it always was.
 */
final class Mnemonics
{
    /**
     * @param list<string> $labels
     *
     * @return list<?int> each label's access letter, as a character offset into it, or null
     */
    public static function assign(array $labels): array
    {
        $candidates = \array_map(self::candidates(...), $labels);
        /** @var array<array-key, int> $owner the label each taken letter went to — a digit key is an int */
        $owner = [];
        // The deal: word-initials first, then any letter still free.
        foreach ([true, false] as $initialsOnly) {
            foreach ($candidates as $i => $keys) {
                if (\in_array($i, $owner, strict: true)) {
                    continue;
                }
                $free = \array_find_key($keys, static fn (int $at, int|string $key): bool => !\array_key_exists($key, $owner) && (!$initialsOnly || self::isInitial($labels[$i], $at)));
                if (null !== $free) {
                    $owner[$free] = $i;
                }
            }
        }
        // The match, for whoever the deal left out.
        foreach (\array_keys($labels) as $i) {
            if (\in_array($i, $owner, strict: true)) {
                continue;
            }
            $seen = [];
            self::claim($i, $candidates, $owner, $seen);
        }
        $letters = \array_fill(start_index: 0, count: \count($labels), value: null);
        foreach ($owner as $key => $i) {
            $letters[$i] = $candidates[$i][$key];
        }

        return $letters;
    }

    /**
     * A free letter for label $i, freeing one by moving its holder on to
     * another of its own when none is free (Kuhn's augmenting path).
     *
     * @param list<array<array-key, int>> $candidates
     * @param array<array-key, int>       $owner
     * @param array<array-key, true>      $seen
     */
    private static function claim(int $i, array $candidates, array &$owner, array &$seen): bool
    {
        foreach (\array_keys($candidates[$i]) as $key) {
            if (\array_key_exists($key, $seen)) {
                continue;
            }
            $seen[$key] = true;
            if (!\array_key_exists($key, $owner) || self::claim($owner[$key], $candidates, $owner, $seen)) {
                $owner[$key] = $i;

                return true;
            }
        }

        return false;
    }

    private static function isInitial(string $label, int $at): bool
    {
        return 0 === $at || null === self::key(\mb_substr($label, $at - 1, length: 1));
    }

    /**
     * Which of $labels the key press $typed picks — the one whose access
     * letter it spells — or null.
     *
     * @param list<string> $labels
     */
    public static function pick(array $labels, string $typed): ?int
    {
        $key = self::key($typed);
        if (null === $key) {
            return null;
        }

        return \array_find_key(self::assign($labels), static fn (?int $at, int $i): bool => null !== $at && self::key(\mb_substr($labels[$i], $at, length: 1)) === $key);
    }

    /**
     * A label's letters in order of preference — each word's first, then the
     * rest — keyed by what a key press must spell (a digit keys as an int),
     * valued by their offset.
     *
     * @return array<array-key, int>
     */
    private static function candidates(string $label): array
    {
        $chars = \mb_str_split($label);
        $initials = [];
        $others = [];
        foreach ($chars as $at => $char) {
            $key = self::key($char);
            if (null === $key) {
                continue;
            }
            $initial = 0 === $at || null === self::key($chars[$at - 1]);
            if ($initial) {
                $initials[$key] ??= $at;
            }
            $others[$key] ??= $at;
        }

        return $initials + $others;
    }

    /** What a key press must spell to pick $char, or null when $char is no letter or digit. */
    public static function key(string $char): ?string
    {
        return 1 === \preg_match('/^[\p{L}\p{N}]$/u', $char) ? \mb_strtolower($char) : null;
    }
}
