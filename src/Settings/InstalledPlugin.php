<?php

declare(strict_types=1);

namespace Porta\Settings;

/**
 * One installed plugin as the store's Installed tab draws it: its name, what
 * it says it is, and the facts the Plugins page always gave — on or off,
 * where it comes from, the tools it still needs.
 */
final readonly class InstalledPlugin
{
    public function __construct(
        public string $name,
        public string $description,
        public string $facts,
    ) {}
}
