<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Debug\DebugController;

/**
 * The debugger, as a plugin sees it: whether a session runs, where it is
 * paused, its stack and each frame's variables — and the verbs the Run menu
 * steps it with.
 *
 * Reached through {@see Runs::debugger()}, `$api->runs()->debugger()`. Listen to
 * {@see \Porta\Event\DebugStatusChanged} with {@see PluginApi::on()} to know
 * when to read it again: it fires as a session starts, pauses, resumes and
 * ends. Paths are absolute, lines 0-based.
 */
final readonly class Debugger
{
    public function __construct(
        private DebugController $debug,
    ) {}

    /** `idle` when no session runs, else `running` or `paused`. */
    public function status(): string
    {
        return match (true) {
            $this->debug->isPaused() => 'paused',
            $this->debug->isActive() => 'running',
            default => 'idle',
        };
    }

    /** @return array{path: string, line: int}|null where it is paused */
    public function stoppedAt(): ?array
    {
        return $this->debug->stoppedAt();
    }

    /**
     * The paused stack, innermost first; empty unless paused.
     *
     * @return list<array{id: int, name: string, path: string, line: int}>
     */
    public function frames(): array
    {
        return $this->debug->frames();
    }

    /**
     * A paused frame's scopes and their variables, delivered once all have
     * arrived from the adapter; empty unless paused.
     *
     * @param \Closure(list<array{scope: string, variables: list<array{name: string, value: string, reference: int}>}>): void $deliver
     */
    public function variables(int $frameId, \Closure $deliver): void
    {
        $this->debug->frameVariables($frameId, $deliver);
    }

    /**
     * What a variable holds — an array's items, an object's properties — by
     * the non-zero `reference` it was delivered with; empty unless paused.
     *
     * @param \Closure(list<array{name: string, value: string, reference: int}>): void $deliver
     */
    public function children(int $reference, \Closure $deliver): void
    {
        $this->debug->children($reference, $deliver);
    }

    /** Resume a paused session. */
    public function continue(): void
    {
        $this->debug->continueRun();
    }

    /** Stop a running session where it is; the next `DebugStatusChanged` says where. */
    public function pause(): void
    {
        $this->debug->pause();
    }

    public function stepOver(): void
    {
        $this->debug->stepOver();
    }

    public function stepIn(): void
    {
        $this->debug->stepIn();
    }

    public function stepOut(): void
    {
        $this->debug->stepOut();
    }

    /** End the session. */
    public function stop(): void
    {
        $this->debug->stop();
    }
}
