<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The terminal panel's outbound hooks, wired in one call instead of three
 * fluent setters — the {@see \Porta\Widget\Git\DiffViewCallbacks} shape.
 * Every slot is nullable: a bare widget fires nothing.
 */
final readonly class TerminalPanelCallbacks
{
    /**
     * @param (callable(string): void)|null $onInputForward forwards input bytes to the process
     * @param (callable(int): void)|null    $onSessionClosed session index; -1 = the active session
     * @param (callable(int): void)|null    $onSessionCycled +1 next / -1 prev
     */
    public function __construct(
        public mixed $onInputForward = null,
        public mixed $onSessionClosed = null,
        public mixed $onSessionCycled = null,
    ) {}
}
