<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * Backslash escapes and HTML entity references, the two ways markdown writes
 * a character it would otherwise read as syntax.
 */
final class Entities
{
    public const string ESCAPABLE = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';
    public const string ENTITY = '/^&(?:#[xX][0-9a-fA-F]{1,6}|#\d{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/';

    /**
     * The character an entity reference at the start of $text stands for, and its length.
     *
     * @return array{string, int}|null null when there is none
     */
    public static function at(string $text): ?array
    {
        $m = [];
        if (1 !== \preg_match(self::ENTITY, $text, $m)) {
            return null;
        }
        $decoded = self::decode($m[0]);

        return null === $decoded ? null : [$decoded, \strlen($m[0])];
    }

    /** Resolve backslash escapes and entity references, as link destinations, titles and info strings need. */
    public static function unescape(string $text): string
    {
        $result = \preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])|&(?:#[xX][0-9a-fA-F]{1,6}|#\d{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $m): string {
                if ('' !== ($m[1] ?? '')) {
                    return $m[1];
                }

                return self::decode($m[0]) ?? $m[0];
            },
            $text,
        );

        return $result ?? $text;
    }

    /** One complete entity reference decoded, or null for a name HTML5 does not define. */
    private static function decode(string $entity): ?string
    {
        if ('#' === $entity[1]) {
            $isHex = 'x' === $entity[2] || 'X' === $entity[2];
            $code = $isHex ? (int) \hexdec(\substr($entity, offset: 3, length: -1)) : (int) \substr($entity, offset: 2, length: -1);

            $valid = $code > 0 && $code <= 0x10_FFFF && ($code < 0xD800 || $code > 0xDFFF);

            return (string) \mb_chr($valid ? $code : 0xFFFD, encoding: 'UTF-8');
        }
        $decoded = \html_entity_decode($entity, \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8');

        return $decoded === $entity ? null : $decoded;
    }
}
