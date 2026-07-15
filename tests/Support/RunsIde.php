<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ide;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The shared E2e harness: boot a real Ide against a VirtualTerminal, schedule
 * synthetic-input steps on the live Revolt loop, stop, return the terminal
 * for output assertions.
 *
 * Without an explicit $duration the loop stops shortly after the LAST step
 * ran (a short grace + a final tick to flush the render) instead of sleeping
 * a fixed tail — pass $duration only when effects genuinely land after the
 * final step (debounces, subprocess output), or better, make the last step
 * await() the effect explicitly.
 */
trait RunsIde
{
    /**
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null, ?string $docsDir = null): VirtualTerminal
    {
        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $file, $terminal, docsDir: $docsDir);
        $tui = $ide->getTui();

        // Steps chain sequentially: each schedules the next with the key
        // DELTA after it completes. A step may await() (suspending on the
        // loop) without a later step's timer firing underneath it.
        $timers = [];
        ksort($steps);
        $keys = array_keys($steps);
        $schedule = static function (int $index, int $previousKey) use (&$schedule, &$timers, $steps, $keys, $tui, $terminal, $ide, $duration): void {
            $key = $keys[$index];
            $timers[] = EventLoop::delay(($key - $previousKey) / 1000, static function () use ($index, $key, $steps, $keys, $tui, $terminal, $ide, $duration, $schedule): void {
                $steps[$key]($tui, $terminal, $ide);
                if (array_key_exists($index + 1, $keys)) {
                    $schedule($index + 1, $key);

                    return;
                }
                if (null === $duration) {
                    EventLoop::delay(0.08, static function () use ($tui): void {
                        $tui->tick();
                        $tui->stop();
                    });
                }
            });
        };
        if ([] !== $keys) {
            $schedule(index: 0, previousKey: 0);
        }
        if (null !== $duration || [] === $keys) {
            $timers[] = EventLoop::delay($duration ?? 0.7, $tui->stop(...));
        }

        try {
            $ide->run();
        } finally {
            foreach ($timers as $timer) {
                EventLoop::cancel($timer);
            }
        }

        return $terminal;
    }

    /**
     * Pump the live loop until $done() or timeout; the caller asserts after.
     * Usable inside a step — steps run on the loop, so suspending here lets
     * subprocess output and async callbacks land.
     */
    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }
}
