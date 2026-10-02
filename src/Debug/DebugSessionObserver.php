<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * How a {@see DapSession} reports back to the IDE. Every method runs on the
 * shared Revolt loop (adapter events arrive there), so implementations mutate
 * UI state directly and request a render.
 */
interface DebugSessionObserver
{
    /** The debuggee paused (reason: 'breakpoint', 'step', …). */
    public function onStopped(string $reason): void;

    /** Execution resumed. */
    public function onContinued(): void;

    /** Debuggee output. */
    public function onOutput(string $category, string $text): void;

    /** The session ended (fired exactly once). */
    public function onTerminated(): void;

    /** The adapter failed to start or launch. */
    public function onError(string $message): void;

    /** The adapter died in the middle of the session (fired instead of onTerminated). */
    public function onCrashed(): void;
}
