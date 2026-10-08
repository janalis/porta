<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * The pieces of a link the spec defines character by character: the label,
 * the destination and the title. Each scanner reads from a byte offset and
 * answers with what it found and where it stopped, or null.
 */
final class LinkSyntax
{
    private const int MAX_LABEL = 999;
    private const int MAX_NESTING = 32;

    /**
     * A link label (`[...]`) at $at: its raw inner text and the offset after `]`.
     *
     * @return array{string, int}|null
     */
    public static function label(string $text, int $at): ?array
    {
        $m = [];
        if (1 !== \preg_match('/\G\[((?:[^\\\\\[\]]|\\\\.){0,'.self::MAX_LABEL.'})\]/s', $text, $m, flags: 0, offset: $at)) {
            return null;
        }
        if ('' === \trim($m[1], characters: " \t\n\r\f\v")) {
            return null;
        }

        return [$m[1], $at + \strlen($m[0])];
    }

    /**
     * A link destination at $at: the unescaped destination and the offset after it.
     *
     * @return array{string, int}|null
     */
    public static function destination(string $text, int $at): ?array
    {
        $m = [];
        if ('<' === ($text[$at] ?? '')) {
            if (1 !== \preg_match('/\G<((?:[^<>\n\\\\\x00]|\\\\.)*)>/', $text, $m, flags: 0, offset: $at)) {
                return null;
            }

            return [Entities::unescape($m[1]), $at + \strlen($m[0])];
        }
        $end = self::bareDestinationEnd($text, $at);
        if (null === $end || $end === $at) {
            return null;
        }

        return [Entities::unescape(\substr($text, $at, $end - $at)), $end];
    }

    /** Where a destination without angle brackets ends: balanced parentheses, no spaces or controls. */
    private static function bareDestinationEnd(string $text, int $at): ?int
    {
        $depth = 0;
        $i = $at;
        $length = \strlen($text);
        while ($i < $length) {
            $c = $text[$i];
            if ('\\' === $c && ($i + 1) < $length && \str_contains(Entities::ESCAPABLE, $text[$i + 1])) {
                $i += 2;
                continue;
            }
            $depth += '(' === $c ? 1 : 0;
            if ($depth > self::MAX_NESTING) {
                return null;
            }
            if (')' === $c) {
                if (0 === $depth) {
                    break;
                }
                --$depth;
            }
            if (\ord($c[0]) <= 0x20 || "\x7f" === $c) {
                break;
            }
            ++$i;
        }

        return 0 === $depth ? $i : null;
    }

    /**
     * A link title (`"…"`, `'…'` or `(…)`) at $at: the unescaped title and the offset after it.
     *
     * @return array{string, int}|null
     */
    public static function title(string $text, int $at): ?array
    {
        $m = [];
        if (1 !== \preg_match('/\G(?:"((?:\\\\[!-\/:-@\[-`{-~]|[^"\x00])*)"|\'((?:\\\\[!-\/:-@\[-`{-~]|[^\'\x00])*)\'|\(((?:\\\\[!-\/:-@\[-`{-~]|[^()\x00])*)\))/', $text, $m, flags: 0, offset: $at)) {
            return null;
        }

        return [Entities::unescape(($m[1] ?? '').($m[2] ?? '').($m[3] ?? '')), $at + \strlen($m[0])];
    }

    /** A label as references are matched: case-folded, whitespace collapsed. */
    public static function normalize(string $label): string
    {
        $collapsed = \preg_replace('/[ \t\n\r\f\v]+/', replacement: ' ', subject: \trim($label, characters: " \t\n\r\f\v")) ?? $label;

        return \mb_convert_case($collapsed, \MB_CASE_FOLD, encoding: 'UTF-8');
    }

    /** The offset after spaces, tabs and at most one line ending from $at. */
    public static function skipSpace(string $text, int $at): int
    {
        $m = [];
        \preg_match('/\G[ \t]*(?:\r?\n[ \t]*)?/', $text, $m, flags: 0, offset: $at);

        return $at + \strlen($m[0] ?? '');
    }
}
