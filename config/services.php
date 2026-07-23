<?php

declare(strict_types=1);

use Porta\Bootstrap\WidgetHooks;
use Porta\Command\CommandProvider;
use Porta\Command\CommandRegistryPopulator;
use Porta\Config\CommandRegistry;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\LayoutStore;
use Porta\Config\SettingsController;
use Porta\Config\SettingsGitController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Container\AsWidget;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DapClientFactory;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugController;
use Porta\Debug\DebugSessionState;
use Porta\Debug\DebugStatePersistence;
use Porta\Debug\EditorDecorations;
use Porta\Debug\ProcessDapClientFactory;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\DiagnosticsSink;
use Porta\Editor\EditorFileActions;
use Porta\Editor\FileOpener;
use Porta\Git\GitCommands;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitRemote;
use Porta\Git\GitRemoteCommands;
use Porta\Git\GitSettings;
use Porta\Git\GitViewController;
use Porta\Help\HelpViewerActions;
use Porta\Highlight\ColorMode;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\Highlight\TerminalThemeFactory;
use Porta\History\LocalHistory;
use Porta\History\LocalHistoryController;
use Porta\History\SessionRestorer;
use Porta\History\SessionStore;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Image\ImageViewerActions;
use Porta\Input\GitMouseRouter;
use Porta\Input\HitTester;
use Porta\Input\HoverRouter;
use Porta\Input\InputDispatcher;
use Porta\Input\MouseRouter;
use Porta\Input\SettingsMouseRouter;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\PaneResizer;
use Porta\Layout\ResponsiveLayout;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\CompletionController;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspLog;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\WorkspaceEditor;
use Porta\Overlay\CompositeFloatingRegions;
use Porta\Overlay\FindModalPresenter;
use Porta\Overlay\PickerModalPresenter;
use Porta\Overlay\FloatingRegions;
use Porta\Overlay\MenuOverlayController;
use Porta\Overlay\MenuOverlayPanes;
use Porta\Overlay\MenuOverlayPanesFactory;
use Porta\Overlay\WelcomeModalController;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginHost;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginLifecycle;
use Porta\Plugin\PluginLifecycleOps;
use Porta\Plugin\PluginLoader;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginRuntime;
use Porta\Plugin\PluginSettings;
use Porta\Plugin\ToolInstaller;
use Porta\Run\RunConfigs;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchEverywhereFactory;
use Porta\Search\TokenSpanProvider;
use Porta\Search\ProjectSearchController;
use Porta\Search\ResultContextProvider;
use Porta\Search\SearchActions;
use Porta\Search\SearchUiWiring;
use Porta\Search\TestNavigator;
use Porta\Settings\LiveSettingsApplier;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsViewController;
use Porta\Support\Clipboard;
use Porta\Support\DocsUrlOpener;
use Porta\Support\FreezeWatchdog;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Ui\BidiTui;
use Porta\Ui\BottomDock;
use Porta\Ui\ChromeInvalidator;
use Porta\Ui\ContextMenuOpener;
use Porta\Ui\CursorStyle;
use Porta\Ui\EditorArea;
use Porta\Ui\FocusCoordinator;
use Porta\Ui\MenuRebuilder;
use Porta\Ui\NotificationLog;
use Porta\Ui\Notifier;
use Porta\Ui\PaneSegmenter;
use Porta\Ui\StatusBarProvider;
use Porta\Ui\TabListProvider;
use Porta\Ui\TerminalStateRefresher;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\Themes;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\BufferNavigationHandler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\EditorCompletionHandler;
use Porta\Widget\EditorFileActionHandler;
use Porta\Widget\EditorNavigationHandler;
use Porta\Widget\EditorRefactorHandler;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\SeparatorWidget;
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
use Symfony\Component\Tui\Widget\ContainerWidget;
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

    // Command providers register in #[AsTaggedItem] priority order (higher
    // first) — the order is contractual: registration order IS the palette
    // display order, and core registers before plugins so core wins key
    // collisions.
    $builder->registerForAutoconfiguration(CommandProvider::class)->addTag('porta.command_provider');

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
        ->bind('$ansi', env('PORTA_ANSI')->bool())
        ->bind('$lspCommand', env('default::PORTA_LSP_COMMAND'))
        ->bind('$lspDisabled', env('PORTA_LSP_DISABLED')->bool())
        ->bind('$docsDir', env('PORTA_DOCS_DIR'))
        ->bind('$gitPath', env('default::PORTA_GIT_PATH')) // '' resolves to null (auto-discover)
        ->bind(ContainerWidget::class.' $editorBody', service('porta.widget.editor_body'))
        ->bind(ContainerWidget::class.' $main', service('porta.widget.main'))
        ->bind(ContainerWidget::class.' $settingsArea', service('porta.widget.settings_area'))
        ->bind(ContainerWidget::class.' $settingsContent', service('porta.widget.settings_content'))
        ->bind(ContainerWidget::class.' $gitArea', service('porta.widget.git_area'))
        ->bind(ContainerWidget::class.' $gitLeft', service('porta.widget.git_left'))
        // The scoped Search Everywhere instances behind Ctrl+P / palette / go-to-symbol.
        ->bind(SearchEverywhereController::class.' $goToFile', service('porta.go_to_file'))
        ->bind(SearchEverywhereController::class.' $commandPalette', service('porta.command_palette'))
        ->bind(SearchEverywhereController::class.' $goToSymbol', service('porta.go_to_symbol'));

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
    // Modal region first, menus after — later regions win the segmenter's
    // per-cell owner pass, matching the paint order (menus above the modal).
    $services->alias(FloatingRegions::class, CompositeFloatingRegions::class);

    // The app event bus. The id must be exactly 'event_dispatcher' — the
    // RegisterListenersPass hard-codes it. Emitters autowire the PSR interface
    // (dispatch() only); Ide autowires the component interface for its runtime
    // addListener() registrations on hand-built widgets.
    $services->set('event_dispatcher', EventDispatcher::class);
    $services->alias(EventDispatcherInterface::class, 'event_dispatcher');
    $services->alias(PsrEventDispatcherInterface::class, 'event_dispatcher');

    // The IDE color scheme: the active theme's tui Styles per Role, one
    // swappable holder (built from the porta.ansi/porta.theme parameters) so
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

    // Which highlighting backend serves which language. The preferences come
    // from the top-level `highlight` block; the plugin-supplied backends come
    // from PluginManager through the interface Porta\Highlight owns, so the
    // highlighting subsystem never depends on Porta\Plugin.
    $services
        ->set(HighlightPreferences::class)
        ->factory([HighlightPreferences::class, 'fromConfig'])
        ->args([env('PORTA_HIGHLIGHT')->json()]);
    $services->alias(SyntaxHighlightProviderSource::class, PluginManager::class);
    $services->alias(TokenSpanProvider::class, PluginManager::class);

    // The single public boundary: Ide resolves every service through this
    // locator, so the entries stay private (alive because referenced) and the
    // container's public surface is exactly this id plus the synthetics.
    // Widget ids and flex styles live on the widget classes as #[AsWidget].
    $locatorEntries = [];
    foreach ([
        DocsUrlOpener::class,
        EditorArea::class,
        WorkspaceViews::class,
        ChromeInvalidator::class,
        GitRefresher::class,
        Translator::class,
        GitInfo::class,
        SessionStore::class,
        BufferManager::class,
        WorkspaceEditor::class,
        CompletionController::class,
        BottomDock::class,
        HitTester::class,
        LayoutController::class,
        NotificationLog::class,
        FreezeWatchdog::class,
        Notifier::class,
        CodeEditorWidget::class,
        ImageViewerWidget::class,
        HelpViewerWidget::class,
        StatusBarWidget::class,
        ResultsPanelWidget::class,
        DebugPanelWidget::class,
        DockTabsWidget::class,
        TerminalPanelWidget::class,
        FileTreeWidget::class,
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
        TabListProvider::class,
        BreadcrumbTrailProvider::class,
        FocusCoordinator::class,
        PluginHost::class,
        Keybindings::class,
        LspSessionPool::class,
        LspLog::class,
        CommandRegistry::class,
        BufferService::class,
        BufferNavigator::class,
        EditorFileActions::class,
        SessionRestorer::class,
        SearchActions::class,
        WorkbenchActions::class,
        LspBootstrapper::class,
        HelpViewerActions::class,
        ImageViewerActions::class,
        LocalHistoryController::class,
        GitCommands::class,
        GitRemoteCommands::class,
        SearchEverywhereController::class,
        DebugController::class,
        DebugStatePersistence::class,
        StatusBarProvider::class,
        SettingsController::class,
        SettingsLanguageController::class,
        SettingsViewController::class,
        OnboardingState::class,
        WelcomeModalController::class,
        FindModalPresenter::class,
        PickerModalPresenter::class,
        PluginLifecycle::class,
        MenuRebuilder::class,
        GitViewController::class,
        ResponsiveLayout::class,
        InputDispatcher::class,
        NavigationController::class,
        RefactorController::class,
        RunController::class,
        SearchUiWiring::class,
        ContextMenuOpener::class,
        CommandRegistryPopulator::class,
        EditorCallbacks::class,
        PluginRuntime::class,
        WidgetHooks::class,
        TerminalStateRefresher::class,
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

    // The plugin subsystem triple is built by PluginHost (its scalar inputs
    // come from IdeConfig + the environment); expose the members as services.
    $services->set(PluginManager::class)->factory([service(PluginHost::class), 'manager']);
    $services->set(PluginLoader::class)->factory([service(PluginHost::class), 'loader']);
    $services->set(PluginSettings::class)->factory([service(PluginHost::class), 'settings']);

    // The installer resolves its plugin dirs and git binary from the
    // environment at construction — a static named constructor owns that.
    $services->set(PluginInstaller::class)->factory([PluginInstaller::class, 'create']);
    $services->set(ToolInstaller::class)->factory([ToolInstaller::class, 'create']);

    // Duplicate-typed widgets from the composite tree: exposed per getter so
    // typed-named binds (below, in _defaults) can feed them to consumers.
    $services->set('porta.widget.editor_body', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'editorBody']);
    $services->set('porta.widget.main', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'main']);
    $services->set('porta.widget.settings_area', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'settingsArea']);
    $services->set('porta.widget.settings_content', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'settingsContent']);
    $services->set('porta.widget.git_area', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'gitArea']);
    $services->set('porta.widget.git_left', ContainerWidget::class)->factory([service(WorkspaceLayout::class), 'gitLeft']);
    $services->set('porta.widget.separator', SeparatorWidget::class)->factory([service(WorkspaceLayout::class), 'separator']);
    $services->set('porta.widget.git_separator', SeparatorWidget::class)->factory([service(WorkspaceLayout::class), 'gitSeparator']);
    $services->set('porta.widget.settings_separator', SeparatorWidget::class)->factory([service(WorkspaceLayout::class), 'settingsSeparator']);

    // The mouse routers (and the hover router split out of the main one) share
    // the parameter name $separator with different instances — binds cannot
    // disambiguate, explicit args can. HoverRouter drives the main sidebar
    // divider, the same instance MouseRouter used before the split.
    $services->set(MouseRouter::class)->arg('$separator', service('porta.widget.separator'));
    $services->set(HoverRouter::class)->arg('$separator', service('porta.widget.separator'));
    $services->set(GitMouseRouter::class)->arg('$separator', service('porta.widget.git_separator'));
    $services->set(SettingsMouseRouter::class)->arg('$separator', service('porta.widget.settings_separator'));

    // BufferService is both interface seams' implementation.
    $services->alias(FileOpener::class, BufferService::class);
    $services->alias(BufferReopener::class, BufferService::class);
    $services->alias(DiagnosticsSink::class, BufferService::class);
    $services->alias(EditorAreaPanes::class, PaneLayout::class);
    $services->alias(PluginLifecycleOps::class, PluginLifecycle::class);
    $services->alias(LanguageServerProvider::class, PluginManager::class);

    // The editor's outbound handler groups — each interface has one impl.
    $services->alias(EditorFileActionHandler::class, EditorFileActions::class);
    $services->alias(EditorNavigationHandler::class, NavigationController::class);
    $services->alias(EditorCompletionHandler::class, CompletionController::class);
    $services->alias(EditorRefactorHandler::class, RefactorController::class);
    $services->alias(BufferNavigationHandler::class, BufferNavigator::class);

    // Per-project config arrays travel JSON-encoded through the env channel
    // (seeded by IdeContainerFactory), so these defs stay dump-safe. Args, not
    // binds: $overrides means languages in one controller, lspServers in the other.
    $services->set(DebugAdapters::class)->arg('$languages', env('PORTA_LANGUAGES')->json());
    $services->set(RunConfigs::class)->arg('$languages', env('PORTA_LANGUAGES')->json());
    $services->set(SettingsController::class)->arg('$settings', env('PORTA_SETTINGS')->json());
    $services->set(GitSettings::class)->arg('$settings', env('PORTA_SETTINGS')->json());
    $services->set(SettingsGitController::class)->arg('$settings', env('PORTA_SETTINGS')->json());
    $services->set(SettingsLanguageController::class)->arg('$overrides', env('PORTA_LANGUAGES')->json());
    $services->set(SettingsLspController::class)->arg('$overrides', env('PORTA_LSP_SERVERS')->json());
    $services
        ->set(PluginLifecycle::class)
        ->arg('$disabledPlugins', env('PORTA_DISABLED_PLUGINS')->json())
        ->arg('$enabledPlugins', env('PORTA_ENABLED_PLUGINS')->json());

    // The Search Everywhere modal aggregates per-source hooks; its factory
    // owns them (see SearchEverywhereFactory).
    $services->set(SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'create']);
    // Scoped instances behind Ctrl+P / palette / go-to-symbol; the binds feed them to their consumers.
    $services->set('porta.go_to_file', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createGoToFile']);
    $services->set('porta.command_palette', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createCommandPalette']);
    $services->set('porta.go_to_symbol', SearchEverywhereController::class)->factory([service(SearchEverywhereFactory::class), 'createGoToSymbol']);

    // Synthetic infrastructure: external (Renderer/Tui), closure-wired objects
    // the container can't build, or values that exist before the container does
    // (IdeConfig steers the container's own env parameters). Ide constructs
    // these and ->set()s the instances right after each is created; declared
    // here so services can depend on them by type.
    foreach ([Renderer::class, Tui::class, BidiTui::class, IdeConfig::class] as $syntheticId) {
        $services->set($syntheticId)->synthetic();
    }
};
