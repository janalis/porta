<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\I18n\BaseDirection;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Support\AnsiText;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The one implementation of a modal's drop shadow: a 1-cell dark band along
 * the box's right edge and bottom row, offset +1/+1 (the classic
 * Turbo-Vision cast). The cells are solid `.modal::shadow`-styled spaces —
 * they hide what sits beneath, which is the depth cue that survives the
 * monochrome theme (where the rule is plain but the overwrite remains).
 *
 * Deliberately NOT baked into the frame's own rows: the title bar casts no
 * shadow beside it (the +1 row offset), and a panel row cannot carry a
 * transparent cell — the compositor invariant forbids it. Two delivery
 * paths for the two compositing worlds: {@see blit()} for modals composited
 * inside one widget's lines (the pickers, Find in Files) and {@see spans()}
 * for the Welcome modal's per-pane span push.
 */
final class ModalShadow
{
    /**
     * Composite the shadow bands for a box at ($startRow, $col, $width, $rows)
     * directly into $lines. Rows beyond the pane are skipped — the same
     * bounds guard the {@see OverlayCompositor::into} splice loops apply.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function blit(array $lines, int $startRow, int $col, int $width, int $rows, int $columns, Styles $styles): array
    {
        $cell = $styles->rule('.modal::shadow')->apply(' ');

        for ($row = $startRow + 1; $row < ($startRow + $rows); ++$row) {
            if ($row >= \count($lines) || ($col + $width) >= $columns) {
                continue;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $col + $width, 1, $columns, $cell);
        }
        $bottom = $startRow + $rows;
        if ($bottom < \count($lines)) {
            $bandWidth = \min($width, $columns - $col - 1);
            if ($bandWidth > 0) {
                $band = $styles->rule('.modal::shadow')->apply(\str_repeat(' ', $bandWidth));
                $lines[$bottom] = OverlayCompositor::into($lines[$bottom], $col + 1, $bandWidth, $columns, $band);
            }
        }

        return $lines;
    }

    /**
     * The same bands as widget-local overlay spans for one pane, for the
     * span-composited Welcome modal — {@see SpanSlicer} clips each band to
     * the pane exactly as it clips the panel itself.
     *
     * $shown is the direction the surface reaches the screen in. A mirrored
     * surface is flipped across the row after composing, so its shadow is
     * cast to the LEFT here and lands bottom-right on screen, where every
     * other shadow falls; cast to the right, it came out bottom-left.
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function spans(WidgetRect $rect, int $top, int $col, int $width, int $rows, Styles $styles, BaseDirection $shown = BaseDirection::Ltr): array
    {
        $cell = $styles->rule('.modal::shadow')->apply(' ');
        $band = $styles->rule('.modal::shadow')->apply(AnsiText::blank($width));
        $mirrored = BaseDirection::Rtl === $shown;

        return [
            ...SpanSlicer::localSpans(\array_fill(0, \max(0, $rows - 1), $cell), $rect, $top + 1, $mirrored ? $col - 1 : $col + $width, 1),
            ...SpanSlicer::localSpans([$band], $rect, $top + $rows, $mirrored ? $col - 1 : $col + 1, $width),
        ];
    }
}
