<?php

declare(strict_types=1);

namespace Porta\Support;

use Porta\Container\AsIdeService;
use Psr\Clock\ClockInterface;
use Revolt\EventLoop;

/**
 * Detects event-loop stalls and writes the evidence to `.porta/freeze.log`, so
 * an intermittent "app stuck" report becomes a named culprit. Two layers:
 *
 * - drift: a heartbeat timer measures the gap between its own runs; a gap past
 *   the threshold means something blocked the loop and recovered — logged with
 *   the stall duration. The gap is MONOTONIC, not wall clock: a suspended
 *   laptop advances the wall clock by hours while the loop is not blocked at
 *   all, and this log had a dozen of those in it — `loop stalled for 1078.06s`
 *   is the machine having slept, and it is the most alarming-looking line in a
 *   file whose whole job is to name culprits. `hrtime()` does not advance while
 *   the process is suspended, so a sleep leaves nothing behind.
 * - hard hang: an OS alarm (ext-pcntl, a hard requirement) re-armed by every
 *   heartbeat. If the loop blocks long enough for the alarm to fire, the
 *   async signal handler interrupts the blocked syscall and logs a backtrace
 *   naming the exact blocking call site, then re-arms — a permanent hang
 *   leaves a trail every few seconds instead of silence.
 *
 * The alarm is the PROCESS's, and someone may already hold it: PHPUnit's
 * per-test time limit is a SIGALRM too. Overwriting it, as this did, meant no
 * test that boots an IDE had a time limit at all — a hung E2e step ran until
 * the worker was killed. So an alarm already pending at start() is kept: its
 * handler fires when its deadline passes, and stop() hands back the handler
 * and whatever is left of the time.
 *
 * Logging is best-effort by design: the watchdog must never break the app.
 * `$signalsAvailable` is the test seam — a unit test passes false so it never
 * leaves a live SIGALRM handler behind.
 */
#[AsIdeService]
final class FreezeWatchdog
{
    private ?string $timer = null;
    private ?float $lastBeat = null;

    /** What held SIGALRM before start(): restored by stop(). */
    private \Closure|int $previousHandler = \SIG_DFL;

    /**
     * When the alarm pending at start() falls due, on the monotonic clock —
     * null when there was none, or its handler was not a callable to forward to.
     */
    private ?float $outerDue = null;
    private readonly DiagnosticLog $log;

    /** @var \Closure(): float seconds on a clock that stops when the process does */
    private readonly \Closure $monotonic;

    /**
     * @param (\Closure(): float)|null $monotonic the drift clock, in seconds; null uses `hrtime()`.
     *                                            Injected rather than called directly so a test can
     *                                            move the two clocks INDEPENDENTLY, which is the whole
     *                                            distinction being drawn — the `ClockInterface` here
     *                                            is only ever the log's stamp clock, handed
     *                                            straight to {@see DiagnosticLog}, which dates
     *                                            every entry it writes.
     */
    public function __construct(
        string $rootDir,
        ClockInterface $clock,
        private readonly float $interval = 1.0,
        private readonly float $driftThreshold = 3.0,
        private readonly int $alarmSeconds = 5,
        private readonly bool $signalsAvailable = true,
        ?\Closure $monotonic = null,
    ) {
        $this->log = DiagnosticLog::forRoot($rootDir, 'freeze', $clock);
        $this->monotonic = $monotonic ?? static fn (): float => \hrtime(as_number: true) / 1_000_000_000;
    }

    public function start(): void
    {
        $this->lastBeat = null;
        $this->timer = EventLoop::repeat($this->interval, $this->heartbeat(...));
        if ($this->signalsAvailable) {
            \pcntl_async_signals(true);
            $pending = \pcntl_alarm(0);
            $this->previousHandler = self::alarmHandler();
            $this->outerDue = $pending > 0 && $this->previousHandler instanceof \Closure ? ($this->monotonic)() + $pending : null;
            \pcntl_signal(\SIGALRM, $this->handleAlarm(...));
            $this->arm();
        }
    }

    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        if ($this->signalsAvailable) {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, $this->previousHandler);
            if (null !== $this->outerDue) {
                \pcntl_alarm($this->secondsToOuter());
            }
            $this->previousHandler = \SIG_DFL;
            $this->outerDue = null;
        }
    }

    /** Timer entry point — {@see start()}'s EventLoop::repeat fires this every `$interval` seconds. */
    public function heartbeat(): void
    {
        $beat = ($this->monotonic)();
        if (null !== $this->lastBeat) {
            $gap = $beat - $this->lastBeat;
            if ($gap > $this->driftThreshold) {
                // The gap includes the interval the loop spent idle, as it
                // should, waiting for this beat: what it was BLOCKED for is
                // the rest. Logged whole, every line read a quarter second
                // long at the IDE's 0.25s heartbeat.
                $this->log->append(\sprintf('loop stalled for %.2fs (recovered)', $gap - $this->interval));
            }
        }
        $this->lastBeat = $beat;
        if ($this->signalsAvailable) {
            $this->arm(); // re-arm: a live loop never lets it fire
        }
    }

    /** Signal entry point — the SIGALRM handler {@see start()} installs. */
    public function handleAlarm(): void
    {
        if ($this->outerFired()) {
            return; // not ours: the alarm that was pending at start() was due
        }
        // The loop missed $alarmSeconds of heartbeats: it is blocked right now.
        // The async signal interrupted the blocked call — capture where.
        $trace = new \Exception()->getTraceAsString();
        $this->log->append(\sprintf("loop blocked \u{2265} %ds, backtrace:\n%s", $this->alarmSeconds, $trace));
        if ($this->signalsAvailable) {
            $this->arm();
        }
    }

    /**
     * Run the handler of the alarm pending at start() once its deadline has
     * passed, as if it had never been displaced — PHPUnit's throws, which is
     * the point. Checked on every beat as well as on our own alarm: a live
     * loop re-arms the alarm faster than a second, so it never fires there.
     */
    private function outerFired(): bool
    {
        if (null === $this->outerDue || ($this->monotonic)() < $this->outerDue) {
            return false;
        }
        $this->outerDue = null;
        if ($this->previousHandler instanceof \Closure) {
            ($this->previousHandler)(\SIGALRM, []);
        }

        return true;
    }

    /**
     * Who holds SIGALRM now: a disposition (`SIG_DFL`, `SIG_IGN`) or a handler.
     * The stub types the call `bool|resource`; PHP returns `callable|int`.
     */
    private static function alarmHandler(): \Closure|int
    {
        /** @var mixed $handler */
        $handler = \pcntl_signal_get_handler(\SIGALRM);
        if (\is_int($handler)) {
            return $handler;
        }

        return \is_callable($handler) ? \Closure::fromCallable($handler) : \SIG_DFL;
    }

    /** Our own alarm, brought forward to the outer one's deadline when that comes first. */
    private function arm(): void
    {
        $this->outerFired();
        \pcntl_alarm(null === $this->outerDue ? $this->alarmSeconds : \min($this->alarmSeconds, $this->secondsToOuter()));
    }

    /** Whole seconds until the outer alarm is due — at least one, as `alarm(0)` would cancel instead. */
    private function secondsToOuter(): int
    {
        return \max(1, (int) \ceil((float) $this->outerDue - ($this->monotonic)()));
    }

    public function logFile(): string
    {
        return $this->log->path();
    }
}
