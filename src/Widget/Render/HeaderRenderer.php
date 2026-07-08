<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Renders the bold, full-width title bar shared by the overlay/panel widgets
 * (find, go-to-file, results, diff, terminal) and the editor's completion popup.
 * Centralizes the `<prefix>…truncateToWidth…reset` header-bar idiom that was
 * copy-pasted across those widgets.
 */
final class HeaderRenderer
{
    /**
     * @param string $prefix the SGR that opens the bar — background/foreground
     *                        plus bold — built by the caller from its Palette
     */
    public static function render(string $text, int $width, string $prefix): string
    {
        return $prefix.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
    }
}
