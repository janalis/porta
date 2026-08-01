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
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Event\LocaleChanged;
use Porta\Event\PluginObservable;
use Porta\Event\ThemeChanged;
use Porta\Highlight\HighlightThemeListener;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Workbench\ChromeRefreshListener;
use Porta\Workbench\Menu\MenuLayoutRefreshListener;
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

    public function testNoCoreListenerSitsWhereAPluginWould(): void
    {
        // The bridge used to BE this pin: it was the compiled -100 listener you
        // could assert came last. Plugin listeners are added at runtime now, so
        // the contract has to be stated the other way round — on the events a
        // plugin can actually reach, nothing compiled may sit at or below the
        // priority plugins get, or a core listener would silently run after
        // third-party code.
        //
        // Scoped to the observable set on purpose: ActiveBufferTracker sits at
        // exactly -100 on BufferSwitched, which is not observable, and that is
        // precisely how it manages to run before anything watching the event it
        // derives.
        //
        // STRICTLY below, not at: ListenerPriority::CHROME_SWEEP shares
        // PLUGIN's rung, which the ladder now says out loud — neither depends
        // on the other, so their relative order is registration order and
        // nothing reads it.
        //
        // One documented exception: ListenerPriority::CACHE_DROP, so a backend
        // that bakes theme colours rebuilds BEFORE the caches it feeds are
        // dropped.
        $dispatcher = $this->dispatcher();
        $allowed = [ThemeChanged::class => HighlightThemeListener::class];

        foreach ($this->observableEvents() as $event) {
            foreach ($dispatcher->getListeners($event) as $listener) {
                // A closure IS a plugin listener — the bundled plugins in the
                // fixture home register on this bus now, which is the thing
                // this refactor did.
                if (!\is_array($listener) || $dispatcher->getListenerPriority($event, $listener) >= PluginManager::PLUGIN_PRIORITY) {
                    continue;
                }
                $this->assertSame(
                    $allowed[$event] ?? null,
                    $listener[0]::class,
                    \sprintf('%s: a core listener at %d runs after plugin listeners', $event, (int) $dispatcher->getListenerPriority($event, $listener)),
                );
            }
        }
    }

    /**
     * The events a plugin may listen to.
     *
     * @return list<class-string>
     */
    private function observableEvents(): array
    {
        $events = [];
        foreach (glob(\dirname(__DIR__, levels: 3).'/src/Event/*.php') as $file) {
            $class = 'Porta\\Event\\'.basename($file, suffix: '.php');
            if (is_a($class, PluginObservable::class, allow_string: true) && class_exists($class)) {
                $events[] = $class;
            }
        }

        return $events;
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

    /** @param array<int, mixed> $listeners */
    private function indexOf(array $listeners, string $class): int
    {
        foreach (array_values($listeners) as $index => $entry) {
            if (\is_array($entry) && $entry[0] instanceof $class) {
                return $index;
            }
        }

        return \PHP_INT_MAX;
    }

    /**
     * The other two live-setting events. MenuLayoutRefreshListener does not
     * listen to these (no menu relabel needed), so there is no "first"
     * contract — only the -100 sweep-last one.
     */
    public function testCursorAndTabPositionEventsEndInTheChromeSweep(): void
    {
        $dispatcher = $this->dispatcher();

        foreach ([\Porta\Event\CursorShapeChanged::class, \Porta\Event\TabBarPositionChanged::class] as $event) {
            $listeners = $dispatcher->getListeners($event);
            $this->assertNotEmpty($listeners, $event.' has compiled listeners');
            $last = end($listeners);
            \assert(\is_array($last), description: 'listener entries are [service, method] pairs');
            $this->assertInstanceOf(ChromeRefreshListener::class, $last[0], $event.': the chrome sweep runs last');
        }
    }

    public function testTheLifecycleMomentsAreOrdinaryEvents(): void
    {
        // Dispatched by Ide at two fixed positions in run(); nothing in core
        // listens, which is the point — they exist for observers, and a plugin
        // registers on them at runtime like any other listener.
        $dispatcher = $this->dispatcher();

        foreach ([IdeReady::class, IdeShuttingDown::class] as $event) {
            $this->assertSame([], $dispatcher->getListeners($event), $event.' is observed, not reacted to');
        }
    }

    public function testEveryEventCoreDispatchesHasSomewhereToLand(): void
    {
        // The failure this exists for: a listener is COMPILED from an
        // attribute, so forgetting one does not break a build or throw — the
        // reaction simply never happens, and the suite only notices if some
        // test happened to cover that path. Thirty-odd events arrived in one
        // round; this is what makes the thirty-first safe.
        //
        // An event may legitimately have no compiled listener when it exists
        // for OBSERVERS — plugins register at runtime — so those are pinned by
        // name rather than waved through by category.
        $observedOnlyAtRuntime = [
            'ActiveBufferChanged', // plugins' onBufferSwitched
            'IdeReady',
            'IdeShuttingDown',
        ];

        $dispatcher = $this->dispatcher();
        $unheard = [];
        foreach (glob(\dirname(__DIR__, levels: 3).'/src/Event/*.php') as $file) {
            $name = basename($file, suffix: '.php');
            $class = 'Porta\\Event\\'.$name;
            if (!class_exists($class) || \in_array($name, $observedOnlyAtRuntime, strict: true)) {
                continue;
            }
            if ([] === $dispatcher->getListeners($class)) {
                $unheard[] = $name;
            }
        }

        $this->assertSame([], $unheard, 'dispatched into silence — a missing #[AsEventListener], or an event nobody needs');
    }

    public function testACommandEventHasExactlyOnePerformer(): void
    {
        // The naming rule, made enforceable. An event is a FACT when it is true
        // at the moment it is dispatched (BufferSaved — the write happened) and
        // a COMMAND when it becomes true only if a listener acts
        // (DockTabCloseRequested — the tab is still open). Commands carry the
        // `…Requested` suffix.
        //
        // A command with two performers is a design error: two listeners would
        // both close the tab, or race to. A fact may have any number, which is
        // the whole point of the bus — so the assertion is deliberately
        // one-directional.
        $dispatcher = $this->dispatcher();

        foreach (glob(\dirname(__DIR__, levels: 3).'/src/Event/*Requested.php') as $file) {
            $class = 'Porta\\Event\\'.basename($file, suffix: '.php');
            if (!class_exists($class)) {
                continue;
            }
            $this->assertCount(
                1,
                $dispatcher->getListeners($class),
                $class.': a command names one thing to do, so exactly one listener may do it',
            );
        }
    }
}
