<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * A readable two-state toggle for test helpers, replacing boolean flag
 * parameters (which mago's no-boolean-flag-parameter rule rejects). Passed as a
 * named argument at call sites, e.g. `blame(withRepo: Presence::Without)`.
 */
enum Presence
{
    case With;
    case Without;
}
