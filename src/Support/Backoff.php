<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * How long to wait before trying a link again: soon at first, then less and
 * less often, never past half a minute — what every reconnect in the IDE
 * waits by, so a server that is down is asked about at the same unhurried
 * pace whatever is asking.
 */
final class Backoff
{
    /** @var non-empty-list<float> seconds before each attempt; the last repeats */
    private const array DELAYS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];

    /** Seconds to wait before attempt $tries + 1, after $tries that failed. */
    public static function after(int $tries): float
    {
        return self::DELAYS[\max(0, \min($tries, \count(self::DELAYS) - 1))];
    }
}
