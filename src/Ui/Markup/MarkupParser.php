<?php

declare(strict_types=1);

namespace Porta\Ui\Markup;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Symfony Console's formatter markup, parsed into a {@see MarkupNode} tree:
 * `<tag>…</tag>` or `<tag>…</>`, `\<` for a literal `<`, and Console's own tag
 * grammar and escaping ({@see OutputFormatter::escape()}), so a string written
 * for a Console command reads the same here. What differs is only where the
 * styles come from — {@see StyleSpec} — and that a tag Console would reject
 * with an exception (an unknown color, a stray close) stays text instead: a
 * render path has nowhere to throw to.
 */
final class MarkupParser
{
    /** OutputFormatter::formatAndWrap()'s tag pattern, verbatim. */
    private const string TAG = '#<(([a-z](?:[^\\\\<>]*+ | \\\\.)*) | /([a-z][^<>]*+)?)>#ix';

    /** $text made safe to splice into markup: every `<` and `>` it holds stays text. */
    public static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    /** $markup's text alone, with the tags that parse dropped and the escapes resolved. */
    public static function strip(string $markup): string
    {
        return self::parse($markup)->plain();
    }

    public static function parse(string $markup): MarkupNode
    {
        $root = [];
        $open = [];
        $found = [];
        \preg_match_all(self::TAG, $markup, $found, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        /** @var mixed $raw */
        $raw = $found;
        /** @var list<array<int, array{0: string, 1: int}>> $matches */
        $matches = $raw;
        $offset = 0;
        foreach ($matches as $match) {
            [$text, $position] = $match[0];
            if (0 !== $position && '\\' === $markup[$position - 1]) {
                continue; // an escaped `\<`: text, resolved with the rest
            }
            self::text($open, $root, \substr($markup, $offset, $position - $offset));
            $offset = $position + \strlen($text);
            if (!self::tag($open, $root, $text, $match)) {
                self::text($open, $root, $text); // what Console would not style, it prints
            }
        }
        self::text($open, $root, \substr($markup, $offset));
        while ([] !== $open) {
            self::close($open, $root); // an unclosed tag runs to the end, as in Console
        }

        return new MarkupNode(null, $root);
    }

    /**
     * Act on one tag: open a span, or close one. False when the tag styles
     * nothing — unknown, malformed, or a close with no match.
     *
     * @param list<array{tag: string, spec: StyleSpec, children: list<string|MarkupNode>}> $open
     * @param list<string|MarkupNode>                                                     $root
     * @param array<int, array{0: string, 1: int}>                                        $match
     */
    private static function tag(array &$open, array &$root, string $text, array $match): bool
    {
        if ('/' !== $text[1]) {
            $tag = $match[2][0];
            $spec = StyleSpec::fromTag($tag);
            if (null !== $spec) {
                $open[] = ['tag' => $tag, 'spec' => $spec, 'children' => []];
            }

            return null !== $spec;
        }

        return self::closeTag($open, $root, $match[3][0] ?? '');
    }

    /**
     * A closing tag: `</>` closes the innermost span, `</tag>` the innermost
     * `<tag>` and every span inside it. A bare `</>` with nothing open is
     * Console's no-op; a named close that matches nothing Console would throw
     * on, and here it prints — as does one naming no style at all, which
     * looks for no span.
     *
     * @param list<array{tag: string, spec: StyleSpec, children: list<string|MarkupNode>}> $open
     * @param list<string|MarkupNode>                                                     $root
     */
    private static function closeTag(array &$open, array &$root, string $tag): bool
    {
        $depth = '' === $tag || null !== StyleSpec::fromTag($tag) ? \count($open) : 0;
        while ($depth > 0 && '' !== $tag && $open[$depth - 1]['tag'] !== $tag) {
            --$depth;
        }
        while ($depth > 0 && \count($open) >= $depth) {
            self::close($open, $root);
        }

        return $depth > 0 || '' === $tag;
    }

    /**
     * @param list<array{tag: string, spec: StyleSpec, children: list<string|MarkupNode>}> $open
     * @param list<string|MarkupNode>                                                     $root
     */
    private static function close(array &$open, array &$root): void
    {
        $span = \array_pop($open);
        if (null !== $span) {
            self::append($open, $root, new MarkupNode($span['spec'], $span['children']));
        }
    }

    /**
     * @param list<array{tag: string, spec: StyleSpec, children: list<string|MarkupNode>}> $open
     * @param list<string|MarkupNode>                                                     $root
     */
    private static function text(array &$open, array &$root, string $text): void
    {
        if ('' !== $text) {
            self::append($open, $root, \strtr($text, ["\0" => '\\', '\\<' => '<', '\\>' => '>']));
        }
    }

    /**
     * Add $child to the innermost open span, or the root. Text next to text
     * joins it, so a tag printed as text never splits a run.
     *
     * @param list<array{tag: string, spec: StyleSpec, children: list<string|MarkupNode>}> $open
     * @param list<string|MarkupNode>                                                     $root
     */
    private static function append(array &$open, array &$root, string|MarkupNode $child): void
    {
        $top = \array_key_last($open);
        $children = null === $top ? $root : $open[$top]['children'];
        $last = \array_key_last($children);
        $joins = \is_string($child) && null !== $last && \is_string($children[$last]);
        $children[$joins ? $last : \count($children)] = $joins ? $children[$last].$child : $child;
        if (null === $top) {
            $root = $children;

            return;
        }
        $open[$top]['children'] = $children;
    }
}
