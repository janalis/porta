<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Text from someone else, made safe to draw: no control character that a
 * terminal would act on (ESC opens an escape sequence, and OSC 52 writes the
 * clipboard), no bidi override that would draw it in another order than it
 * reads. A line keeps nothing of either; a page keeps its line breaks and tabs.
 */
final class ControlChars
{
    /** The bidi embeddings, overrides and isolates: they reorder what follows them. */
    private const string BIDI = '\x{202A}-\x{202E}\x{2066}-\x{2069}';

    /** $text with no control character at all, on one line, trimmed. */
    public static function line(string $text): string
    {
        return \trim((string) \preg_replace('/[\p{Cc}'.self::BIDI.']+/u', replacement: ' ', subject: self::utf8($text)));
    }

    /** $text with every control character but the line feed and the tab dropped; CRLF reads as LF. */
    public static function page(string $text): string
    {
        $text = \str_replace("\r\n", replace: "\n", subject: self::utf8($text));

        return (string) \preg_replace('/[^\P{Cc}\n\t]|['.self::BIDI.']/u', replacement: '', subject: $text);
    }

    /** Invalid UTF-8 would make the patterns above fail outright: each bad byte becomes `?`. */
    private static function utf8(string $text): string
    {
        return \mb_scrub($text, encoding: 'UTF-8');
    }
}
