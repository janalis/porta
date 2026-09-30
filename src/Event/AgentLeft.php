<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * An agent is gone — disconnected, or no longer to be shown — and everything
 * {@see AgentActivity} put on screen for it goes at once. A FACT.
 */
final readonly class AgentLeft implements PluginObservable
{
    public function __construct(
        public string $agent,
    ) {}
}
