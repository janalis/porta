<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Renders the bold, full-width title row of the docked panels (the file tree,
 * the git lists and commit box, the review panes).
 * Centralizes the styled header-bar idiom that was copy-pasted across those
 * widgets, scrolling the bar to keep an embedded input caret marker visible
 * when the header holds a text field (a markerless header just clips). An
 * optional $suffix (the transient surfaces' right-aligned `Esc` dismiss hint)
 * renders faint at the bar's right edge, outside the scrolled span.
 */
final class HeaderRenderer
{
    /**
     * The standard panel title — {@see Role::Emphasis}, bold, on the pane
     * itself: a shade there would read as a bar, and the bars are the menu's
     * and the status line's — with the one column of inset every panel's
     * title sits at, added here rather than by each caller (or, worse, inside
     * a translated string a translator can drop).
     */
    public static function title(string $text, int $width, Styles $styles, string $suffix = ''): string
    {
        return self::render(' '.$text, $width, $styles->of(Role::Emphasis)->withBold(), $suffix);
    }

    public static function render(string $text, int $width, Style $style, string $suffix = ''): string
    {
        $room = \max(0, $width - AnsiUtils::visibleWidth($suffix) - 1);

        return match (true) {
            '' === $suffix => $style->apply(AnsiText::scrollLineToCaret($text, $width)),
            // The pane cannot seat the suffix and its gap, so the SUFFIX is
            // what has to shrink — there is no text left to take it out of.
            // `max(0, …)` above bounds the room and not the output, which is a
            // clamp with no floor: it kept the suffix at full width and the row
            // came out `mb_strwidth($suffix) + 1` columns wide however narrow
            // the pane was. A row wider than its pane does not garble a line,
            // it throws RenderException out of the render loop and takes the
            // session with it.
            0 === $room => $style->apply(AnsiText::truncate($suffix.' ', $width)),
            default => $style->apply(AnsiText::padRight(AnsiText::scrollLineToCaret($text, $room), $room).Sgr::NORMAL_INTENSITY.Sgr::FAINT.$suffix.' '),
        };
    }
}
