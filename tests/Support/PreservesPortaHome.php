<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Restores the process-global PORTA_HOME after every test from the pristine
 * snapshot taken in bootstrap.php ({@see PORTA_TEST_PRISTINE_PORTA_HOME}).
 *
 * The sibling of {@see PreservesPath}, for the same reason and with the same
 * shape: putenv is process-global and paratest runs a worker's methods
 * sequentially, so a test that points PORTA_HOME at a temp dir leaks that dir
 * into every later method in the worker — and by then the dir is usually gone,
 * because TempProject removes it in its destructor.
 *
 * Restoring to the bootstrap snapshot rather than a setUp-time getenv() is what
 * makes it immune to ordering: a setUp read inherits any corruption already
 * leaked into the worker. Composes as an #[After] hook, so a class keeps its
 * own tearDown.
 */
trait PreservesPortaHome
{
    #[After]
    protected function restorePristinePortaHome(): void
    {
        $home = (string) \PORTA_TEST_PRISTINE_PORTA_HOME;
        \putenv('' !== $home ? 'PORTA_HOME='.$home : 'PORTA_HOME');
    }
}
