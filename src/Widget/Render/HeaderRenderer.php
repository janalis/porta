<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * Renders the bold, full-width title bar shared by the overlay/panel widgets
 * (find, go-to-file, results, diff, terminal) and the editor's completion popup.
 * Centralizes the styled header-bar idiom that was copy-pasted across those
 * widgets, scrolling the bar to keep an embedded input caret marker visible
 * when the header holds a text field (a markerless header just clips). An
 * optional $suffix (the transient surfaces' right-aligned `Esc` dismiss hint)
 * renders faint at the bar's right edge, outside the scrolled span.
 */
final class HeaderRenderer
{
    /** The standard panel title bar — {@see Role::HeaderBar}, bold — that five panels styled by hand. */
    public static function title(string $text, int $width, Styles $styles, string $suffix = ''): string
    {
        return self::render($text, $width, $styles->of(Role::HeaderBar)->withBold(), $suffix);
    }

    public static function render(string $text, int $width, Style $style, string $suffix = ''): string
    {
        if ('' === $suffix) {
            return $style->apply(AnsiText::scrollLineToCaret($text, $width));
        }
        $room = max(0, $width - mb_strwidth($suffix) - 1);

        return $style->apply(AnsiText::padRight(AnsiText::scrollLineToCaret($text, $room), $room).Sgr::NORMAL_INTENSITY.Sgr::FAINT.$suffix.' ');
    }
}
