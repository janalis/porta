<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Revolt\EventLoop;

/**
 * The two event-loop pumps every async test needs, extracted from
 * {@see RunsIde} (which composes this trait) so the non-Ide suites — git
 * subprocesses, LSP/DAP clients, async processes, PTYs — stop hand-rolling
 * divergent copies. The divergence was the risk: sixteen copies silently fell
 * through on timeout, so their assertions ran against pre-op state.
 *
 * The 0.02s turn is NOT a gap that survived the step-key round. Re-checking on
 * a backoff (0, 1, 2, 5, 10, 20ms) was measured over four alternated serial
 * runs and bought nothing: `tests/Unit/Support` 22.8/22.3s → 22.1/22.3s,
 * IdeLspFeaturesTest 11.3/10.9s → 10.7/10.5s, IdeTest inside the noise. An
 * await's condition is checked BEFORE its first turn, so the common case
 * already costs no turns at all, and the ones that do wait are waiting on a
 * subprocess or a server whose latency dwarfs 20ms. At six processes it was
 * actively worse — 13.7s → 24.9s for one E2e run — because `awaitPainted`
 * forces a full render per check, and a tight re-check spends the spare WALL
 * time this suite has on the CPU it does not. `docs/internals/decisions/history-01.md`, "The verify loop, measured", carries the numbers.
 */
trait PumpsLoop
{
    /** The default $what: replaced by the call site when a barrier times out. */
    private const string UNNAMED = "\0unnamed";

    /**
     * Pump the live loop until $done() or timeout, then return. Usable inside a
     * step — steps run on the loop, so suspending here lets subprocess output
     * and async callbacks land. THROWS on timeout: a silent fall-through is the
     * classic flake — the assertion then runs against pre-op state. Await the
     * real condition; if you genuinely want to pump the loop for a fixed span
     * with no condition, use {@see pump()}.
     *
     * The budget is MEASURED, not counted. It used to add 0.02 per turn and
     * call the total elapsed seconds, so under parallel load — where a turn
     * takes longer than its delay — "timed out after 10.0s" could be a 30s
     * wait. That broke the one diagnosis worth trusting: a test that burns its
     * FULL budget lost an effect, it did not race. A number that is not the
     * number enforced cannot support that reading.
     *
     * The default is generous on purpose: these poll real async effects (grep,
     * git, LSP, render passes), and a genuinely stuck condition still throws,
     * just a few seconds later.
     */
    private function await(callable $done, float $timeout = 10.0, string $what = self::UNNAMED): void
    {
        $budget = $timeout * self::coverageSlowdown();
        $deadline = \hrtime(as_number: true) + (int) ($budget * 1e9);
        while (!$done()) {
            if (\hrtime(as_number: true) >= $deadline) {
                throw new \RuntimeException(\sprintf(
                    'timed out after %.1fs waiting for %s',
                    $budget,
                    self::UNNAMED === $what ? self::callerLabel() : $what,
                ));
            }
            $this->turn();
        }
    }

    /**
     * What the default $what reports: the call site, not the word "condition".
     *
     * 284 of the suite's await() calls pass no label, and a bare "timed out
     * waiting for condition" cannot say what was lost — which is the whole
     * value of the throw. Labelling 284 sites by hand invites 284 labels that
     * describe nothing, so the default names itself instead. Built only on the
     * timeout path, so it costs nothing in the passing case. A `what:` that
     * says what the EFFECT was is still better, and still worth adding at the
     * sites that get one.
     */
    private static function callerLabel(): string
    {
        /** @var list<array{file?: string, line?: int}> $frames */
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            if (!\is_string($file) || !\is_int($line)) {
                continue; // an internal frame carries neither
            }
            // Past this trait and the barrier helpers composed beside it —
            // awaitPainted, awaitLanes, awaitSearch all live in tests/Support,
            // and naming one of them would point at the harness, not the test.
            if (!\str_contains($file, '/tests/Support/')) {
                return \sprintf('an unnamed condition at %s:%d', \basename($file), $line);
            }
        }

        return 'an unnamed condition';
    }

    /**
     * Pump the live loop for a fixed span with no completion condition — the
     * deliberate cousin of {@see await()}, for the rare case that has nothing to
     * await (e.g. proving a debounce has NOT yet fired, or draining output whose
     * arrival is the thing under test). Prefer await() whenever a real condition
     * exists.
     *
     * Measured too, and for a sharper reason: this one stands in for a real
     * clock — a double-click window, a gesture burst, a document-sync debounce
     * — so pump(0.4) has to be 0.4s of WALL time. Counting twenty turns of
     * unknown length is the one thing it must not do.
     */
    private function pump(float $seconds): void
    {
        $deadline = \hrtime(as_number: true) + (int) ($seconds * 1e9);
        while (\hrtime(as_number: true) < $deadline) {
            $this->turn();
        }
    }

    /**
     * One 0.02s loop turn. The delay is cancelled in FINALLY: if the loop dies
     * mid-suspend (a poisoned worker), an un-cancelled timer would hold a
     * strong reference to the now-corrupt {main} suspension and anchor the
     * poison for the rest of the worker; cancelling a fired timer is a no-op.
     */
    private function turn(): void
    {
        $suspension = EventLoop::getSuspension();
        $timer = EventLoop::delay(0.02, $suspension->resume(...));
        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($timer);
        }
    }

    /**
     * How much longer a barrier may take under coverage.
     *
     * pcov is 2-3x slower, and the turn-counted budget absorbed that by
     * accident: 500 turns that each took 40ms were 20 seconds, not the 10 the
     * message claimed. Measuring real time removes that accident, so the
     * allowance has to be stated instead — otherwise the same await that
     * passes under `composer test:*` starts timing out under
     * `composer coverage:check`, which is the gate. The sniff is the one
     * tests/bootstrap.php already uses.
     */
    private static function coverageSlowdown(): float
    {
        return '1' === \ini_get('pcov.enabled') ? 3.0 : 1.0;
    }
}
