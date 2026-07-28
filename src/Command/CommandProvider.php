<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One group of IDE-level commands. The interface-level `#[AutoconfigureTag]`
 * tags every implementation `porta.command_provider`; providers register in a
 * fixed `#[AsTaggedItem]` priority order (workbench, debug, plugins, file,
 * search, view, git) because registration order IS the palette display order,
 * and core must register its key-bound commands before plugins so core wins
 * key collisions.
 */
#[AutoconfigureTag('porta.command_provider')]
interface CommandProvider
{
    public function register(CommandRegistry $commands): void;
}
