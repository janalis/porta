<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Config\ConfigScope;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsScope;
use Porta\Workbench\View\ViewRegistry;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The shared E2e harness: boot a real Ide against a VirtualTerminal, schedule
 * synthetic-input steps on the live Revolt loop, stop, return the terminal
 * for output assertions.
 *
 * The loop stops right after the LAST step ran, behind a final forced frame
 * that flushes what it painted. Effects that land after the final step are
 * awaited IN that step — there is no duration to pass, because a clock is not
 * a barrier.
 */
trait RunsIde
{
    use AwaitsGit;
    use PumpsLoop;
    use ScreenReads;

    /**
     * Wait for $needle to be ON SCREEN, forcing a frame each turn.
     *
     * The two-moments problem: a flash is set synchronously, but whether it has
     * PAINTED depends on a render happening after it — and a bare tick() is not
     * a frame (the ticker is adaptive). Forcing one frame is right when nothing
     * else has to happen first; when a loop callback sits between the action and
     * the paint, only forcing a frame per turn is reliable, and under parallel
     * load that gap is real. The assertion stays at the call site: this is the
     * barrier, not the check.
     */
    private function awaitPainted(Tui $tui, VirtualTerminal $terminal, string $needle, float $timeout = 10.0): void
    {
        $this->await(
            function () use ($tui, $terminal, $needle): bool {
                $this->frame($tui);

                return \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), $needle);
            },
            timeout: $timeout,
            what: $needle.' to paint',
        );
    }

    /**
     * {@see awaitPainted()} against the RAW byte stream.
     *
     * Colour and cursor-shape assertions look for escape sequences —
     * `\x1b[38;5;91m` for a theme's keyword colour, `\x1b[1 q` for a DECSCUSR
     * shape — and `awaitPainted()` strips precisely those before it looks, so
     * it can never match one.
     *
     * The pairing is what makes this matter rather than merely fail: these
     * tests `clearOutput()`, change a setting, and then assert the new
     * sequence is present AND the old one is gone. Without a real frame the
     * buffer is still empty, so the negative half passes by having nothing to
     * look at — a green assertion that proves the opposite of what it claims.
     */
    private function awaitPaintedRaw(Tui $tui, VirtualTerminal $terminal, string $needle, float $timeout = 10.0): void
    {
        $this->await(
            function () use ($tui, $terminal, $needle): bool {
                $this->frame($tui);

                return \str_contains($terminal->getOutput(), $needle);
            },
            timeout: $timeout,
            what: 'raw '.\addcslashes($needle, characters: "\0..\37").' to paint',
        );
    }

    /**
     * Wait until the mock language server is actually UP, not merely configured.
     *
     * An LSP request fired at a session that has not initialised is dropped in
     * silence: no request goes out, so no response and no flash ever arrive,
     * and whatever barrier follows burns its entire budget. The editor having
     * a file path does NOT imply this — the file opens first and the session
     * starts behind it, which is what the old 300-400 ms boot gaps were really
     * covering. The status bar naming the server is the signal that it is up.
     *
     * Measured, not assumed: with the boot gap at 100 ms and no barrier,
     * testCompletionResolveWithoutImportsLeavesFileUntouched and
     * testGoToImplementationWithNoResultFlashes each failed 6 runs in 30 under
     * 16 CPU burners, both timing out on the effect of a request that had
     * never been sent. IdeTest's scripted session is the second caller: its
     * Ctrl+Space went out before `initialize` returned the moment its own
     * 0.4/0.5s boot gaps came off.
     */
    private function awaitLspReady(Tui $tui, VirtualTerminal $terminal): void
    {
        $this->awaitPainted($tui, $terminal, 'LSP:mock-lsp-server');
    }

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
     * Write settings into the scenario's OWN project, not into a home shared
     * with every other scenario.
     *
     * A real first-time user defaults to the global scope, which is the whole
     * point of it — but a global write here lands in `PORTA_HOME`, which for
     * most suites is the committed fixture home and for the rest is the
     * developer's actual one. Either way it outlives the test: a toggled
     * `tabBarPosition` sat in that file and broke four later scenarios that
     * had never heard of it.
     *
     * An E2e scenario asserts about its TempProject, so that is where its
     * writes belong. A test that means to exercise the global scope sets it
     * back with {@see \Porta\Settings\SettingsScope::set()} in a step.
     */
    private function pinSettingsScope(Ide $ide): void
    {
        Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Project);
    }

    /**
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     * @param int                  $rows  terminal height; the default is roomy
     *                                    enough that nothing scrolls, so a test
     *                                    about scrolling has to ask for less
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?string $docsDir = null, int $columns = 160, int $rows = 24): VirtualTerminal
    {
        $terminal = new VirtualTerminal(columns: $columns, rows: $rows);
        $this->suppressFirstRunWelcome($project);
        $ide = new Ide($project->dir, $file, $terminal, docsDir: $docsDir);
        $this->pinSettingsScope($ide);
        $tui = Services::resolve($ide, Tui::class);

        // Stopping is a STEP, not a branch in the scheduler: it chains behind
        // the last step the same way every other step does, so a step that
        // awaits cannot have the terminator fire underneath it. Its key is 80ms
        // past the last one, which only costs anything when the gaps are being
        // replayed on purpose.
        $steps[([] === $steps ? 0 : \max(\array_keys($steps))) + 80] = static function (Tui $tui): void {
            $tui->tick();
            // A bare tick() is not a frame: force one, or what the last step
            // flashed is on screen only if the frame cap allowed it.
            $tui->requestRender(force: true);
            $tui->processRender();
            $tui->stop();
        };

        $this->runSteps($steps, $tui, $terminal, $ide);

        return $terminal;
    }

    /**
     * Chain $steps onto the live loop, run the Ide, cancel every timer.
     *
     * Steps run **sequentially chained** — each schedules the next after it
     * completes, so a step may await() a subprocess or async effect without a
     * later step firing underneath it.
     *
     * **The keys ORDER the steps; they are not waits.** The gap between two
     * steps is capped at PORTA_E2E_STEP_GAP ms, default 0, and every step
     * starts behind one forced frame (a user's input always lands after a
     * paint). A step that needs something to have HAPPENED awaits it; one that
     * needs TIME to pass says so with pump(). Set the variable high to replay
     * the keys as real gaps when chasing a flake — a test that only passes that
     * way is leaning on a gap.
     *
     * Timers are cancelled in FINALLY: when a step already stopped the Tui, a
     * surviving terminator would tick the dead Ide in the next test to run in
     * this worker.
     *
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     */
    private function runSteps(array $steps, Tui $tui, VirtualTerminal $terminal, Ide $ide): void
    {
        $timers = [];
        \ksort($steps);
        $keys = \array_keys($steps);
        $gap = \getenv('PORTA_E2E_STEP_GAP');
        $cap = \is_numeric($gap) ? (float) $gap : 0.0;
        $schedule = static function (int $index, int $previousKey) use (&$schedule, &$timers, $steps, $keys, $tui, $terminal, $ide, $cap): void {
            $key = $keys[$index];
            $timers[] = EventLoop::delay(\min($key - $previousKey, $cap) / 1000, static function () use ($index, $key, $steps, $keys, $tui, $terminal, $ide, $schedule): void {
                // A user's input always lands after a paint, so every step does:
                // with no gap, a bare tick() inside the 60fps cap paints nothing.
                $tui->requestRender(force: true);
                $tui->processRender();
                $steps[$key]($tui, $terminal, $ide);
                if (\array_key_exists($index + 1, $keys)) {
                    $schedule($index + 1, $key);
                }
            });
        };
        if ([] !== $keys) {
            $schedule(index: 0, previousKey: 0);
        }

        try {
            $ide->run();
        } finally {
            foreach ($timers as $timer) {
                EventLoop::cancel($timer);
            }
        }
    }

    /**
     * Await the app's git completion barrier: block until no git subprocess is
     * in flight (the GitProcessRunner's in-flight map). git mutates the working
     * tree before its process exits, so once this returns the on-disk effect —
     * a revert, a stash pop, a checkout — has fully landed and an assertion
     * against the tree (or a mark refresh) is safe.
     */
    private function awaitGit(Ide $ide, float $timeout = 5.0): void
    {
        // Reached through the registered VIEW rather than a service: git is a
        // plugin, so its repository handle is not in the container at all. The
        // view is the one plugin-built object core can name, and `find()` is
        // how anything else would reach it too.
        $view = Services::resolve($ide, ViewRegistry::class)->find('git');
        if (null === $view) {
            return; // the git plugin is not loaded — nothing can be in flight
        }
        // BOTH lanes, walked from the two objects the view already holds. The
        // plugin keeps them apart so that "Cancel Remote Operation" can stop a
        // fetch without stopping the status poll; a barrier that waited on one
        // let a test walk out with the other still running.
        $statusLane = Probe::object($view, 'git', 'runner', 'lane');
        $remoteLane = Probe::object($view, 'remoteCommands', 'remote', 'lane');
        \assert($statusLane instanceof ProcessLane && $remoteLane instanceof ProcessLane, description: 'the git view holds its two process lanes');
        $this->awaitLanes([$statusLane, $remoteLane], $timeout);
    }
}
