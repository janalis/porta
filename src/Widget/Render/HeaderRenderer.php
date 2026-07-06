<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Renders the bold, full-width title bar shared by the overlay/panel widgets
 * (find, go-to-file, results, diff, terminal) and the editor's completion popup.
 * Centralizes the `\x1b[48;5;<bg>m\x1b[1m…truncateToWidth…\x1b[0m` idiom that was
 * copy-pasted across those widgets.
 */
final class HeaderRenderer
{
    /**
     * @param int      $bg 256-color background index (default matches the panels)
     * @param int|null $fg optional 256-color foreground index
     */
    public static function render(string $text, int $width, int $bg = 237, ?int $fg = null): string
    {
        $prefix = "\x1b[48;5;{$bg}m".(null !== $fg ? "\x1b[38;5;{$fg}m" : '')."\x1b[1m";

        return $prefix.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
    }
}
