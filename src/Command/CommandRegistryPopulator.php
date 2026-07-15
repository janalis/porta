<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registers every IDE-level command once, by running the tagged providers in
 * priority order (higher first). The order is contractual: registration order
 * IS the palette display order, and core registers its key-bound groups
 * before plugins so core wins key collisions — see each provider's
 * #[AsTaggedItem] priority.
 */
final class CommandRegistryPopulator
{
    /** @param iterable<CommandProvider> $providers */
    public function __construct(
        #[AutowireIterator('porta.command_provider')]
        private readonly iterable $providers,
        private readonly CommandRegistry $commands,
    ) {}

    public function populate(): void
    {
        foreach ($this->providers as $provider) {
            $provider->register($this->commands);
        }
    }
}
