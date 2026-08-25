<?php

declare(strict_types=1);

namespace Porta\Support;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Ui\FrameHooks;
use Psr\Clock\ClockInterface;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Collects per-second render/input evidence and appends it to
 * `.porta/perf.log`, so "scrolling feels laggy" becomes numbers from the
 * user's own session: input events vs frames painted, inter-pass gaps, paint
 * durations, and how often frame generation was skipped (terminal
 * back-pressure, frame-rate cap) or coalesced. Counters are fed by direct
 * method calls — never events: the feeders sit on render paths, where
 * dispatching is forbidden.
 *
 * Always on, like {@see FreezeWatchdog}: the collection cost is a few clock
 * reads per frame, and the log writes one line per second only while the
 * session is actually doing something — an idle window appends nothing, so
 * the log's size cap never rotates away the interesting history.
 */
#[AsIdeService]
#[AsEager]
final class PerfCollector
{
    public const float BEAT_INTERVAL = 1.0;

    /** Hard memory bound on samples within one beat window. */
    private const int MAX_SAMPLES = 1024;

    private readonly DiagnosticLog $log;
    private ?string $timer = null;
    private ?float $lastPassAt = null;

    /** @var list<float> seconds between consecutive render passes */
    private array $passGaps = [];

    /** @var list<float> seconds spent generating each painted frame */
    private array $paintDurations = [];

    private int $inputs = 0;
    private int $wheels = 0;
    private int $passes = 0;
    private int $paints = 0;
    private int $paceSkips = 0;
    private int $capSkips = 0;
    private int $coalesced = 0;

    public function __construct(
        string $rootDir,
        private readonly ClockInterface $clock,
        ?FrameHooks $frameHooks = null,
        private readonly float $interval = self::BEAT_INTERVAL,
    ) {
        $this->log = DiagnosticLog::forRoot($rootDir, 'perf');
        $frameHooks?->onBeforeFrame($this->passMark(...));
    }

    public function start(): void
    {
        $this->timer = EventLoop::repeat($this->interval, $this->beat(...));
    }

    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    /** Frame-hook entry point: a render pass began (it may not paint). */
    public function passMark(): void
    {
        ++$this->passes;
        $now = Clocks::seconds($this->clock);
        if (null !== $this->lastPassAt && \count($this->passGaps) < self::MAX_SAMPLES) {
            $this->passGaps[] = $now - $this->lastPassAt;
        }
        $this->lastPassAt = $now;
    }

    /** High-priority Tui listener: counts every input, wheel notches separately. */
    public function countInput(InputEvent $event): void
    {
        ++$this->inputs;
        $data = $event->getData();
        if (str_starts_with($data, "\x1b[<64;") || str_starts_with($data, "\x1b[<65;")) {
            ++$this->wheels;
        }
    }

    /** A frame actually painted, taking this long to generate. */
    public function paintMark(float $durationSeconds): void
    {
        ++$this->paints;
        if (\count($this->paintDurations) < self::MAX_SAMPLES) {
            $this->paintDurations[] = $durationSeconds;
        }
    }

    /** Frame generation skipped: the terminal still holds undelivered bytes. */
    public function countPaceSkip(): void
    {
        ++$this->paceSkips;
    }

    /** Frame generation skipped: inside the minimum frame interval. */
    public function countCapSkip(): void
    {
        ++$this->capSkips;
    }

    /** A render request landed while a same-iteration tick was already queued. */
    public function countCoalesced(): void
    {
        ++$this->coalesced;
    }

    /** Timer entry point — {@see start()}'s EventLoop::repeat fires this every `$interval` seconds. */
    public function beat(): void
    {
        if (0 === $this->passes && 0 === $this->inputs) {
            $this->reset(); // idle window: log nothing, keep the size cap for real history

            return;
        }
        $this->log->append(\sprintf(
            '%s in=%d wheel=%d passes=%d paints=%d paceSkip=%d capSkip=%d coalesced=%d gapMs p50=%.1f p95=%.1f max=%.1f paintMs p50=%.1f p95=%.1f busy=%d',
            $this->clock->now()->format('Y-m-d H:i:s'),
            $this->inputs,
            $this->wheels,
            $this->passes,
            $this->paints,
            $this->paceSkips,
            $this->capSkips,
            $this->coalesced,
            1000 * self::percentile($this->passGaps, 0.50),
            1000 * self::percentile($this->passGaps, 0.95),
            1000 * ([] === $this->passGaps ? 0.0 : max($this->passGaps)),
            1000 * self::percentile($this->paintDurations, 0.50),
            1000 * self::percentile($this->paintDurations, 0.95),
            (int) (1000 * array_sum($this->paintDurations)),
        ));
        $this->reset();
    }

    public function logFile(): string
    {
        return $this->log->path();
    }

    /** @param list<float> $samples */
    private static function percentile(array $samples, float $q): float
    {
        if ([] === $samples) {
            return 0.0;
        }
        sort($samples);

        // Nearest-rank: the smallest sample with at least q of the set at or
        // below it (1-based rank ceil(q*N), as a 0-based index).
        return $samples[max(0, (int) ceil($q * \count($samples)) - 1)];
    }

    private function reset(): void
    {
        $this->inputs = 0;
        $this->wheels = 0;
        $this->passes = 0;
        $this->paints = 0;
        $this->paceSkips = 0;
        $this->capSkips = 0;
        $this->coalesced = 0;
        $this->passGaps = [];
        $this->paintDurations = [];
        // A fresh window starts gap-free: an idle stretch between windows must
        // not land as one giant sample.
        $this->lastPassAt = null;
    }
}
