<?php

declare(strict_types=1);

use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\LayoutStore;
use Porta\Container\AsWidget;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DapClientFactory;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Debug\ProcessDapClientFactory;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\BufferManager;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\TerminalThemeFactory;
use Porta\History\LocalHistory;
use Porta\History\SessionStore;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Input\HitTester;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\PaneResizer;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\CompletionController;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspLog;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Overlay\FloatingRegions;
use Porta\Overlay\MenuOverlayController;
use Porta\Overlay\MenuOverlayPanes;
use Porta\Overlay\MenuOverlayPanesFactory;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginHost;
use Porta\Search\ProjectSearchController;
use Porta\Search\ResultContextProvider;
use Porta\Search\TestNavigator;
use Porta\Settings\LiveSettingsApplier;
use Porta\Support\Clipboard;
use Porta\Support\DocsUrlOpener;
use Porta\Support\FreezeWatchdog;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Ui\BidiTui;
use Porta\Ui\ChromeInvalidator;
use Porta\Ui\CommandPalette;
use Porta\Ui\CursorStyle;
use Porta\Ui\EditorArea;
use Porta\Ui\FocusCoordinator;
use Porta\Ui\NotificationLog;
use Porta\Ui\Notifier;
use Porta\Ui\Palette;
use Porta\Ui\PaletteFactory;
use Porta\Ui\PanelManager;
use Porta\Ui\PaneSegmenter;
use Porta\Ui\SwitchablePalette;
use Porta\Ui\TabListProvider;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
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
use Tempest\Highlight\TerminalTheme;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Porta's service definitions. Lives outside src/ so this wiring stays off the
 * 100%-line-coverage gate (phpunit's <source> includes only src/). Every
 * service is shared (the DI default), matching the app-lifetime-singleton
 * lifecycle; never use lazy: true — it would proxy-subclass a final class.
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
                $tagAttributes['method'] = $reflector->getName();
            }
            $definition->addTag('kernel.event_listener', $tagAttributes);
        },
    );
    $builder->addCompilerPass(new RegisterListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);

    $services = $container->services();

    // Private by default: autoregistration below relies on the removal pass
    // pruning the ~137 non-service classes (closure-wired controllers, DTOs,
    // value objects, per-buffer objects) that register but nothing public
    // references. Making defaults public would keep them all and fail autowiring.
    // Per-boot scalars resolve at runtime from $_ENV (seeded by
    // IdeContainerFactory) so the compiled container is project-independent
    // and can be dumped/cached across boots.
    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$rootDir', env('PORTA_ROOT_DIR'))
        ->bind('$locale', env('PORTA_LOCALE'))
        ->bind('$ansi', env('PORTA_ANSI')->bool());

    // Autoregister every Porta\ class. Only the orchestrator and the container
    // factory are excluded; everything else that isn't a real service registers
    // private and is pruned at compile.
    $services
        ->load('Porta\\', '../src/*')
        ->exclude([
            '../src/Ide.php',
            '../src/Container/',
        ]);

    // The wall clock (symfony/clock). A NativeClock backs Psr\Clock\ClockInterface
    // so time-dependent services autowire it; tests inject a MockClock.
    $services->set(ClockInterface::class, NativeClock::class);

    // The DAP transport factory: a child process in production, fakes in tests.
    $services->alias(DapClientFactory::class, ProcessDapClientFactory::class);

    // The delay-timer seam: the shared Revolt loop in production, fakes in tests.
    $services->alias(Scheduler::class, RevoltScheduler::class);

    // "The active file" is the editor widget's current path.
    $services->alias(ActiveFileProvider::class, CodeEditorWidget::class);

    // The overlay compositor's pane bundle: built by a factory because its
    // widget lists and view-open closures aren't expressible in a dumped
    // definition. The RTL segmenter reads the floating dropdowns through the
    // FloatingRegions seam (fakes in tests).
    $services->set(MenuOverlayPanes::class)->factory([service(MenuOverlayPanesFactory::class), 'create']);
    $services->alias(FloatingRegions::class, MenuOverlayController::class);

    // The app event bus. The id must be exactly 'event_dispatcher' — the
    // RegisterListenersPass hard-codes it. Emitters autowire the PSR interface
    // (dispatch() only); Ide autowires the component interface for its runtime
    // addListener() registrations on hand-built widgets.
    $services->set('event_dispatcher', EventDispatcher::class);
    $services->alias(EventDispatcherInterface::class, 'event_dispatcher');
    $services->alias(PsrEventDispatcherInterface::class, 'event_dispatcher');

    // The IDE color scheme. The service is a SwitchablePalette (built from the
    // porta.ansi/porta.theme parameters) so Ide can swap the theme live; widgets
    // autowire Palette via the alias and hold that one holder instance. Both the
    // holder id and the alias are public so Ide can resolve them.
    $services
        ->set(SwitchablePalette::class)
        ->factory([PaletteFactory::class, 'createSwitchable'])
        ->args([env('PORTA_ANSI')->bool(), env('PORTA_THEME')]);
    $services->alias(Palette::class, SwitchablePalette::class);

    // The syntax-highlighting theme, likewise a live-swappable holder. Buffers and
    // the editor/diff widgets autowire TerminalTheme via the alias; Ide resolves
    // the holder by id to switch it.
    $services
        ->set(SwitchableTerminalTheme::class)
        ->factory([TerminalThemeFactory::class, 'createSwitchable'])
        ->args([env('PORTA_ANSI')->bool(), env('PORTA_THEME')]);
    $services->alias(TerminalTheme::class, SwitchableTerminalTheme::class);

    // The single public boundary: Ide resolves every service through this
    // locator, so the entries stay private (alive because referenced) and the
    // container's public surface is exactly this id plus the synthetics.
    // Widget ids and flex styles live on the widget classes as #[AsWidget].
    $locatorEntries = [];
    foreach ([
        SwitchablePalette::class,
        SwitchableTerminalTheme::class,
        Clipboard::class,
        DocsUrlOpener::class,
        EditorArea::class,
        WorkspaceViews::class,
        ChromeInvalidator::class,
        GitRefresher::class,
        BreakpointStore::class,
        DebugSessionState::class,
        EditorDecorations::class,
        DapClientFactory::class,
        ProjectSearchController::class,
        ResultContextProvider::class,
        Scheduler::class,
        PluginEvents::class,
        Translator::class,
        GitInfo::class,
        LocalHistory::class,
        SessionStore::class,
        EditorConfigResolver::class,
        BufferManager::class,
        LayoutStore::class,
        WorkspaceEditor::class,
        CompletionController::class,
        PanelManager::class,
        CommandPalette::class,
        HitTester::class,
        LayoutController::class,
        TestNavigator::class,
        ImageDecoder::class,
        CursorStyle::class,
        NotificationLog::class,
        FreezeWatchdog::class,
        Notifier::class,
        GitSidebar::class,
        CodeEditorWidget::class,
        ImageViewerWidget::class,
        HelpViewerWidget::class,
        StatusBarWidget::class,
        ResultsPanelWidget::class,
        DebugPanelWidget::class,
        TerminalPanelWidget::class,
        FileTreeWidget::class,
        ChangesListWidget::class,
        DiffViewWidget::class,
        CommitBoxWidget::class,
        HistoryListWidget::class,
        GitTabsWidget::class,
        SettingsNavWidget::class,
        SettingsFormWidget::class,
        KeybindingsListWidget::class,
        GoToFileWidget::class,
        FindBarWidget::class,
        TabBarWidget::class,
        BreadcrumbsWidget::class,
        MenuBarWidget::class,
        ContextMenu::class,
        WorkspaceLayout::class,
        MenuOverlayController::class,
        PaneSegmenter::class,
        PaneReorder::class,
        PaneLayout::class,
        PaneResizer::class,
        TabListProvider::class,
        BreadcrumbTrailProvider::class,
        LiveSettingsApplier::class,
        FocusCoordinator::class,
        PluginHost::class,
        Keybindings::class,
        LspSessionPool::class,
        LspLog::class,
        FormatOnSave::class,
    ] as $locatorId) {
        $locatorEntries[$locatorId] = service($locatorId);
    }
    $locatorEntries[EventDispatcherInterface::class] = service('event_dispatcher');
    $services
        ->set('porta.ide', ServiceLocator::class)
        ->args([$locatorEntries])
        ->tag('container.service_locator')
        ->public();

    // The immutable Keybindings map. Built by a factory because plugin default
    // keys must merge in — resolving it forces the one-time plugin load.
    $services->set(Keybindings::class)->factory([service(KeybindingsFactory::class), 'create']);

    // Synthetic infrastructure: external (Renderer/Tui), closure-wired objects
    // the container can't build, or values that exist before the container does
    // (IdeConfig steers the container's own env parameters). Ide constructs
    // these and ->set()s the instances right after each is created; declared
    // here so services can depend on them by type.
    foreach ([Renderer::class, Tui::class, BidiTui::class, IdeConfig::class] as $syntheticId) {
        $services->set($syntheticId)->synthetic();
    }
};
