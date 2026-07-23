<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Event\LocaleChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\HighlightThemeListener;
use Porta\Plugin\PluginEvents;
use Porta\Ui\ChromeRefreshListener;
use Porta\Ui\Menu\MenuLayoutRefreshListener;
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
        $container = new IdeContainerFactory()->build('/tmp/porta-event-wiring', IdeConfig::load('/tmp/porta-event-wiring'), Ansi::Enabled);
        // Materializing the listener map resolves listener services, some of
        // which depend on the synthetics Ide normally sets right after build.
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10));
        $container->set(Tui::class, $tui);
        $container->set(Renderer::class, new Renderer(new StyleSheet()));
        $container->set(IdeConfig::class, IdeConfig::load('/tmp/porta-event-wiring'));
        $container->set(
            \Porta\Ui\BidiTui::class,
            new \Porta\Ui\BidiTui(terminal: new VirtualTerminal(columns: 80, rows: 10), keybindings: new \Symfony\Component\Tui\Input\Keybindings([]), renderer: new Renderer(new StyleSheet())),
        );
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'the public boundary is a ServiceLocator');
        $dispatcher = $locator->get(EventDispatcherInterface::class);
        \assert($dispatcher instanceof EventDispatcherInterface, description: 'the locator yields the event dispatcher');

        return $dispatcher;
    }

    public function testTheDispatcherResolvesThroughTheLocatorAsASharedInstance(): void
    {
        $container = new IdeContainerFactory()->build('/tmp/porta-event-wiring', IdeConfig::load('/tmp/porta-event-wiring'), Ansi::Enabled);
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'the public boundary is a ServiceLocator');

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
            \assert(\is_array($last), description: 'a listener entry is a [service, method] pair');
            $this->assertInstanceOf(PluginEvents::class, $last[0], $event.': third-party hooks run after every core listener');
        }
    }

    public function testThemeAndLocaleRefreshersAreCompiledListenersAheadOfTheChromeSweep(): void
    {
        $dispatcher = $this->dispatcher();

        foreach ([ThemeChanged::class, LocaleChanged::class] as $event) {
            $listeners = $dispatcher->getListeners($event);
            $first = reset($listeners);
            $last = end($listeners);
            \assert(\is_array($first) && \is_array($last), description: 'listener entries are [service, method] pairs');
            $this->assertInstanceOf(MenuLayoutRefreshListener::class, $first[0], $event.': the menu/layout refresh runs first');

            // The chrome sweep is last among the *rebuild* listeners, which is
            // what its -100 contract is about. ThemeChanged additionally carries
            // the -200 highlight-cache drop, which must postdate the -100 plugin
            // bridge so a theme-baking backend rebuilds before its caches go.
            $expectedLast = ThemeChanged::class === $event ? HighlightThemeListener::class : ChromeRefreshListener::class;
            $this->assertInstanceOf($expectedLast, $last[0], $event.': the sweep order ends as documented');
            $this->assertLessThanOrEqual(
                $this->indexOf($listeners, $expectedLast),
                $this->indexOf($listeners, ChromeRefreshListener::class),
                $event.': the chrome sweep still runs after every rebuild listener',
            );
        }
    }

    /** @param array<int, array{0: object, 1: string}> $listeners */
    private function indexOf(array $listeners, string $class): int
    {
        foreach (array_values($listeners) as $index => $entry) {
            if ($entry[0] instanceof $class) {
                return $index;
            }
        }

        return \PHP_INT_MAX;
    }
}
