<?php

declare(strict_types=1);

use Porta\Bootstrap\EagerServices;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Container\AsWidget;
use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Highlight\ColorMode;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\Highlight\TerminalThemeFactory;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Region\CompositeFloatingRegions;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Overlay\State\MenuOverlayPanesFactory;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchEverywhereFactory;
use Porta\Ui\BidiTui;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\Themes;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\ViewerTabHandler;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Tempest\Highlight\TerminalTheme;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Porta's service definitions. Lives outside src/ so this wiring stays off the
 * 100%-line-coverage gate (phpunit's <source> includes only src/). Every
 * service is shared (the DI default), matching the app-lifetime-singleton
 * lifecycle; never use lazy: true — pure overhead for shared app-lifetime
 * services (and no longer even blocked by final: PHP 8.4 lazy objects don't
 * proxy-subclass).
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    // Widgets declare their widget-tree id (and optional flex style) with
    // #[AsWidget]; autoconfiguration turns the attribute into the
    // setId()/setStyle() calls applied after construction.
    $builder->registerAttributeForAutoconfiguration(
        AsWidget::class,
        static function (ChildDefinition $definition, AsWidget $attribute): void {
            $definition->addMethodCall('setId', [$attribute->id]);
            if (null !== $attribute->flex) {
                $style = new Definition(Style::class);
                $style->setArgument('$flex', $attribute->flex);
                $definition->addMethodCall('setStyle', [$style]);
            }
        },
    );

    // #[AsEventListener] methods become kernel.event_listener tags; the
    // RegisterListenersPass compiles them into the dispatcher's listener map
    // (dump-safe addListener calls over service closures — never lazy proxies,
    // and the closure references keep private listener services alive through
    // the removal pass). Kernel-less setup, so both are hand-registered here.
    $builder->registerAttributeForAutoconfiguration(
        AsEventListener::class,
        static function (ChildDefinition $definition, AsEventListener $attribute, \ReflectionClass|\ReflectionMethod $reflector): void {
            $tagAttributes = get_object_vars($attribute);
            if ($reflector instanceof \ReflectionMethod) {
                // FrameworkBundle rejects this contradiction too — the old
                // closure here silently clobbered a declared method: instead.
                if (null !== $attribute->method) {
                    throw new LogicException(\sprintf('#[AsEventListener] on method %s::%s() must not declare method: "%s".', $reflector->class, $reflector->getName(), $attribute->method));
                }
                $tagAttributes['method'] = $reflector->getName();
            }
            $definition->addTag('kernel.event_listener', $tagAttributes);
        },
    );
    $builder->addCompilerPass(new RegisterListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
    // Single-impl Porta\ interface aliases are derived by the LOADER itself:
    // PhpFileLoader::load() ends in registerAliasesForSinglyImplementedInterfaces(),
    // which aliases every interface with exactly one discovered implementation
    // and skips any interface that gains a second one — those stay explicit
    // alias() calls below (FloatingRegions, SyntaxHighlightProviderSource).

    // The porta.* tag attributes (#[AsIdeService], #[AsEager], #[AsChromeRoot])
    // and the tagged interfaces (CommandProvider, SettingsSection) carry their
    // own autoconfiguration as #[AutoconfigureTag] subclasses/uses — Symfony
    // resolves them natively (IS_INSTANCEOF), so nothing registers them here.
    // #[AsWidget] above is the one that cannot: it builds a nested Style
    // definition, inexpressible as a tag.

    $services = $container->services();

    // Private by default: the seven fully-non-service locations are excluded
    // from discovery outright (see the load() below); the remaining non-service
    // classes in MIXED directories (closure-wired controllers, per-buffer
    // objects, VOs beside their services) still register private and are pruned
    // at compile because nothing public references them. Still *fragile* there:
    // if a public service ever gains a dependency on a pruned class, compile
    // fails with a Symfony autowiring error — explicit at boot, caught by the
    // first E2e test. Making defaults public would keep them all and fail
    // autowiring.
    // Per-boot scalars resolve at runtime from $_ENV (seeded by
    // IdeContainerFactory) so the compiled container is project-independent
    // and can be dumped/cached across boots.
    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$rootDir', env('PORTA_ROOT_DIR'))
        ->bind('$locale', env('PORTA_LOCALE'))
        ->bind('$ansi', env('PORTA_ANSI')->bool())
        ->bind('$lspCommand', env('default::PORTA_LSP_COMMAND'))
        ->bind('$lspDisabled', env('PORTA_LSP_DISABLED')->bool())
        ->bind('$docsDir', env('PORTA_DOCS_DIR'));
    // The composite tree's duplicate-typed sub-widgets (editorBody/main/git+
    // settings areas/separators) and the scoped Search Everywhere instances
    // (Ctrl+P / palette / go-to-symbol) are selected at the consumer with
    // #[Container/LayoutSlot('…')] / #[Autowire(service: 'porta.…')] — the
    // param that knows which one it wants names it, so no central binds here.

    // Autoregister every Porta\ class except the orchestrator, the container
    // factory, and the measured 100%-non-service tiers (events, widget-support
    // DTOs/enums/traits, the stateless render tier, the console entrypoint,
    // the JSON grammars (Highlight/Json) — zero container-built classes in
    // each). A class
    // in one of these that ever becomes a real service must be un-excluded
    // here; that edit is the review point. Mixed directories (Overlay/Region,
    // Ui/, Editor/, Lsp/ proper, …) stay discovered and rely on compile
    // pruning as before.
    $services
        ->load('Porta\\', '../src/*')
        ->exclude([
            '../src/Ide.php',
            '../src/Container/',
            '../src/Console/',
            '../src/Event/',
            '../src/Highlight/Json/',
            '../src/Widget/Render/',
            '../src/Widget/Support/',
        ]);

    // The wall clock (symfony/clock). A NativeClock backs Psr\Clock\ClockInterface
    // so time-dependent services autowire it; tests inject a MockClock.
    $services->set(ClockInterface::class, NativeClock::class);

    // The overlay compositor's pane bundle: built by a factory because its
    // widget lists and view-open closures aren't expressible in a dumped
    // definition. The RTL segmenter reads the floating dropdowns through the
    // FloatingRegions seam (fakes in tests).
    $services->set(MenuOverlayPanes::class)->factory([service(MenuOverlayPanesFactory::class), 'create']);
    // Modal region first, menus after — later regions win the segmenter's
    // per-cell owner pass, matching the paint order (menus above the modal).
    $services->alias(FloatingRegions::class, CompositeFloatingRegions::class);


    // Kept explicit on purpose: BidiTui's def is overridden to synthetic AFTER
    // the loader's load-time interface counting, so a natively derived
    // FrameHooks alias here would be a load-order accident — and the presenters'
    // nullable FrameHooks dep fails silently, so this seam must not hang on one.
    $services->alias(FrameHooks::class, BidiTui::class);

    // The app event bus. The id must be exactly 'event_dispatcher' — the
    // RegisterListenersPass hard-codes it. Emitters autowire the PSR interface
    // (dispatch() only); the component-interface alias is for the two consumers
    // that need more than dispatching — PluginManager adds and removes plugin
    // listeners at runtime, and Ide resolves it through the locator key below.
    $services->set('event_dispatcher', EventDispatcher::class)
        ->tag('porta.ide_service', ['key' => EventDispatcherInterface::class]);
    $services->alias(EventDispatcherInterface::class, 'event_dispatcher');
    $services->alias(PsrEventDispatcherInterface::class, 'event_dispatcher');

    // The IDE color scheme: the active theme's tui Styles per Role, one
    // swappable holder (built from the PORTA_ANSI/PORTA_THEME env placeholders) so
    // the theme can swap live; widgets autowire Styles and hold that one
    // holder instance.
    $services
        ->set(Styles::class)
        ->factory([Themes::class, 'createSwitchable'])
        ->args([env('enum:'.ColorMode::class.':PORTA_ANSI'), env('PORTA_THEME')]);

    // The syntax-highlighting theme, likewise a live-swappable holder. Buffers and
    // the editor/diff widgets autowire TerminalTheme via the alias; Ide resolves
    // the holder by id to switch it.
    $services
        ->set(SwitchableTerminalTheme::class)
        ->factory([TerminalThemeFactory::class, 'createSwitchable'])
        ->args([env('enum:'.ColorMode::class.':PORTA_ANSI'), env('PORTA_THEME')]);
    $services->alias(TerminalTheme::class, SwitchableTerminalTheme::class);

    // Which highlighting backend serves which language: HighlightPreferences
    // self-declares its #[Autoconfigure(constructor: 'fromConfig')] + env arg on
    // the class. The plugin-supplied backends come from PluginManager through the
    // interface Porta\Highlight owns, so the subsystem never depends on Porta\Plugin.
    $services->alias(SyntaxHighlightProviderSource::class, PluginManager::class);

    // ViewerTabHandler gained a second implementation when HelpViewerHandler
    // started extending it (the help pane needs the same four tab verbs), so the
    // loader derives no alias and one has to be named. BufferNavigator is the
    // general answer: HelpViewerActions is the help pane's own superset, reached
    // by that widget alone.
    $services->alias(ViewerTabHandler::class, BufferNavigator::class);

    // The single public boundary: Ide resolves every service through this
    // locator, so the entries stay private (alive because referenced) and the
    // container's public surface is exactly this one id.
    // Widget ids and flex styles live on the widget classes as #[AsWidget].
    // Membership is declared on each class with #[AsIdeService] (tag
    // porta.ide_service); the locator is built from that tag, keyed by service
    // id = FQCN so Ide::service(X::class) resolves it. Two members can't carry
    // a class attribute: Keybindings (factory-built, tagged at its def below)
    // and EventDispatcherInterface (an alias — event_dispatcher is tagged with
    // an explicit key at its def). IdeLocatorMembershipTest guards that every
    // Ide::service() target is a member.
    $services
        ->set('porta.ide', ServiceLocator::class)
        ->args([tagged_iterator('porta.ide_service', indexAttribute: 'key')])
        ->tag('container.service_locator')
        ->public();

    // The immutable Keybindings map. Built by a factory because plugin default
    // keys must merge in — resolving it forces the one-time plugin load.
    $services->set(Keybindings::class)->factory([service(KeybindingsFactory::class), 'create'])->tag('porta.ide_service');

    // The plugin subsystem triple is built by PluginHost (its scalar inputs
    // come from IdeConfig + the environment); expose the members as services.
    $services->set(PluginManager::class)->factory([service(PluginHost::class), 'manager']);
    $services->set(PluginLoader::class)->factory([service(PluginHost::class), 'loader']);
    $services->set(PluginSettings::class)->factory([service(PluginHost::class), 'settings']);

    // The installers resolve their plugin dirs and git binary from the
    // environment in a static named constructor, self-declared on each class
    // with #[Autoconfigure(constructor: 'create')].

    // Duplicate-typed widgets from the composite tree: exposed per getter so
    // a consumer can name the one it wants at the parameter with
    // #[LayoutSlot('…')] (see the _defaults comment above — no central binds).
    $services->set('porta.widget.editor_body', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'editorBody']);
    // The main editor area is a chrome root too, but it is a getter service
    // (a bare ContainerWidget, not a class that can carry #[AsChromeRoot]), so
    // it is tagged here — the Keybindings/event_dispatcher exception pattern.
    $services->set('porta.widget.main', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'main'])->tag('porta.chrome_root');
    $services->set('porta.widget.separator', SeparatorWidget::class)->factory([service(WorkspaceLayout::class), 'separator']);

    // The mouse routers (and the hover router split out of the main one) share
    // the parameter name $separator with different instances; each names the one
    // it wants with #[LayoutSlot('…separator')] at the param (HoverRouter drives
    // the main sidebar divider, the same instance MouseRouter used).

    // BufferService is both interface seams' implementation.

    // The editor's outbound handler groups — each interface has one impl.

    // Per-project config arrays travel JSON-encoded through the env channel
    // (seeded by IdeContainerFactory); each consumer names the channel it wants
    // at the param with #[Autowire('%env(json:PORTA_…)%')] — dump-safe (the arg
    // is an env placeholder) and it keeps the $overrides split explicit
    // (SettingsLanguageController ← PORTA_LANGUAGES, SettingsLspController ←
    // PORTA_LSP_SERVERS), so no central args here.

    // The Search Everywhere modal aggregates per-source hooks; its factory
    // owns them (see SearchEverywhereFactory).
    $services->set(SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'create']);
    // Scoped instances behind Ctrl+P / palette / go-to-symbol; each consumer
    // names its instance with #[Autowire(service: 'porta.…')] at the param.
    $services->set('porta.go_to_file', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createGoToFile']);
    $services->set('porta.command_palette', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createCommandPalette']);
    $services->set('porta.go_to_symbol', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createGoToSymbol']);

    // Synthetic infrastructure: external (Renderer/Tui), closure-wired objects
    // the container can't build, or values that exist before the container does
    // (IdeConfig steers the container's own env parameters). Ide constructs
    // these and ->set()s the instances right after each is created; declared
    // here so services can depend on them by type. PRIVATE on purpose (round
    // 12): the dumper emits every synthetic id regardless of visibility, and
    // set() works because the id is synthetic — tests reach them through
    // tests/Support/Services' container fallback. Tripwire: if a private
    // synthetic ever loses its last internal consumer it is compile-removed
    // and set() throws AT BOOT — loud, caught by the first E2e test.
    foreach ([Renderer::class, Tui::class, BidiTui::class, IdeConfig::class] as $syntheticId) {
        // The class is passed explicitly: set(id) alone leaves the definition
        // classless, invisible to every reflection-driven pass.
        $services->set($syntheticId, $syntheticId)->synthetic();
    }
};
