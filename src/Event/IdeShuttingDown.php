<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * First thing in the teardown: every service a listener might touch is still
 * alive, but the event loop has ALREADY stopped.
 *
 * So a listener must finish synchronously — a timer or a subprocess it starts
 * will never run. Persisting something is what this moment is for.
 */
final readonly class IdeShuttingDown implements PluginObservable
{
    public function __construct(
        public string $projectRoot,
    ) {}
}
