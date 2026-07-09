<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

/**
 * Renders the bold, full-width title bar shared by the overlay/panel widgets
 * (find, go-to-file, results, diff, terminal) and the editor's completion popup.
 * Centralizes the `<prefix>…clip…reset` header-bar idiom that was copy-pasted
 * across those widgets, scrolling the bar to keep an embedded input caret marker
 * visible when the header holds a text field (a markerless header just clips).
 */
final class HeaderRenderer
{
    /**
     * @param string $prefix the SGR that opens the bar — background/foreground
     *                        plus bold — built by the caller from its Palette
     */
    public static function render(string $text, int $width, string $prefix): string
    {
        return $prefix.AnsiText::scrollLineToCaret($text, $width, AnsiUtils::cursorMarker(CursorShape::Bar))."\x1b[0m";
    }
}
