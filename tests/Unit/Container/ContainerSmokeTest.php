<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Config\IdeConfig;
use Porta\Tests\Support\TempProject;
use Porta\Ui\BidiTui;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Resolves every service the compiled container serves — the smoke gate the
 * suite lacked: a definition with a bad argument that no test happens to
 * resolve was invisible until a real boot, and the synthetic-pruning tripwire
 * (config/services.php) was guarded only by "the first E2e test". Here it is
 * one targeted unit test instead: every public id, then every entry behind the
 * `porta.ide` locator (the ide-service surface, mostly private definitions no
 * public id reaches).
 */
final class ContainerSmokeTest extends ContainerTestCase
{
    public function testEveryPublicIdAndEveryLocatorEntryResolves(): void
    {
        // Hermetic plugin loading: resolving Keybindings executes every
        // plugin.php via PluginHost::ensureLoaded(), and the loader's global
        // base must not be this machine's real ~/.porta.
        $home = new TempProject();
        $savedHome = getenv('PORTA_HOME');
        putenv('PORTA_HOME='.$home->dir);

        try {
            $container = $this->buildContainer();

            // The four synthetics Ide::run() would set; doubles of the same shape.
            $renderer = new Renderer(new StyleSheet());
            $container->set(Renderer::class, $renderer);
            $container->set(Tui::class, new Tui(terminal: new VirtualTerminal(columns: 100, rows: 30), keybindings: new Keybindings([]), renderer: $renderer));
            $container->set(BidiTui::class, new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 30), renderer: $renderer));
            $container->set(IdeConfig::class, IdeConfig::load('/tmp/porta-container-test'));

            $resolved = 0;
            $removed = $container->getRemovedIds();
            foreach ($container->getServiceIds() as $id) {
                if (\array_key_exists($id, $removed)) {
                    continue; // private and inlined — reachable only through its consumers
                }
                $this->assertNotNull($container->get($id), \sprintf('service "%s" resolves', $id));
                ++$resolved;
            }

            $locator = $container->get('porta.ide');
            \assert($locator instanceof ServiceLocator, description: 'porta.ide is the ide-service locator');
            foreach (array_keys($locator->getProvidedServices()) as $id) {
                $this->assertNotNull($locator->get($id), \sprintf('porta.ide locator entry "%s" resolves', $id));
                ++$resolved;
            }

            // A floor, so an accidentally-emptied discovery cannot pass as
            // "everything resolved" when almost nothing was registered. ~80
            // roots today (public ids + locator entries); every private
            // definition is inlined into one of them, so constructing the
            // roots constructs the whole reachable graph.
            $this->assertGreaterThan(60, $resolved, 'the sweep saw the real service surface, not a stub of it');
        } finally {
            putenv(false === $savedHome ? 'PORTA_HOME' : 'PORTA_HOME='.$savedHome);
        }
    }
}
