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
 * leak-clean — and then isolates, by one of two routes.
 *
 * The cheap route is a fresh driver: a swap also resets the suspension WeakMap,
 * curing pending-state corruption that leaves zero identifiers behind. But
 * `setDriver()` refuses while the driver considers itself running, and that is
 * NOT rare — a full E2e suite hits it for hundreds of tests, because a Tui that
 * parked a suspension leaves the driver running after `run()` returns. This
 * class used to skip isolation entirely there, silently, which is how a serial
 * suite accumulated watchers across tests. The fallback cancels each leaked
 * identifier by hand: same outcome for the next test, without the WeakMap
 * reset.
 *
 * **The report is invisible under `composer test`**: paratest swallows worker
 * STDERR (measured — an unconditional write from here never reaches the log).
 * Use `composer test:serial` to read it.
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

            return;
        }
        // The swap is impossible while the driver still considers itself
        // running — which is NOT the rare case this class used to assume: a
        // full E2e suite hits it for hundreds of tests, because a Tui that
        // parked a suspension leaves the driver running after run() returns.
        // Isolation cannot be skipped there just because the cheap route is
        // closed, so cancel what leaked instead. Same outcome, one watcher at
        // a time.
        foreach ($leaked as $identifier) {
            EventLoop::cancel($identifier);
        }
    }
}
