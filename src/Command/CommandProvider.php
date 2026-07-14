<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;

/**
 * One group of IDE-level commands. Providers register in a fixed order
 * (workbench, debug, plugins, file, search, view, git) because registration
 * order IS the palette display order, and core must register its key-bound
 * commands before plugins so core wins key collisions.
 */
interface CommandProvider
{
    public function register(CommandRegistry $commands): void;
}
