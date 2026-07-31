<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The IDE is up: layout applied, every service wired, the loop about to run —
 * and no buffer opened yet, so setup done here lands before the first
 * {@see BufferOpened} rather than racing it.
 *
 * The project root and nothing else. Not the container, not the service
 * locator: an event is a fact, and handing observers the service graph through
 * one would make every service reachable from a listener.
 */
final readonly class IdeReady implements PluginObservable
{
    public function __construct(
        public string $projectRoot,
    ) {}
}
