<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Symfony\Component\Tui\Style\Style;

/**
 * Renders the bold, full-width title bar shared by the overlay/panel widgets
 * (find, go-to-file, results, diff, terminal) and the editor's completion popup.
 * Centralizes the styled header-bar idiom that was copy-pasted across those
 * widgets, scrolling the bar to keep an embedded input caret marker visible
 * when the header holds a text field (a markerless header just clips).
 */
final class HeaderRenderer
{
    public static function render(string $text, int $width, Style $style): string
    {
        return $style->apply(AnsiText::scrollLineToCaret($text, $width));
    }
}
