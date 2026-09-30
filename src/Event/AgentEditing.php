<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * An agent is about to change a file for the first time since it connected,
 * and this is what the file held before it did — the open buffer's text,
 * unsaved edits included. A FACT, for recovery: `local-history` keeps it as a
 * snapshot, so the version before any agent touched the file is always one
 * restore away, whatever the undo stack has seen since.
 */
final readonly class AgentEditing implements PluginObservable
{
    public function __construct(
        public string $path,
        public string $previousText,
    ) {}
}
