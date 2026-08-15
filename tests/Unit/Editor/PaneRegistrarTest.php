<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorPanes;
use Porta\Editor\PaneRegistrar;
use Porta\Editor\PaneServices;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Support\RevoltScheduler;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Tests\Support\TestPane;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Plugin pane registrations become live panes in the core registry — once, at the ctor tail. */
final class PaneRegistrarTest extends TestCase
{
    private PluginManager $manager;
    private EditorPanes $registry;

    private function drain(): PaneRegistrar
    {
        $this->registry = new EditorPanes();

        return new PaneRegistrar(
            $this->manager,
            $this->registry,
            Styles::dark(),
            new Translator('en'),
            new RevoltScheduler(),
            new RecordingViewerTabHandler(),
        );
    }

    private function commit(\Closure $factory, string $plugin = 'demo', string $id = 'demo'): void
    {
        $settings = new PluginSettings('/p', []);
        $this->manager = new PluginManager(new EventDispatcher(), $settings);
        $api = new PluginApi($plugin, $this->manager, '/p', $settings);
        $api->registerPane($id, $factory, static fn (string $path): string => 'payload:'.$path);
        $this->manager->commit($api);
    }

    public function testAFactoryBecomesARegisteredPaneWithItsLoader(): void
    {
        $pane = new TestPane('demo');
        $this->commit(static fn (PaneServices $services): EditorPaneWidget => $pane);

        $this->drain()->register();

        $this->assertSame($pane, $this->registry->paneFor('demo'));
        $loader = $this->registry->loaderFor('demo');
        $this->assertNotNull($loader);
        $this->assertSame('payload:/p/a.demo', $loader('/p/a.demo'));
        $this->assertNotNull(Probe::get($pane, 'handler'), 'the pane is handed the tab handler on the way in');
    }

    public function testThePaneReceivesTheServicesItCannotAutowire(): void
    {
        $seen = null;
        $this->commit(static function (PaneServices $services) use (&$seen): EditorPaneWidget {
            $seen = $services;

            return new TestPane('demo');
        });

        $this->drain()->register();

        $this->assertInstanceOf(PaneServices::class, $seen);
        $this->assertInstanceOf(Styles::class, $seen->styles);
        $this->assertSame('Open', $seen->translator->trans('Open'), 'a live translator, not a stub');
        $this->assertInstanceOf(RecordingViewerTabHandler::class, $seen->tabs);
    }

    public function testAFactoryThatReturnsSomethingElseIsRefused(): void
    {
        // The plugin boundary types the factory as a bare Closure so the plugin
        // tier never names a widget class; the check therefore happens HERE, on
        // what comes back, and reads like any other plugin fault.
        $this->commit(static fn (PaneServices $services): string => 'not a pane');

        $this->drain()->register();

        $this->assertNull($this->registry->paneFor('demo'));
        $this->assertStringContainsString('EditorPaneWidget', $this->manager->warnings()[0]->params['%message%'] ?? '');
    }

    public function testAThrowingFactoryCostsOnlyItsOwnPane(): void
    {
        // A pane that cannot be built leaves the id unanswered, which is the
        // same state as an id no pane ever claimed: the file opens as text.
        $this->commit(static fn (PaneServices $services): EditorPaneWidget => throw new \RuntimeException('no pane today'));

        $this->drain()->register();

        $this->assertNull($this->registry->paneFor('demo'));
        $this->assertNotSame([], $this->manager->warnings(), 'the failure is reported, not swallowed');
    }
}
