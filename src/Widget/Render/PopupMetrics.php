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

    /** Find in Files never squeezes below this inner width (content- or drag-sized). */
    public const int FIND_MIN_WIDTH = 48;

    /** Inner-width cap for the corner toast cards. */
    public const int TOAST_MAX_INNER = 42;
}
