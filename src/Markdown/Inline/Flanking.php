<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * Whether a delimiter run can open or close emphasis, from the characters on
 * either side of it: the spec's left- and right-flanking rules, with `_`
 * stricter inside words.
 */
final class Flanking
{
    /** @return array{bool, bool} can open, can close */
    public static function of(string $text, int $start, int $end, string $char): array
    {
        $before = self::charBefore($text, $start);
        $after = self::charAt($text, $end);
        $afterSpace = self::isWhitespace($after);
        $afterPunct = self::isPunctuation($after);
        $beforeSpace = self::isWhitespace($before);
        $beforePunct = self::isPunctuation($before);
        $left = !$afterSpace && (!$afterPunct || $beforeSpace || $beforePunct);
        $right = !$beforeSpace && (!$beforePunct || $afterSpace || $afterPunct);
        if ('_' === $char) {
            return [$left && (!$right || $beforePunct), $right && (!$left || $afterPunct)];
        }

        return [$left, $right];
    }

    /** The whole UTF-8 character ending just before $at, or a line ending at the start. */
    private static function charBefore(string $text, int $at): string
    {
        if (0 === $at) {
            return "\n";
        }
        $start = $at - 1;
        while ($start > 0 && 0x80 === (\ord($text[$start]) & 0xC0)) {
            --$start; // a continuation byte: the character began earlier
        }

        return \substr($text, $start, $at - $start);
    }

    /** The whole UTF-8 character starting at $at, or a line ending at the end. */
    private static function charAt(string $text, int $at): string
    {
        if ($at >= \strlen($text)) {
            return "\n";
        }
        $lead = \ord($text[$at]);
        $length = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };

        return \substr($text, $at, $length);
    }

    public static function isWhitespace(string $char): bool
    {
        return 1 === \preg_match('/^[\t\n\f\r\p{Zs}]$/u', $char);
    }

    public static function isPunctuation(string $char): bool
    {
        return 1 === \preg_match('/^[\p{P}\p{S}]$/u', $char);
    }
}
