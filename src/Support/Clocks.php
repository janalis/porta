<?php

declare(strict_types=1);

namespace Porta\Support;

use Psr\Clock\ClockInterface;

/**
 * The one conversion the timing consumers (double-click, zoom gesture, undo
 * coalescing, toast expiry) share: a clock instant as float seconds with
 * sub-second precision. DateTimeInterface has no float accessor, so every
 * consumer used to hand-roll this cast beside its own clock.
 */
final class Clocks
{
    public static function seconds(ClockInterface $clock): float
    {
        /** @var numeric-string $seconds 'U.u' always yields digits.digits */
        $seconds = $clock->now()->format('U.u');

        return (float) $seconds;
    }
}
