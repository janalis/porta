<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Command\DebugCommands;
use Porta\Command\FileCommands;
use Porta\Command\PluginCommandProvider;
use Porta\Command\SearchCommands;
use Porta\Command\ViewCommands;
use Porta\Command\WorkbenchCommands;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * CommandRegistryPopulator runs the `porta.command_provider`-tagged providers in
 * #[AsTaggedItem] priority order (higher first). That order is contractual:
 * registration order IS the palette display order, and core registers before
 * plugins so core wins key collisions. A new provider that forgets the tag, or a
 * priority regression, would silently reorder the palette / flip that precedence.
 * This test pins the tagged set AND the priority order so both fail loudly here.
 *
 * Unlike #[AsEager], the priority lives on #[AsTaggedItem] (the tag itself is
 * added attribute-less by registerForAutoconfiguration), so membership comes from
 * the tag and the order is read by reflecting each class's #[AsTaggedItem].
 */
final class CommandProviderOrderTest extends ContainerTestCase
{
    public function testCommandProviderTagIsExactlyTheExpectedProvidersInContractualOrder(): void
    {
        $container = $this->buildContainer();

        $priorities = [];
        foreach (array_keys($container->findTaggedServiceIds('porta.command_provider')) as $id) {
            $attributes = new \ReflectionClass($id)->getAttributes(AsTaggedItem::class);
            self::assertCount(1, $attributes, $id.' must declare its palette order with #[AsTaggedItem]');
            $priorities[$id] = (int) $attributes[0]->newInstance()->priority;
        }
        arsort($priorities);
        $actual = array_keys($priorities);

        $expected = [
            WorkbenchCommands::class, // 700
            DebugCommands::class, // 600
            PluginCommandProvider::class, // 500 — core (above) wins key collisions over plugins
            FileCommands::class, // 400
            SearchCommands::class, // 300
            ViewCommands::class, // 200
        ];

        self::assertSame($expected, $actual, 'every command provider must carry #[AsTaggedItem] with its contractual priority');
    }
}
