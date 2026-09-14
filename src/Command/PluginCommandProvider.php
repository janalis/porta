<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Plugin\Runtime\PluginManager;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The plugins' slot in the provider order: after core's key-bound groups
 * (core wins when a plugin default key collides), before the palette groups.
 */
#[AsTaggedItem(priority: 500)]
final readonly class PluginCommandProvider implements CommandProvider
{
    public function __construct(
        private PluginManager $plugins,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $this->plugins->registerCommands($commands);
    }
}
