<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Git\GitInfo;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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
    use PumpsLoop;

    /** Set by {@see allowFirstRunWelcome()} for the tests that want the real first boot. */
    private bool $allowFirstRunWelcome = false;

    /**
     * Let the Welcome screen open on this fixture's first boot.
     *
     * Every other fixture is pre-marked as seen: a TempProject is a fresh
     * project by construction, so without this the Welcome view would swap over
     * the editor in every E2e test and none of them could see the pane they are
     * asserting on.
     */
    private function allowFirstRunWelcome(): void
    {
        $this->allowFirstRunWelcome = true;
    }

    /** Pre-record the Welcome screen as seen, unless the test asked for first-run. */
    private function suppressFirstRunWelcome(TempProject $project): void
    {
        if ($this->allowFirstRunWelcome) {
            return;
        }

        new OnboardingState($project->dir)->markWelcomeSeen();
    }

    /**
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null, ?string $docsDir = null, int $columns = 160): VirtualTerminal
    {
        $terminal = new VirtualTerminal(columns: $columns, rows: 24);
        $this->suppressFirstRunWelcome($project);
        $ide = new Ide($project->dir, $file, $terminal, docsDir: $docsDir);
        $tui = $ide->resolve(Tui::class);

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
     * Await the app's git completion barrier: block until no git subprocess is
     * in flight ({@see \Porta\Git\GitInfo::isBusy()}). git mutates the working
     * tree before its process exits, so once this returns the on-disk effect —
     * a revert, a stash pop, a checkout — has fully landed and an assertion
     * against the tree (or a mark refresh) is safe.
     */
    private function awaitGit(Ide $ide, float $timeout = 5.0): void
    {
        $git = $ide->resolve(GitInfo::class);
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
