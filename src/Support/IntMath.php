<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Small integer helpers, wrapping the `max($lo, min($hi, $v))` clamp idiom the
 * widgets and editor hand-rolled in dozens of places.
 */
final class IntMath
{
    /** Constrain $value to the inclusive range [$lo, $hi]. */
    public static function clamp(int $value, int $lo, int $hi): int
    {
        return \max($lo, \min($hi, $value));
    }
}
