<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * How a {@see DapSession} reports back to the IDE. Every callback runs on the
 * shared Revolt loop (adapter events arrive there), so implementations mutate
 * UI state directly and request a render.
 */
final readonly class DebugSessionCallbacks
{
    /**
     * @param (\Closure(string): void)|null         $onStopped    the debuggee paused (reason: 'breakpoint', 'step', …)
     * @param (\Closure(): void)|null               $onContinued  execution resumed
     * @param (\Closure(string, string): void)|null $onOutput     debuggee output (category, text)
     * @param (\Closure(): void)|null               $onTerminated the session ended (fired exactly once)
     * @param (\Closure(string): void)|null         $onError      the adapter failed to start or launch
     */
    public function __construct(
        public ?\Closure $onStopped = null,
        public ?\Closure $onContinued = null,
        public ?\Closure $onOutput = null,
        public ?\Closure $onTerminated = null,
        public ?\Closure $onError = null,
    ) {}
}
