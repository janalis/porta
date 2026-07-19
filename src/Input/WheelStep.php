<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * How far one wheel notch scrolls. Three rows, like every editor's default —
 * spelled out here rather than as a literal in each of the three routers, so
 * the panes cannot drift apart on it.
 *
 * The two panes that deviate say so at the point of deviation, through
 * $factor: the terminal's scrollback runs the other way, and the image
 * viewer's rows are half-height.
 *
 * A horizontal notch moves {@see COLS} columns, the same constant in the three
 * panes that scroll sideways (editor, image, diff) so they cannot drift apart
 * either. Wider than a vertical notch: a column is narrower than a row, and a
 * horizontal swipe is the coarser gesture.
 */
final class WheelStep
{
    /** Rows per wheel notch. */
    public const int ROWS = 3;

    /** Columns per horizontal wheel notch. */
    public const int COLS = 6;

    /**
     * Signed rows for this notch (negative = up). $factor scales the distance;
     * a negative one inverts the direction.
     */
    public static function rows(MouseEvent $mouse, int $factor = 1): int
    {
        return $factor * self::ROWS * $mouse->wheelDelta;
    }

    /**
     * Signed columns for this horizontal notch (negative = left). $factor
     * scales the distance; a negative one inverts the direction.
     */
    public static function cols(MouseEvent $mouse, int $factor = 1): int
    {
        return $factor * self::COLS * $mouse->wheelHDelta;
    }
}
