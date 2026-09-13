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

    /** A single frame at least this slow gets its own immediate log line. */
    public const float SLOW_PAINT_SECONDS = 0.1;

    /** Hard memory bound on samples within one beat window. */
    private const int MAX_SAMPLES = 1024;

    private readonly DiagnosticLog $log;
    /** @var \Closure(): float */
    private readonly \Closure $loadAvg;
    private ?string $timer = null;
    private ?float $lastPassAt = null;

    /** @var (\Closure(): int)|null reads the terminal's undelivered-byte backlog */
    private ?\Closure $pendingBytes = null;

    /** @var list<float> seconds between consecutive render passes */
    private array $passGaps = [];

    /** @var list<float> seconds spent generating each painted frame (render + bidi + write) */
    private array $paintDurations = [];

    /** @var list<float> seconds spent in Renderer::render per painted frame */
    private array $renderDurations = [];

    /**
     * @var list<float> seconds spent mirroring each painted frame — the RTL
     *                  reorder only, zero in an LTR session
     */
    private array $bidiDurations = [];

    /** @var list<float> seconds spent in ScreenWriter::writeLines per painted frame */
    private array $writeDurations = [];

    /** @var list<float> seconds spent inside CodeEditorWidget::render per frame */
    private array $editorDurations = [];

    private bool $bootLogged = false;

    private int $inputs = 0;
    private int $wheels = 0;
    private int $passes = 0;
    private int $paints = 0;
    private int $paceSkips = 0;
    private int $capSkips = 0;
    private int $coalesced = 0;
    private int $hlFull = 0;
    private int $hlSplices = 0;

    /**
     * @param (\Closure(): float)|null $loadAvg the FreezeWatchdog-style test
     *                                          seam over sys_getloadavg — a
     *                                          syscall no MockClock can stand
     *                                          in for
     */
    public function __construct(
        string $rootDir,
        private readonly ClockInterface $clock,
        ?FrameHooks $frameHooks = null,
        private readonly float $interval = self::BEAT_INTERVAL,
        ?\Closure $loadAvg = null,
        private readonly ?BootTrace $bootTrace = null,
    ) {
        $this->log = DiagnosticLog::forRoot($rootDir, 'perf', $clock);
        // mago's sys_getloadavg() stub returns an untyped array, so its element
        // is `mixed`. Narrowed rather than cast: is_numeric() is a branch the
        // analyzer can follow, where `(float) $mixed` is not.
        $this->loadAvg = $loadAvg ?? static function (): float {
            $load = sys_getloadavg();

            return \is_array($load) && \is_numeric($load[0] ?? null) ? (float) $load[0] : 0.0;
        };
        $frameHooks?->onBeforeFrame($this->passMark(...));
    }

    /**
     * Install (or clear, with null) the terminal-backlog probe, sampled at
     * beat time and on slow-paint lines — it separates "the widget tree was
     * slow" from "the outer terminal stopped draining".
     *
     * @param (\Closure(): int)|null $probe
     */
    public function setPendingBytesProbe(?\Closure $probe): void
    {
        $this->pendingBytes = $probe;
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

    /**
     * A frame actually painted: how long the widget tree, the RTL reorder and
     * the terminal write each took.
     *
     * The reorder is its own number because it is neither of the other two and
     * is bigger than both: measured on the reported 226x67 screen it costs
     * ~8ms a frame, and while it was folded into the write it read as eight
     * milliseconds of terminal on a tty with zero bytes pending. The parameter
     * is LAST rather than in pipeline order so the two original positional
     * arguments keep their meaning; the log prints them in pipeline order.
     */
    public function paintMark(float $renderSeconds, float $writeSeconds = 0.0, float $bidiSeconds = 0.0): void
    {
        if (!$this->bootLogged && null !== $this->bootTrace) {
            // Once, before any slow-paint line this same frame could add: the
            // boot line is the session's opening evidence, phase-stamped here
            // because only the paint path knows when the first frame landed.
            $this->bootLogged = true;
            $this->bootTrace->mark('firstPaint');
            $this->log->append($this->bootTrace->line());
        }
        ++$this->paints;
        $total = $renderSeconds + $bidiSeconds + $writeSeconds;
        if (\count($this->paintDurations) < self::MAX_SAMPLES) {
            $this->paintDurations[] = $total;
            $this->renderDurations[] = $renderSeconds;
            $this->bidiDurations[] = $bidiSeconds;
            $this->writeDurations[] = $writeSeconds;
        }
        if ($total >= self::SLOW_PAINT_SECONDS) {
            // One immediate line per bad frame, naming the layer on the spot:
            // render-heavy = the widget tree, bidi-heavy = the RTL reorder,
            // write-heavy = the tty, all three tiny while the wall clock
            // stalled = the machine's scheduler.
            $this->log->append(\sprintf(
                'slow-paint render=%.0fms bidi=%.0fms write=%.0fms pendingBytes=%d load=%.1f',
                1000 * $renderSeconds,
                1000 * $bidiSeconds,
                1000 * $writeSeconds,
                null !== $this->pendingBytes ? (int) ($this->pendingBytes)() : 0,
                ($this->loadAvg)(),
            ));
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

    /**
     * A syntax parse completed — a whole-document one, or an incremental
     * splice. The ratio is the edit path's cost attribution: a beat full of
     * `hlFull` while typing means every keystroke re-parsed the file.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whole-parse vs splice IS the measurement, not a mode switch
    public function countHighlightParse(bool $incremental): void
    {
        if ($incremental) {
            ++$this->hlSplices;

            return;
        }
        ++$this->hlFull;
    }

    /** The editor widget finished one render — its share of the frame's renderMs. */
    public function editorMark(float $seconds): void
    {
        if (\count($this->editorDurations) < self::MAX_SAMPLES) {
            $this->editorDurations[] = $seconds;
        }
    }

    /** Timer entry point — {@see start()}'s EventLoop::repeat fires this every `$interval` seconds. */
    public function beat(): void
    {
        if (0 === $this->passes && 0 === $this->inputs) {
            $this->reset(); // idle window: log nothing, keep the size cap for real history

            return;
        }
        $this->log->append(\sprintf(
            'in=%d wheel=%d passes=%d paints=%d paceSkip=%d capSkip=%d coalesced=%d gapMs p50=%s p95=%s max=%s paintMs p50=%s p95=%s busy=%d renderMs p50=%s p95=%s bidiMs p50=%s p95=%s writeMs p50=%s p95=%s edMs p50=%s p95=%s hlFull=%d hlSplice=%d pend=%d load=%.1f',
            $this->inputs,
            $this->wheels,
            $this->passes,
            $this->paints,
            $this->paceSkips,
            $this->capSkips,
            $this->coalesced,
            self::ms($this->passGaps, 0.50),
            self::ms($this->passGaps, 0.95),
            [] === $this->passGaps ? self::NO_SAMPLES : \sprintf('%.1f', 1000 * max($this->passGaps)),
            self::ms($this->paintDurations, 0.50),
            self::ms($this->paintDurations, 0.95),
            (int) (1000 * array_sum($this->paintDurations)),
            self::ms($this->renderDurations, 0.50),
            self::ms($this->renderDurations, 0.95),
            self::ms($this->bidiDurations, 0.50),
            self::ms($this->bidiDurations, 0.95),
            self::ms($this->writeDurations, 0.50),
            self::ms($this->writeDurations, 0.95),
            self::ms($this->editorDurations, 0.50),
            self::ms($this->editorDurations, 0.95),
            $this->hlFull,
            $this->hlSplices,
            null !== $this->pendingBytes ? (int) ($this->pendingBytes)() : 0,
            ($this->loadAvg)(),
        ));
        $this->reset();
    }

    public function logFile(): string
    {
        return $this->log->path();
    }

    /**
     * What an empty sample set prints. NOT `0.0`, which is the whole point:
     * a percentile of nothing is not a fast measurement, and the two read
     * identically as a number.
     *
     * `edMs` is where this bites. Every other timing field has a counter
     * beside it that gives it away — `paints=0` says why `paintMs` is empty —
     * and the editor's has none, so `edMs p50=0.0` means either "the editor
     * rendered twenty times and cost nothing" or "the editor never rendered",
     * with nothing in the line to separate them. Reading a real session's log
     * this way produced the conclusion "the editor is free, so the frame's
     * time is elsewhere in the widget tree", which the log does not support
     * and may be exactly backwards.
     */
    private const string NO_SAMPLES = '-';

    /**
     * One percentile, in milliseconds, or {@see NO_SAMPLES} when there is
     * nothing to take one of.
     *
     * @param list<float> $samples
     */
    private static function ms(array $samples, float $q): string
    {
        return [] === $samples ? self::NO_SAMPLES : \sprintf('%.1f', 1000 * self::percentile($samples, $q));
    }

    /**
     * The empty case is NOT guarded here any more, and that is the point of
     * the guard moving up: returning `0.0` for no samples is what made an
     * absent measurement print as a fast one. {@see ms()} is the only caller
     * and answers `-` before reaching this, so the parameter is non-empty by
     * contract rather than by a branch nothing can take.
     *
     * @param non-empty-list<float> $samples
     */
    private static function percentile(array $samples, float $q): float
    {
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
        $this->hlFull = 0;
        $this->hlSplices = 0;
        $this->passGaps = [];
        $this->paintDurations = [];
        $this->renderDurations = [];
        $this->bidiDurations = [];
        $this->writeDurations = [];
        $this->editorDurations = [];
        // A fresh window starts gap-free: an idle stretch between windows must
        // not land as one giant sample.
        $this->lastPassAt = null;
    }
}
