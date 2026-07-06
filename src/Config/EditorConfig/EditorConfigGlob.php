<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

/**
 * EditorConfig glob matching, compiled to a PCRE. Supports the full section-glob
 * grammar: `*` (any run except `/`), `**` (any run including `/`), `?` (one char
 * except `/`), `[seq]` / `[!seq]` character classes, `{a,b,c}` alternation (with
 * nesting) and `{n1..n2}` integer ranges, plus `\` escaping. A glob with no `/`
 * matches the file's basename at any depth; a glob containing `/` is anchored to
 * the directory of its `.editorconfig` (a leading `/` is just that anchor).
 *
 * Paths are matched with forward slashes, relative to the `.editorconfig` dir.
 */
final class EditorConfigGlob
{
    public static function matches(string $glob, string $relativePath): bool
    {
        return 1 === preg_match(self::compile($glob), $relativePath);
    }

    public static function compile(string $glob): string
    {
        if (str_contains($glob, '/')) {
            $body = '/' === $glob[0] ? substr($glob, 1) : $glob;
            $prefix = '';
        } else {
            $body = $glob;
            $prefix = '(?:.*/)?'; // no separator: match the basename at any depth
        }

        return '#^'.$prefix.self::compileSegment($body).'$#D';
    }

    private static function compileSegment(string $s): string
    {
        $out = '';
        $len = \strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            $c = $s[$i];
            if ('\\' === $c && ($i + 1) < $len) {
                $out .= preg_quote($s[$i + 1], '#');
                ++$i;
            } elseif ('*' === $c) {
                if (($i + 1) < $len && '*' === $s[$i + 1]) {
                    if (($i + 2) < $len && '/' === $s[$i + 2]) {
                        $out .= '(?:.*/)?'; // `**/` also matches zero directories
                        $i += 2;
                    } else {
                        $out .= '.*';
                        ++$i;
                    }
                } else {
                    $out .= '[^/]*';
                }
            } elseif ('?' === $c) {
                $out .= '[^/]';
            } elseif ('[' === $c) {
                $out .= self::compileClass($s, $i);
            } elseif ('{' === $c) {
                $out .= self::compileBrace($s, $i);
            } else {
                $out .= preg_quote($c, '#');
            }
        }

        return $out;
    }

    /** Compile `[seq]` / `[!seq]` starting at $i (advanced past the class), or a literal `[`. */
    private static function compileClass(string $s, int &$i): string
    {
        $len = \strlen($s);
        $j = $i + 1;
        $negate = $j < $len && '!' === $s[$j];
        if ($negate) {
            ++$j;
        }
        $start = $j;
        if ($j < $len && ']' === $s[$j]) {
            ++$j; // a `]` right after `[`/`[!` is a literal member
        }
        while ($j < $len && ']' !== $s[$j]) {
            if ('\\' === $s[$j]) {
                ++$j;
            }
            ++$j;
        }
        if ($j >= $len) {
            return preg_quote('[', '#'); // unterminated: treat `[` as a literal
        }

        $inner = substr($s, $start, $j - $start);
        $i = $j; // consume through the closing `]`

        return '['.($negate ? '^' : '').$inner.']';
    }

    /** Compile `{a,b}` / `{n1..n2}` starting at $i (advanced past the group), or a literal `{`. */
    private static function compileBrace(string $s, int &$i): string
    {
        $close = self::matchingBrace($s, $i);
        if (-1 === $close) {
            return preg_quote('{', '#'); // unmatched: treat `{` as a literal
        }

        $content = substr($s, $i + 1, $close - $i - 1);
        $i = $close; // consume through the closing `}`
        $parts = self::splitTopLevel($content);

        if (1 === \count($parts)) {
            $m = [];
            if (1 === preg_match('/^(-?\d+)\.\.(-?\d+)$/', $content, $m)) {
                return self::numericRange((int) $m[1], (int) $m[2]);
            }

            // A single choice with no comma is a literal `{...}`.
            return preg_quote('{', '#').self::compileSegment($content).preg_quote('}', '#');
        }

        return '(?:'.implode('|', array_map(self::compileSegment(...), $parts)).')';
    }

    private static function numericRange(int $from, int $to): string
    {
        $nums = [];
        if ($from <= $to) {
            for ($n = $from; $n <= $to; ++$n) {
                $nums[] = $n;
            }
        } else {
            for ($n = $from; $n >= $to; --$n) {
                $nums[] = $n;
            }
        }

        return '(?:'.implode('|', array_map(static fn (int $n): string => preg_quote((string) $n, '#'), $nums)).')';
    }

    /** Index of the `}` matching the `{` at $open (respecting nesting and classes), or -1. */
    private static function matchingBrace(string $s, int $open): int
    {
        $len = \strlen($s);
        $depth = 0;
        $inClass = false;
        for ($j = $open; $j < $len; ++$j) {
            $c = $s[$j];
            if ('\\' === $c) {
                ++$j;
                continue;
            }
            if ($inClass) {
                if (']' === $c) {
                    $inClass = false;
                }
                continue;
            }
            if ('[' === $c) {
                $inClass = true;
            } elseif ('{' === $c) {
                ++$depth;
            } elseif ('}' === $c) {
                --$depth;
                if (0 === $depth) {
                    return $j;
                }
            }
        }

        return -1;
    }

    /**
     * Split brace content on top-level commas (ignoring commas nested in inner
     * `{}` / `[]` or escaped).
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $len = \strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            $c = $s[$i];
            if ('\\' === $c && ($i + 1) < $len) {
                $buf .= $c.$s[$i + 1];
                ++$i;
                continue;
            }
            if ('{' === $c || '[' === $c) {
                ++$depth;
                $buf .= $c;
            } elseif ('}' === $c || ']' === $c) {
                $depth = max(0, $depth - 1);
                $buf .= $c;
            } elseif (',' === $c && 0 === $depth) {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $c;
            }
        }
        $parts[] = $buf;

        return $parts;
    }
}
