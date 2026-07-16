<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Parses an LSP completion snippet (`insertTextFormat: 2`) into its literal
 * text plus the tab stops the caret should visit.
 *
 * Supported syntax: `$1`/`$2`… tab stops, `$0` final caret, `${1:default}`
 * placeholders, `${1|a,b,c|}` choices (the first option is used as the
 * default), and `\$` / `\}` / `\\` escapes. Repeated indices emit their text
 * but only the first occurrence becomes a navigable stop (mirrors are not
 * linked). Nesting is flattened.
 */
final class SnippetParser
{
    /**
     * @return array{text: string, stops: list<SnippetTabStop>}
     *               `text` is what to insert; each stop's start/end are byte
     *               offsets into `text`, ordered 1,2,… then 0 (final) last
     */
    public static function parse(string $snippet): array
    {
        $text = '';
        /** @var array<int, SnippetTabStop> $stops keyed by index (first occurrence wins) */
        $stops = [];
        $len = \strlen($snippet);
        $i = 0;

        while ($i < $len) {
            $ch = $snippet[$i];

            if ('\\' === $ch && ($i + 1) < $len) {
                $text .= $snippet[$i + 1];
                $i += 2;

                continue;
            }

            if ('$' === $ch && ($i + 1) < $len) {
                // ${...}
                if ('{' === $snippet[$i + 1]) {
                    $close = self::matchingBrace($snippet, $i + 1);
                    if (-1 !== $close) {
                        $inner = substr($snippet, $i + 2, $close - $i - 2);
                        [$index, $default] = self::parsePlaceholder($inner);
                        if (null !== $index) {
                            // Recurse so nested placeholders (${1:${2:x}}) flatten
                            // into sibling stops at the right offsets.
                            $sub = self::parse($default);
                            $start = \strlen($text);
                            $text .= $sub['text'];
                            self::recordStop($stops, $index, $start, \strlen($text));
                            foreach ($sub['stops'] as $nested) {
                                self::recordStop($stops, $nested->index, $start + $nested->start, $start + $nested->end);
                            }
                            $i = $close + 1;

                            continue;
                        }
                    }
                }
                // $N
                if (ctype_digit($snippet[$i + 1])) {
                    $j = $i + 1;
                    while ($j < $len && ctype_digit($snippet[$j])) {
                        ++$j;
                    }
                    $index = (int) substr($snippet, $i + 1, $j - $i - 1);
                    $start = \strlen($text);
                    self::recordStop($stops, $index, $start, $start);
                    $i = $j;

                    continue;
                }
            }

            $text .= $ch;
            ++$i;
        }

        return ['text' => $text, 'stops' => self::orderStops($stops)];
    }

    /** @param array<int, SnippetTabStop> $stops */
    private static function recordStop(array &$stops, int $index, int $start, int $end): void
    {
        if (!array_key_exists($index, $stops)) {
            $stops[$index] = new SnippetTabStop($index, $start, $end);
        }
    }

    /**
     * @param array<int, SnippetTabStop> $stops
     *
     * @return list<SnippetTabStop> ordered 1,2,… then 0 last
     */
    private static function orderStops(array $stops): array
    {
        $ordered = array_values($stops);
        usort($ordered, static function (SnippetTabStop $a, SnippetTabStop $b): int {
            // $0 (final caret) always sorts last.
            $ka = 0 === $a->index ? \PHP_INT_MAX : $a->index;
            $kb = 0 === $b->index ? \PHP_INT_MAX : $b->index;

            return $ka <=> $kb;
        });

        return $ordered;
    }

    /**
     * Split the inside of a `${...}` into [index, defaultText], or [null, '']
     * when it is not a tab stop (e.g. a `${VAR}` variable we render literally).
     *
     * @return array{0: int|null, 1: string}
     */
    private static function parsePlaceholder(string $inner): array
    {
        $m = [];
        if (preg_match('/^(\d+):(.*)$/s', $inner, $m)) {
            return [(int) $m[1], $m[2]];
        }
        if (preg_match('/^(\d+)\|([^|]*)\|$/s', $inner, $m)) {
            $choices = explode(',', $m[2]);

            return [(int) $m[1], $choices[0]];
        }
        if (preg_match('/^\d+$/', $inner)) {
            return [(int) $inner, ''];
        }

        return [null, ''];
    }

    /**
     * Index of the `}` matching the `{` at $open, or -1.
     */
    private static function matchingBrace(string $s, int $open): int
    {
        $depth = 0;
        $len = \strlen($s);
        for ($i = $open; $i < $len; ++$i) {
            if ('\\' === $s[$i]) {
                ++$i;

                continue;
            }
            if ('{' === $s[$i]) {
                ++$depth;

                continue;
            }
            if ('}' === $s[$i]) {
                if (0 === --$depth) {
                    return $i;
                }
            }
        }

        return -1;
    }
}
