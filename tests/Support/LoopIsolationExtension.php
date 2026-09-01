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
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\TimerCallback;

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
 * **The STDERR report is invisible under `composer test`**: paratest swallows
 * worker STDERR (measured — an unconditional write from here never reaches the
 * log). It therefore goes through {@see LeakLog}, which also appends to a file
 * that survives the parallel run; `composer test:serial` remains the way to
 * read it inline, in a reproducible order.
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
            LeakLog::append(\sprintf("[loop-leak] %s: %d identifier(s) leaked%s\n", $testId, \count($leaked), self::describe($leaked)));
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

    /**
     * Name what leaked: the kind of watcher and the line that armed it.
     *
     * A bare count says a test walked out early and nothing else — every leak
     * in the log looked alike, so nobody could tell the benign ones from the
     * dangerous one. They are not alike. A full serial E2e run leaks 55
     * identifiers from seven sites, and 37 are {@see \Porta\Support\ProcessReaper}'s
     * 50 ms poll, which cancels itself the moment the child is gone and is
     * unreferenced besides. The one that matters is an `onReadable` on a
     * subprocess pipe, which is the exact shape this class exists to contain.
     *
     * Best-effort, and deliberately so: it reflects into Revolt's `@internal`
     * driver callbacks (v1.0.9), so any upgrade that moves them degrades this
     * to the bare count rather than failing a suite. Diagnostics must never be
     * the reason a run goes red.
     *
     * @param array<array-key, string> $leaked
     */
    private static function describe(array $leaked): string
    {
        $seen = [];
        try {
            /** @var mixed $callbacks */
            $callbacks = new \ReflectionProperty(AbstractDriver::class, 'callbacks')->getValue(EventLoop::getDriver());
            if (!\is_array($callbacks)) {
                return '';
            }
            foreach ($leaked as $id) {
                /** @var mixed $callback */
                $callback = $callbacks[$id] ?? null;
                if (!$callback instanceof DriverCallback) {
                    continue;
                }
                $where = new \ReflectionFunction($callback->closure);
                $kind = $callback instanceof TimerCallback
                    ? \sprintf('%s %.3fs', $callback->repeat ? 'repeat' : 'delay', $callback->interval)
                    : new \ReflectionClass($callback)->getShortName();
                $seen[\sprintf('%s %s:%d', $kind, basename((string) $where->getFileName()), $where->getStartLine())] = true;
            }
        } catch (\Throwable) {
            return '';
        }

        return [] === $seen ? '' : ' ('.implode('; ', array_keys($seen)).')';
    }
}
