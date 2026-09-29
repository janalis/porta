<?php

declare(strict_types=1);

namespace Porta\Install;

/** Where the global git config stands with Porta as its difftool and mergetool ({@see GitToolSetup::status()}). */
enum GitToolStatus
{
    /** No git binary to ask. */
    case Unavailable;

    /** None of Porta's keys are set. */
    case Absent;

    /** Every key is set, to this binary. */
    case Current;

    /** Some keys are set, or all of them to another binary — a checkout moved, a phar replaced by another. */
    case Stale;
}
