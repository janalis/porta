<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Restores `SHELL` after every test, for the suites that pin it to `/bin/sh` so
 * a terminal session spawns something predictable.
 *
 * The snapshot is taken in a Before hook rather than from a bootstrap constant
 * ({@see PreservesPath}'s shape), and that is only sound because
 * {@see EnvIsolationExtension} hands every test the worker's boot environment:
 * the value read here cannot be a previous test's leftover.
 *
 * Without this the net still restores SHELL — but it reports the drift every
 * time, and a report that fires five times a run for something already handled
 * is how a leak channel stops being read.
 */
trait PreservesShell
{
    private ?string $pristineShell = null;

    #[Before]
    protected function rememberShell(): void
    {
        $shell = \getenv('SHELL');
        $this->pristineShell = false === $shell ? null : $shell;
    }

    #[After]
    protected function restoreShell(): void
    {
        \putenv(null === $this->pristineShell ? 'SHELL' : 'SHELL='.$this->pristineShell);
    }
}
