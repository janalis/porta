<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Symfony YAML decodes each UTF-16 escape separately, producing WTF-8 pairs. */
final class MermaidUtf16
{
    public static function pairs(string $text): string
    {
        return \preg_replace_callback(
            '/\xED[\xA0-\xAF][\x80-\xBF]\xED[\xB0-\xBF][\x80-\xBF]/',
            static function (array $match): string {
                $bytes = $match[0];
                $high = ((\ord($bytes[1]) & 63) << 6) | (\ord($bytes[2]) & 63);
                $low = ((\ord($bytes[4]) & 15) << 6) | (\ord($bytes[5]) & 63);

                return \mb_chr(0x1_0000 + (($high - 0x800) << 10) + $low, encoding: 'UTF-8');
            },
            $text,
        ) ?? $text;
    }

    /** YAML double quotes can retain lone JavaScript code units without key collisions. */
    public static function quote(string $text): string
    {
        /** @var list<string> $pieces The static byte pattern is valid and retains its matched delimiters. */
        $pieces = \preg_split('/(\xED[\xA0-\xBF][\x80-\xBF])/', self::pairs($text), flags: \PREG_SPLIT_DELIM_CAPTURE);
        $quoted = '"';
        foreach ($pieces as $index => $piece) {
            if (1 === ($index % 2)) {
                $unit = 0xD000 | ((\ord($piece[1]) & 63) << 6) | (\ord($piece[2]) & 63);
                $quoted .= \sprintf('\\u%04x', $unit);
                continue;
            }
            $quoted .= \substr(\json_encode($piece, flags: \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), offset: 1, length: -1);
        }

        return $quoted.'"';
    }

    public static function display(string $text): string
    {
        return \preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/', replacement: "\u{FFFD}", subject: self::pairs($text)) ?? $text;
    }
}
