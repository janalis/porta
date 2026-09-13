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

    /**
     * The same instant as whole microseconds — exact, where seconds are not.
     *
     * A float second carries the epoch in its mantissa, so the difference of two
     * of them lands a hair either side of the value asked for: a duration cannot
     * be compared to a threshold it is meant to sit exactly on, which is a
     * boundary no test can then pin. `Uu` is a digit string of microseconds and
     * an integer holds it outright, so `elapsed <= budget` means what it says.
     */
    public static function microseconds(ClockInterface $clock): int
    {
        /** @var numeric-string $micros 'Uu' always yields digits */
        $micros = $clock->now()->format('Uu');

        return (int) $micros;
    }
}
