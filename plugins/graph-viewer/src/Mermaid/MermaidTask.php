<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Source\ParseError;
use Janalis\Viz\Source\Unsupported;
use Porta\Support\Clocks;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/** Bounded scheduled generator batches, with latest-request-wins cancellation. */
final class MermaidTask
{
    private const int BATCH = 64;
    private const int SLICE_MICROSECONDS = 4_000;

    private ?string $timer = null;
    private int $generation = 0;

    public function __construct(
        private readonly Scheduler $scheduler = new RevoltScheduler(),
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {}

    /**
     * @template T
     * @param \Generator<int, null, mixed, T> $work
     * @param \Closure(T): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function run(\Generator $work, \Closure $success, \Closure $error): \Closure
    {
        $this->cancel();
        $generation = $this->generation;
        $this->timer = $this->scheduler->delay(0.0, fn () => $this->advance($work, $success, $error, $generation));

        return function () use ($generation): void {
            if ($generation === $this->generation) {
                $this->cancel();
            }
        };
    }

    /**
     * @template T
     * @param \Generator<int, null, mixed, T> $work
     * @param \Closure(T): void $success
     * @param \Closure(string): void $error
     */
    private function advance(\Generator $work, \Closure $success, \Closure $error, int $generation): void
    {
        if ($generation !== $this->generation) {
            return;
        }
        $this->timer = null;
        if (!$this->finishSlice($work, $success, $error)) {
            // No self-referencing closure: cancelling the timer releases the generator
            // immediately, including its finally blocks for extracted PHAR resources.
            $this->timer = $this->scheduler->delay(0.0, fn () => $this->advance($work, $success, $error, $generation));
        }
    }

    /**
     * @template T
     * @param \Generator<int, null, mixed, T> $work
     * @param \Closure(T): void $success
     * @param \Closure(string): void $error
     */
    private function finishSlice(\Generator $work, \Closure $success, \Closure $error): bool
    {
        try {
            $deadline = Clocks::microseconds($this->clock) + self::SLICE_MICROSECONDS;
            for ($i = 0; $i < self::BATCH && $work->valid(); ++$i) {
                $work->next();
                if (Clocks::microseconds($this->clock) >= $deadline) {
                    break;
                }
            }
            $complete = !$work->valid();
            if ($complete) {
                $success($work->getReturn());
            }

            return $complete;
        } catch (ParseError|Unsupported|\InvalidArgumentException $exception) {
            $error($exception->getMessage());

            return true;
        }
    }

    public function cancel(): void
    {
        ++$this->generation;
        if (null !== $this->timer) {
            $this->scheduler->cancel($this->timer);
            $this->timer = null;
        }
    }
}
