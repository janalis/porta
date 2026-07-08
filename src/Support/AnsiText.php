<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Small helpers for ANSI-styled text measured in display columns, wrapping the
 * `str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($s)))` idiom the
 * renderers hand-rolled in several places.
 */
final class AnsiText
{
    /**
     * Right-pad $text with spaces so its visible width reaches $width (a no-op
     * when it is already at least that wide). SGR codes are not counted.
     */
    public static function padRight(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($text)));
    }
}
