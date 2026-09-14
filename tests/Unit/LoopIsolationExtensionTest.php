<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\LoopErrors;
use Porta\Tests\Support\LoopIsolationExtension;
use Revolt\EventLoop;

final class LoopIsolationExtensionTest extends TestCase
{
    /**
     * The driver swap between tests must not drop the suite's error handler.
     *
     * It did: the handler is stored on the driver, the swap installs a fresh
     * one, and the next E2e test found no handler — so Ide::run() installed
     * its production handler and a throwing step became a toast in a passing
     * test. Measured before the fix with a two-test probe (a loop-free test,
     * then a step that throws): the second reported OK.
     */
    public function testTheDriverSwapKeepsTheErrorHandler(): void
    {
        $handler = LoopErrors::rethrow();
        EventLoop::setErrorHandler($handler);
        $before = EventLoop::getDriver();

        LoopIsolationExtension::reset(self::class.'::probe');

        $this->assertNotSame($before, EventLoop::getDriver(), 'the swap route ran — a loop that is not running gets a fresh driver');
        $this->assertSame($handler, EventLoop::getErrorHandler(), 'and the handler came with it');
    }
}
