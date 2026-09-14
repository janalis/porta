<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Restores the process-global PATH after every test from the pristine snapshot
 * taken in bootstrap.php ({@see PORTA_TEST_PRISTINE_PATH}).
 *
 * A test that does putenv('PATH=/nonexistent') — to stop an Ide boot from
 * spawning git/LSP subprocesses — would otherwise leak that broken PATH into the
 * next method in the same paratest worker (putenv is process-global, methods run
 * sequentially per worker), and a later git-dependent test would resolve no git
 * binary and flash "Git not available". Restoring here, not to a setUp-time
 * getenv('PATH') (which inherits any prior corruption), makes the reset immune to
 * ordering. Composes as an #[After] hook, so a class keeps its own tearDown.
 */
trait PreservesPath
{
    #[After]
    protected function restorePristinePath(): void
    {
        \putenv('PATH='.(string) \PORTA_TEST_PRISTINE_PATH);
    }
}
