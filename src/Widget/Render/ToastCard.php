<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Overlay\State\Toast;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * One corner notification card in the modal design language: the rounded
 * frame in the toast's severity role (error red, warning amber, info blue,
 * success green — the diagnostics palette), the severity glyph on the top
 * border, the tab strips' × affordance, and the message WRAPPED across rows
 * rather than truncated — a clipped error message is no message, and the E2e
 * suite greps full flash texts off the terminal.
 *
 * Every returned row is exactly $innerWidth + {@see PopupFrame::CHROME_COLS}
 * visible columns — the compositor invariant.
 */
final class ToastCard
{
    /** Wrap cap: a message longer than this many rows ellipsizes on the last. */
    public const int MAX_MESSAGE_ROWS = 6;

    /**
     * @return list<string> the framed card rows
     */
    public static function render(Toast $toast, int $innerWidth, Styles $styles, bool $closeHovered = false): array
    {
        $accent = $styles->of($toast->severity->role());
        $close = $closeHovered ? TabCloseGlyph::active($styles) : TabCloseGlyph::inactive($styles);

        // `╭ ✗ ──── ×╮`: glyph chrome is 4 columns (corner, spaces, glyph),
        // close chrome 3 (space, ×, corner) — the × lands at width − 2, the
        // exact cell ModalBox::closeHit answers for.
        $fill = max(0, $innerWidth - 5);
        $top =
            $accent->apply(Glyphs::MODAL_TOP_LEFT.' ')
            .$accent->withBold()->apply($toast->severity->glyph())
            .' '
            .$accent->apply(str_repeat(Glyphs::FRAME_HORIZONTAL, $fill))
            .' '
            .$close
            .$accent->apply(Glyphs::MODAL_TOP_RIGHT);

        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        $rows = [AnsiText::truncate($top, max(0, $innerWidth + PopupFrame::CHROME_COLS), pad: true)];
        $lines = AnsiText::wrap($toast->message, max(1, $innerWidth - 2));
        if (\count($lines) > self::MAX_MESSAGE_ROWS) {
            $lines = \array_slice($lines, 0, self::MAX_MESSAGE_ROWS);
            $lines[self::MAX_MESSAGE_ROWS - 1] = AnsiText::truncate($lines[self::MAX_MESSAGE_ROWS - 1].'…', max(1, $innerWidth - 2));
        }
        foreach ($lines as $line) {
            $body = $styles->of(Role::Bright)->apply(AnsiText::truncate(' '.$line, max(0, $innerWidth), pad: true));
            $rows[] = $rail.$body.$rail;
        }
        $rows[] = $accent->apply(Glyphs::MODAL_BOTTOM_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $innerWidth)).Glyphs::MODAL_BOTTOM_RIGHT);

        return $rows;
    }
}
