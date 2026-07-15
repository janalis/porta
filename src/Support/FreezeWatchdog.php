<?php

declare(strict_types=1);

namespace Porta\Support;

use Psr\Clock\ClockInterface;
use Revolt\EventLoop;

/**
 * Detects event-loop stalls and writes the evidence to `.porta/freeze.log`, so
 * an intermittent "app stuck" report becomes a named culprit. Two layers:
 *
 * - drift: a heartbeat timer measures the gap between its own runs; a gap past
 *   the threshold means something blocked the loop and recovered — logged with
 *   the stall duration.
 * - hard hang (when ext-pcntl is available): an OS alarm re-armed by every
 *   heartbeat. If the loop blocks long enough for the alarm to fire, the
 *   async signal handler interrupts the blocked syscall and logs a backtrace
 *   naming the exact blocking call site, then re-arms — a permanent hang
 *   leaves a trail every few seconds instead of silence.
 *
 * Logging is best-effort by design: the watchdog must never break the app.
 */
final class FreezeWatchdog
{
    private ?string $timer = null;
    private ?\DateTimeImmutable $lastBeat = null;
    private ?bool $signalsAvailable;

    public function __construct(
        private readonly string $rootDir,
        private readonly ClockInterface $clock,
        private readonly float $interval = 1.0,
        private readonly float $driftThreshold = 3.0,
        private readonly int $alarmSeconds = 5,
        ?bool $signalsAvailable = null,
    ) {
        $this->signalsAvailable = $signalsAvailable;
    }

    public function start(): void
    {
        $this->signalsAvailable ??= \function_exists('pcntl_alarm');
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
        if (true === $this->signalsAvailable) {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    /** @internal exposed for tests */
    public function heartbeat(): void
    {
        $now = $this->clock->now();
        if (null !== $this->lastBeat) {
            $gap = $now->getTimestamp() - $this->lastBeat->getTimestamp() + (($now->getMicrosecond() - $this->lastBeat->getMicrosecond()) / 1_000_000.0);
            if ($gap > $this->driftThreshold) {
                $this->append(\sprintf('%s loop stalled for %.2fs (recovered)', $now->format('Y-m-d H:i:s'), $gap));
            }
        }
        $this->lastBeat = $now;
        if (true === $this->signalsAvailable) {
            pcntl_alarm($this->alarmSeconds); // re-arm: a live loop never lets it fire
        }
    }

    /** @internal exposed for tests */
    public function handleAlarm(): void
    {
        // The loop missed $alarmSeconds of heartbeats: it is blocked right now.
        // The async signal interrupted the blocked call — capture where.
        $trace = new \Exception()->getTraceAsString();
        $this->append(\sprintf("%s loop blocked \u{2265} %ds, backtrace:\n%s", $this->clock->now()->format('Y-m-d H:i:s'), $this->alarmSeconds, $trace));
        if (true === $this->signalsAvailable) {
            pcntl_alarm($this->alarmSeconds);
        }
    }

    public function logFile(): string
    {
        return $this->rootDir.'/.porta/freeze.log';
    }

    private function append(string $entry): void
    {
        $dir = $this->rootDir.'/.porta';
        if (!is_dir($dir) && is_dir($this->rootDir)) {
            mkdir($dir, permissions: 0o777, recursive: true);
        }
        if (is_dir($dir)) {
            file_put_contents($this->logFile(), $entry."\n", \FILE_APPEND);
        }
    }
}
