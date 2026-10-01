<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Someone else came to work on this project — another Porta on the same
 * folder or the same server directory, or a plugin's collaborator — and is in
 * the roster now. A FACT; `$via` says how they reach the project (`local`,
 * `ssh`, `ftp`, or the name of the plugin that brought them).
 */
final readonly class ParticipantJoined implements PluginObservable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $via,
    ) {}
}
