<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * GFM's extended autolinks: `www.` addresses, `http(s)://` URLs and bare
 * email addresses recognised in running text, without angle brackets. The
 * trailing punctuation a sentence puts after a link is not part of it.
 */
final class ExtendedAutolinks
{
    /** What may come right before an extended autolink. */
    public const string BOUNDARY = " \t\n*_~(";

    private const string DOMAIN = '[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*';

    /**
     * The autolink starting at $at: its text, its destination and its length.
     *
     * @return array{string, string, int}|null
     */
    public static function at(string $text, int $at): ?array
    {
        $m = [];
        if (1 === \preg_match('/\G(?:((?:https?|ftp):\/\/)|(www\.))('.self::DOMAIN.')[^\s<]*/i', $text, $m, flags: 0, offset: $at)) {
            $link = self::trimTrailing($m[0]);
            $domain = self::trimTrailing($m[3]);
            if (!self::validDomain('' !== $m[2] ? 'www.'.$domain : $domain)) {
                return null;
            }

            return [$link, '' !== $m[1] ? $link : 'http://'.$link, \strlen($link)];
        }

        return self::email($text, $at);
    }

    /** @return array{string, string, int}|null */
    private static function email(string $text, int $at): ?array
    {
        $m = [];
        if (1 !== \preg_match('/\G[a-zA-Z0-9.+_-]+@[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)+/', $text, $m, flags: 0, offset: $at)) {
            return null;
        }
        $address = \rtrim($m[0], characters: '.');
        $last = $address[\strlen($address) - 1];
        if ('-' === $last || '_' === $last || !\str_contains(\substr($address, (int) \strpos($address, needle: '@')), needle: '.')) {
            return null;
        }

        return [$address, 'mailto:'.$address, \strlen($address)];
    }

    /** At least one period, and no underscore in the last two segments. */
    private static function validDomain(string $domain): bool
    {
        $segments = \explode('.', $domain);
        if (\count($segments) < 2) {
            return false;
        }

        return !\str_contains(\implode('.', \array_slice($segments, -2)), '_');
    }

    /** Drop trailing punctuation, unbalanced closing parentheses, and an entity-like `&name;` tail. */
    private static function trimTrailing(string $link): string
    {
        do {
            $before = $link;
            $link = \rtrim($link, characters: '?!.,:*_~');
            $m = [];
            if (\str_ends_with($link, ';') && 1 === \preg_match('/&[a-zA-Z0-9]+;$/', $link, $m)) {
                $link = \substr($link, offset: 0, length: -\strlen($m[0]));
            }
            if (\str_ends_with($link, ')') && \substr_count($link, needle: ')') > \substr_count($link, needle: '(')) {
                $link = \substr($link, offset: 0, length: -1);
            }
        } while ($link !== $before);

        return $link;
    }
}
