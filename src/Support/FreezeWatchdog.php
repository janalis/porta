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
 *   the stall duration.
 * - hard hang: an OS alarm (ext-pcntl, a hard requirement) re-armed by every
 *   heartbeat. If the loop blocks long enough for the alarm to fire, the
 *   async signal handler interrupts the blocked syscall and logs a backtrace
 *   naming the exact blocking call site, then re-arms — a permanent hang
 *   leaves a trail every few seconds instead of silence.
 *
 * Logging is best-effort by design: the watchdog must never break the app.
 * `$signalsAvailable` is the test seam — a unit test passes false so it never
 * leaves a live SIGALRM handler behind.
 */
#[AsIdeService]
final class FreezeWatchdog
{
    private ?string $timer = null;
    private ?\DateTimeImmutable $lastBeat = null;
    private readonly DiagnosticLog $log;

    public function __construct(
        string $rootDir,
        private readonly ClockInterface $clock,
        private readonly float $interval = 1.0,
        private readonly float $driftThreshold = 3.0,
        private readonly int $alarmSeconds = 5,
        private readonly bool $signalsAvailable = true,
    ) {
        $this->log = new DiagnosticLog($rootDir.'/.porta/freeze.log');
    }

    public function start(): void
    {
        $this->lastBeat = null;
        $this->timer = EventLoop::repeat($this->interval, $this->heartbeat(...));
        if ($this->signalsAvailable) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGALRM, $this->handleAlarm(...));
            pcntl_alarm($this->alarmSeconds);
        }
    }

    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        if ($this->signalsAvailable) {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    /** Timer entry point — {@see start()}'s EventLoop::repeat fires this every `$interval` seconds. */
    public function heartbeat(): void
    {
        $now = $this->clock->now();
        if (null !== $this->lastBeat) {
            $gap = $now->getTimestamp() - $this->lastBeat->getTimestamp() + (($now->getMicrosecond() - $this->lastBeat->getMicrosecond()) / 1_000_000.0);
            if ($gap > $this->driftThreshold) {
                $this->log->append(\sprintf('%s loop stalled for %.2fs (recovered)', $now->format('Y-m-d H:i:s'), $gap));
            }
        }
        $this->lastBeat = $now;
        if ($this->signalsAvailable) {
            pcntl_alarm($this->alarmSeconds); // re-arm: a live loop never lets it fire
        }
    }

    /** Signal entry point — the SIGALRM handler {@see start()} installs. */
    public function handleAlarm(): void
    {
        // The loop missed $alarmSeconds of heartbeats: it is blocked right now.
        // The async signal interrupted the blocked call — capture where.
        $trace = new \Exception()->getTraceAsString();
        $this->log->append(\sprintf("%s loop blocked \u{2265} %ds, backtrace:\n%s", $this->clock->now()->format('Y-m-d H:i:s'), $this->alarmSeconds, $trace));
        if ($this->signalsAvailable) {
            pcntl_alarm($this->alarmSeconds);
        }
    }

    public function logFile(): string
    {
        return $this->log->path();
    }
}
