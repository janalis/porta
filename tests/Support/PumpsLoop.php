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
 */
trait PumpsLoop
{
    /**
     * Pump the live loop until $done() or timeout, then return. Usable inside a
     * step — steps run on the loop, so suspending here lets subprocess output
     * and async callbacks land. THROWS on timeout: a silent fall-through is the
     * classic flake — the assertion then runs against pre-op state. Await the
     * real condition; if you genuinely want to pump the loop for a fixed span
     * with no condition, use {@see pump()}.
     *
     * The default is generous on purpose: these poll real async effects (grep,
     * git, LSP, render passes), and under the pcov coverage run (2-3x slower)
     * plus heavy parallelism a tight budget times out before the effect lands —
     * a genuinely stuck condition still throws, just a few seconds later.
     */
    private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void
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
}
