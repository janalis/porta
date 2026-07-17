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
 */
final class WheelStep
{
    /** Rows per wheel notch. */
    public const int ROWS = 3;

    /**
     * Signed rows for this notch (negative = up). $factor scales the distance;
     * a negative one inverts the direction.
     */
    public static function rows(MouseEvent $mouse, int $factor = 1): int
    {
        return $factor * self::ROWS * $mouse->wheelDelta;
    }
}
