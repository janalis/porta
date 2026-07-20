<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ide;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui;
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
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null, ?string $docsDir = null, int $columns = 160): VirtualTerminal
    {
        $terminal = new VirtualTerminal(columns: $columns, rows: 24);
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
     * Pump the live loop until $done() or timeout, then return. Usable inside a
     * step — steps run on the loop, so suspending here lets subprocess output
     * and async callbacks land. THROWS on timeout: a silent fall-through is the
     * classic flake — the assertion then runs against pre-op state. Await the
     * real condition; if you genuinely want to pump the loop for a fixed span
     * with no condition, use {@see pump()}.
     */
    private function await(callable $done, float $timeout = 3.0, string $what = 'condition'): void
    {
        $elapsed = 0.0;
        while (!$done()) {
            if ($elapsed >= $timeout) {
                throw new \RuntimeException(\sprintf('timed out after %.1fs waiting for %s', $timeout, $what));
            }
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    /**
     * Pump the live loop for a fixed span with no completion condition — the
     * deliberate cousin of {@see await()}, for the rare case that has nothing to
     * await (e.g. proving a debounce has NOT yet fired, or draining output whose
     * arrival is the thing under test). Prefer await() whenever a real condition
     * exists.
     */
    private function pump(float $seconds): void
    {
        $elapsed = 0.0;
        while ($elapsed < $seconds) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    /**
     * Await the app's git completion barrier: block until no git subprocess is
     * in flight ({@see \Porta\Git\GitInfo::isBusy()}). git mutates the working
     * tree before its process exits, so once this returns the on-disk effect —
     * a revert, a stash pop, a checkout — has fully landed and an assertion
     * against the tree (or a mark refresh) is safe.
     */
    private function awaitGit(Ide $ide, float $timeout = 5.0): void
    {
        $git = new \ReflectionProperty($ide, 'git')->getValue($ide);
        \assert($git instanceof \Porta\Git\GitInfo, description: 'Ide::$git is the GitInfo barrier');
        $this->await(static fn (): bool => !$git->isBusy(), $timeout, 'git to finish');
    }

    /**
     * Force a full render pass so widget rects and rendered glyphs reflect the
     * current tree. A bare tick() renders only when a change already requested
     * one; reading getWidgetRect (or a rendered row) behind a plain delay races
     * the first frame. This is the force-frame idiom screenGrid() uses.
     */
    private function frame(Tui $tui): void
    {
        $tui->requestRender(force: true);
        $tui->processRender();
    }
}
