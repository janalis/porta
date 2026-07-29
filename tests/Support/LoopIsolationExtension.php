<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

/**
 * Per-test event-loop isolation. Revolt's driver is process-global per
 * paratest worker, so one test's leaked watcher on a since-closed stream
 * poisons the loop for EVERY later test in that worker: stream_select throws
 * ("No stream arrays were passed"), the exception escapes suspend() leaving
 * the shared {main} suspension pending forever, and the rest of the worker
 * cascades "Must call resume() or throw() before calling suspend() again".
 *
 * After every test (Finished, plus the two Preparation* ends a setUp-abort
 * takes instead) this reports any leaked identifiers to STDERR as
 * `[loop-leak]` — visibility only, never a failure, until the suite runs
 * leak-clean — and ALWAYS swaps in a fresh driver: a swap also resets the
 * suspension WeakMap, curing pending-state corruption that leaves zero
 * identifiers behind. Swapping is safe here (the only setDriver guard is
 * isRunning(), false between tests) and cheap (one StreamSelectDriver).
 */
final class LoopIsolationExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                LoopIsolationExtension::reset($event->test()->id());
            }
        });
        $facade->registerSubscriber(new class implements PreparationFailedSubscriber {
            public function notify(PreparationFailed $event): void
            {
                LoopIsolationExtension::reset($event->test()->id());
            }
        });
        $facade->registerSubscriber(new class implements PreparationErroredSubscriber {
            public function notify(PreparationErrored $event): void
            {
                LoopIsolationExtension::reset($event->test()->id());
            }
        });
    }

    /** Report leaks, then hand the next test a pristine loop. Idempotent. */
    public static function reset(string $testId): void
    {
        $leaked = EventLoop::getIdentifiers();
        if ([] !== $leaked) {
            fwrite(STDERR, \sprintf("[loop-leak] %s: %d identifier(s) leaked\n", $testId, \count($leaked)));
        }
        if (!EventLoop::getDriver()->isRunning()) {
            EventLoop::setDriver(new StreamSelectDriver());
        }
    }
}
