<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\LeakLog;
use Porta\Tests\Support\LoopErrors;
use Porta\Tests\Support\LoopIsolationExtension;
use Porta\Tests\Support\TempProject;
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
     *
     * In its own process: the swap route only runs when the loop is not
     * running, and an earlier test in the same process can leave it running —
     * which sends reset() down the cancel route instead and makes this test
     * about test ORDER rather than about the swap.
     */
    #[RunInSeparateProcess]
    public function testTheDriverSwapKeepsTheErrorHandler(): void
    {
        $handler = LoopErrors::rethrow();
        EventLoop::setErrorHandler($handler);
        $before = EventLoop::getDriver();

        LoopIsolationExtension::reset(self::class.'::probe');

        $this->assertNotSame($before, EventLoop::getDriver(), 'the swap route ran — a loop that is not running gets a fresh driver');
        $this->assertSame($handler, EventLoop::getErrorHandler(), 'and the handler came with it');
    }

    /**
     * A test that left the handler null (or its own) must not hand that to
     * the next one: with none, Ide::run() installs the production handler and
     * a failing await() becomes a toast over an IDE that never stops — a
     * worker that hung 5.5h under coverage. The put-back runs before either
     * route, so this needs no process of its own; it covers both anyway.
     */
    public function testAHandlerATestLeftChangedIsPutBack(): void
    {
        $project = new TempProject([]);
        $previous = \getenv(LeakLog::PATH_ENV);
        \putenv(LeakLog::PATH_ENV.'='.$project->path('leaks.log'));
        try {
            EventLoop::setErrorHandler(null);
            LoopIsolationExtension::reset(self::class.'::nulled');
            $this->assertSame(LoopErrors::rethrow(), EventLoop::getErrorHandler(), 'a null handler is replaced by the rethrower');

            EventLoop::setErrorHandler(static function (): void {});
            $suspension = EventLoop::getSuspension();
            EventLoop::defer(static function () use ($suspension): void {
                LoopIsolationExtension::reset(LoopIsolationExtensionTest::class.'::running');
                $suspension->resume();
            });
            $suspension->suspend();
            $this->assertSame(LoopErrors::rethrow(), EventLoop::getErrorHandler(), 'and a test\'s own one too, while the loop runs');

            $this->assertStringContainsString('[handler-leak] '.self::class.'::nulled', (string) \file_get_contents($project->path('leaks.log')), 'who moved it is named');
        } finally {
            \putenv(\is_string($previous) ? LeakLog::PATH_ENV.'='.$previous : LeakLog::PATH_ENV);
            EventLoop::setErrorHandler(LoopErrors::rethrow());
        }
    }
}
