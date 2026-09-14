<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Cumulative boot-phase marks, written as one `boot …` line to perf.log on the
 * first painted frame: "startup feels slow" becomes per-phase numbers from the
 * user's own boot. Times come from a raw hrtime, not {@see ClockInterface} —
 * the clock seam is whole-second, and boot phases are milliseconds.
 *
 * Created before the container exists (the prelude covers the interpreter and
 * autoload, which no service can observe), handed to {@see \Porta\Ide} and
 * re-published as a synthetic service so {@see PerfCollector} can stamp the
 * first paint — the IdeConfig pattern.
 */
final class BootTrace
{
    /** @var list<array{string, float}> mark name → cumulative milliseconds since process start */
    private array $marks = [];

    private readonly float $startNs;

    /** @var \Closure(): float */
    private readonly \Closure $nanos;

    /**
     * @param float|null            $processStartSeconds the wall-clock start of the process
     *                                                   ($_SERVER['REQUEST_TIME_FLOAT']); when
     *                                                   given, a `prelude` mark records what ran
     *                                                   before construction and every later mark
     *                                                   stays cumulative from that origin
     * @param (\Closure(): float)|null $nanos            test seam over hrtime(true)
     * @param (\Closure(): float)|null $wallSeconds      test seam over microtime(true), read once
     *                                                   to measure the prelude
     */
    public function __construct(?float $processStartSeconds = null, ?\Closure $nanos = null, ?\Closure $wallSeconds = null)
    {
        $this->nanos = $nanos ?? static fn (): float => (float) \hrtime(true);
        $this->startNs = ($this->nanos)();
        if (null !== $processStartSeconds) {
            $wall = $wallSeconds ?? static fn (): float => \microtime(true);
            $this->marks[] = ['prelude', 1000.0 * \max(0.0, $wall() - $processStartSeconds)];
        }
    }

    /** Record that the named boot phase just finished. */
    public function mark(string $name): void
    {
        $prelude = [] !== $this->marks && 'prelude' === $this->marks[0][0] ? $this->marks[0][1] : 0.0;
        $this->marks[] = [$name, $prelude + ((($this->nanos)() - $this->startNs) / 1_000_000.0)];
    }

    /** The single log line: `boot prelude=65ms container=120ms … firstPaint=420ms`. */
    public function line(): string
    {
        return 'boot '
        .\implode(' ', \array_map(
            static fn (array $mark): string => \sprintf('%s=%.0fms', $mark[0], $mark[1]),
            $this->marks,
        ));
    }
}
