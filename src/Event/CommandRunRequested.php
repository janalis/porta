<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Run a command by its id — a core action (`ide_save`) or a plugin's
 * (`local-history:recent_changes`) — as the palette would.
 *
 * A command, so exactly one listener performs it
 * ({@see \Porta\Workbench\RequestedCommands}). Observable so a plugin can
 * {@see \Porta\Plugin\Api\PluginApi::emit()} it: a plugin reaching another
 * plugin's verb has no other way to, and should not have one that ties the
 * two together. With nothing answering to the id — its plugin disabled or
 * absent — the user is told so.
 */
final readonly class CommandRunRequested implements PluginObservable
{
    public function __construct(
        public string $command,
    ) {}
}
