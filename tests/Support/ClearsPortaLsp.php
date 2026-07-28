<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Clears the PORTA_LSP env channel around every test. A test that points it at
 * a mock server script would otherwise leak the value into the next method in
 * the same paratest worker (putenv is process-global), silently booting later
 * Ides against a server they never asked for. Cleared before AND after: before
 * protects this test from a prior leak, after protects whoever runs next.
 * Composes as attribute hooks, so a class keeps its own setUp/tearDown —
 * {@see PreservesPath} is the same pattern for PATH.
 */
trait ClearsPortaLsp
{
    #[After]
    #[Before]
    protected function clearPortaLsp(): void
    {
        putenv('PORTA_LSP');
    }
}
