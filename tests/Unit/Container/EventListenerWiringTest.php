<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Container\IdeContainerFactory;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Plugin\PluginEvents;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Pins the kernel-less event-dispatcher wiring: the AsEventListener
 * autoconfiguration and RegisterListenersPass are hand-registered in
 * config/services.php, so this is the test that fails if they drift.
 */
final class EventListenerWiringTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PORTA_CACHE_DIR', 'PORTA_CONTAINER_CACHE'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('PORTA_CONTAINER_CACHE=off'); // exercise the compiled builder deterministically
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv(false === $value ? $key : $key.'='.$value);
        }
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $container = new IdeContainerFactory()->build('/tmp/porta-event-wiring', 'en', true, 'dark');
        // Materializing the listener map resolves listener services, some of
        // which depend on the synthetics Ide normally sets right after build.
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10));
        $container->set(Tui::class, $tui);
        $container->set(Renderer::class, new Renderer(new StyleSheet()));
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator);
        $dispatcher = $locator->get(EventDispatcherInterface::class);
        \assert($dispatcher instanceof EventDispatcherInterface);

        return $dispatcher;
    }

    public function testTheDispatcherResolvesThroughTheLocatorAsASharedInstance(): void
    {
        $container = new IdeContainerFactory()->build('/tmp/porta-event-wiring', 'en', true, 'dark');
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator);

        $dispatcher = $locator->get(EventDispatcherInterface::class);

        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
        $this->assertSame($dispatcher, $locator->get(EventDispatcherInterface::class), 'one shared bus');
    }

    public function testRuntimeListenersRegisterAndReceiveDispatches(): void
    {
        $dispatcher = $this->dispatcher();
        $received = [];
        $dispatcher->addListener(\stdClass::class, static function (object $event) use (&$received): void {
            $received[] = $event;
        });

        $event = new \stdClass();
        $dispatcher->dispatch($event);

        $this->assertSame([$event], $received);
    }

    public function testTheCompiledListenerMapWiresTheBufferEvents(): void
    {
        $dispatcher = $this->dispatcher();

        foreach ([BufferOpened::class, BufferSaved::class, BufferSwitched::class] as $event) {
            $this->assertNotEmpty($dispatcher->getListeners($event), $event.' has compiled listeners');
        }
    }

    public function testThePluginBridgeObservesLast(): void
    {
        $dispatcher = $this->dispatcher();

        foreach ([BufferOpened::class, BufferSaved::class, BufferSwitched::class] as $event) {
            $listeners = $dispatcher->getListeners($event);
            $last = end($listeners);
            \assert(\is_array($last));
            $this->assertInstanceOf(PluginEvents::class, $last[0], $event.': third-party hooks run after every core listener');
        }
    }
}
