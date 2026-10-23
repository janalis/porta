<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * How long a test may wait for something to HAPPEN, in real seconds.
 *
 * pcov makes everything 2-3x slower, so under coverage — `composer
 * coverage:check`, which is the gate — a budget gets an explicit ×3 (the
 * sniff tests/bootstrap.php uses). {@see PumpsLoop::await()} spends its
 * budget through here, and so does a blocking wait that cannot pump the loop
 * (a fixture server's startup). A NEGATIVE window — "does not happen within
 * 1.5s" — must not stretch, and never comes through here.
 */
final class Budget
{
    public static function seconds(float $seconds): float
    {
        return $seconds * ('1' === \ini_get('pcov.enabled') ? 3.0 : 1.0);
    }
}
