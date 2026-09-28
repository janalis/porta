<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * The shared width vocabulary for every floating surface. Each popup family
 * used to carry its own literal caps (70 / 46 / 48 / "columns − 8"), which
 * made the surfaces drift apart one tweak at a time; deriving them from one
 * table keeps a future re-tune a single-file change.
 */
final class PopupMetrics
{
    /** Inner-width comfort cap for the centered pickers (list overlay, Search Everywhere). */
    public const int CENTERED_MAX_INNER = 70;

    /**
     * Columns a centered surface always leaves to the pane edges, so a modal
     * never reads as a full-width sheet even on narrow terminals.
     */
    public const int CENTERED_MARGIN = 8;

    /** Inner-width cap for the caret-anchored completion popup. */
    public const int COMPLETION_MAX_INNER = 46;

    /**
     * Rows the completion popup shows at once. Here rather than private to the
     * renderer because {@see \Porta\Editor\EditorCompletion} resolves exactly
     * what is on screen — two places computing the same window separately is
     * how one of them ends up resolving a row nobody can see.
     */
    public const int COMPLETION_ROWS = 8;

    /**
     * Inner width of the docblock panel beside the completion popup. Wider than
     * the list is tall is the wrong shape for prose, and a panel as narrow as
     * the list would wrap every sentence twice.
     */
    public const int COMPLETION_DOC_INNER = 44;

    /** Find in Files never squeezes below this inner width (content- or drag-sized). */
    public const int FIND_MIN_WIDTH = 48;

    /**
     * Inner-width comfort cap for Find in Files, above the pickers' because
     * its preview shows lines of code, not labels. A corner drag may go past it.
     */
    public const int FIND_MAX_INNER = 80;

    /** Preview rows Find in Files shows until a corner drag sets its own. */
    public const int FIND_PREVIEW_ROWS = 10;

    /** Rows a centered picker's list shows at most. */
    public const int PICKER_ROWS = 12;

    /** Inner-width cap for the corner toast cards. */
    public const int TOAST_MAX_INNER = 42;

    /**
     * Screen position of a box centered against the pane union: a third down
     * (the placement the pickers have always had), centered across. Every
     * centered surface — the pickers, Find in Files, the Welcome modal —
     * opens here until a drag gives it a place of its own.
     *
     * @param array{int, int, int, int} $bounds [top, left, bottom, right]
     *
     * @return array{int, int} [top, col]
     */
    public static function centered(array $bounds, int $boxRows, int $boxWidth): array
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;

        return [
            $boundTop + \max(0, \intdiv($boundBottom - $boundTop - $boxRows, num2: 3)),
            $boundLeft + \max(0, \intdiv($boundRight - $boundLeft - $boxWidth, num2: 2)),
        ];
    }
}
