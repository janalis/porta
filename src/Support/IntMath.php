<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Small integer helpers, wrapping the `max($lo, min($hi, $v))` clamp idiom the
 * widgets and editor hand-rolled in dozens of places.
 */
final class IntMath
{
    /**
     * Constrain $value to the inclusive range [$lo, $hi] — and $lo wins when the range is
     * empty ($hi < $lo), as for `count - 1` on an empty list or a pane with no room. That
     * is the difference from PHP 8.6's `\clamp()`, which throws a ValueError there: a
     * caller whose bounds are always ordered calls `\clamp()`, every other caller this.
     */
    public static function clamp(int $value, int $lo, int $hi): int
    {
        return \max($lo, \min($hi, $value));
    }
}
