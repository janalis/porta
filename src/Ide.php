<?php

declare(strict_types=1);

namespace Porta;

use Porta\Command\DebugCommands;
use Porta\Command\FileCommands;
use Porta\Command\GitCommandProvider;
use Porta\Command\SearchCommands;
use Porta\Command\ViewCommands;
use Porta\Command\WorkbenchCommands;
use Porta\Config\CommandRegistry;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsDebugController;
use Porta\Config\SettingsKeymapController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Config\SettingsPluginsController;
use Porta\Container\IdeContainerFactory;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DapClientFactory;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugController;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\EditorFileActions;
use Porta\Event\LocaleChanged;
use Porta\Event\ThemeChanged;
use Porta\Git\GitCommands;
use Porta\Git\GitInfo;
use Porta\Git\GitPanes;
use Porta\Git\GitRefresher;
use Porta\Git\GitViewController;
use Porta\Help\HelpViewerActions;
use Porta\History\LocalHistory;
use Porta\History\LocalHistoryController;
use Porta\History\SessionRestorer;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Image\ImageViewerActions;
use Porta\Input\EditorLayoutPanes;
use Porta\Input\GitMouseRouter;
use Porta\Input\HitTester;
use Porta\Input\InputDispatcher;
use Porta\Input\MouseRouter;
use Porta\Input\OverlayPanels;
use Porta\Input\SettingsMouseRouter;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\PaneResizer;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\CompletionController;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspLog;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Overlay\MenuOverlayController;
use Porta\Plugin\PluginHost;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginLifecycle;
use Porta\Plugin\PluginLoader;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginRuntime;
use Porta\Plugin\PluginSettings;
use Porta\Run\RunConfigs;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchEverywhereFactory;
use Porta\Search\ProjectSearchController;
use Porta\Search\ResultContextProvider;
use Porta\Search\SearchActions;
use Porta\Search\SearchUiWiring;
use Porta\Search\TestNavigator;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsViewController;
use Porta\Support\AsyncProcess;
use Porta\Support\BrowserOpener;
use Porta\Support\Clipboard;
use Porta\Support\DocsUrlOpener;
use Porta\Support\FreezeWatchdog;
use Porta\Support\Scheduler;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Tree\TreeOperations;
use Porta\Ui\BidiTui;
use Porta\Ui\ChromeInvalidator;
use Porta\Ui\CommandPalette;
use Porta\Ui\ContextMenuBuilder;
use Porta\Ui\ContextMenuOpener;
use Porta\Ui\EditorArea;
use Porta\Ui\FocusCoordinator;
use Porta\Ui\MenuRebuilder;
use Porta\Ui\NonBlockingTerminal;
use Porta\Ui\NotificationLog;
use Porta\Ui\Notifier;
use Porta\Ui\PanelManager;
use Porta\Ui\ResultsPanelMode;
use Porta\Ui\Role;
use Porta\Ui\StatusBarProvider;
use Porta\Ui\SwitchablePalette;
use Porta\Ui\TabListProvider;
use Porta\Ui\TerminalStateRefresher;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\EditorCallbacks;
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
use Porta\Widget\SeparatorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The terminal IDE application: file tree + code editor + status bar,
 * backed by a language server for diagnostics and autocompletion.
 */
final class Ide
{
    public const string VERSION = '0.1.0';

    private Tui $tui;
    private BidiTui $bidiTui;
    private FileTreeWidget $tree;
    private CodeEditorWidget $editor;
    private ImageViewerWidget $imageViewer;
    private HelpViewerWidget $helpViewer;
    private ContainerWidget $editorBody;
    private StatusBarWidget $statusBar;
    private Notifier $notifier;
    private FreezeWatchdog $watchdog;
    private Clipboard $clipboard;
    private BufferNavigator $bufferNavigator;
    private DocsUrlOpener $docsUrls;
    private EditorArea $editorArea;
    private WorkspaceViews $views;
    private ChromeInvalidator $chrome;
    private TerminalStateRefresher $terminalRefresher;
    private MenuBarWidget $menuBar;
    private TabBarWidget $tabBar;
    private BreadcrumbsWidget $breadcrumbs;
    private FindBarWidget $findBar;
    private GoToFileWidget $goToFile;
    private ResultsPanelWidget $resultsPanel;
    private ProjectSearchController $projectSearchController;
    private TestNavigator $testNavigator;
    private CommandPalette $commandPalette;
    private TerminalPanelWidget $terminalPanel;
    private RunController $run;
    private DebugController $debug;
    private DebugPanelWidget $debugPanel;
    private DebugAdapters $debugAdapters;

    /** The app event bus; the component interface for runtime addListener() on hand-built widgets. */
    private readonly EventDispatcherInterface $dispatcher;
    private readonly PluginManager $plugins;
    private readonly PluginLoader $pluginLoader;
    private readonly PluginSettings $pluginSettings;
    private PluginLifecycle $pluginLifecycle;
    private readonly string $docsDir;
    private readonly ContainerInterface $container;

    /** @var ServiceLocator<object> The one public boundary; every service() resolution goes through it. */
    private readonly ServiceLocator $locator;
    private GitInfo $git;
    private GitRefresher $gitRefresher;
    private WorkspaceLayout $workspaceLayout;
    private PaneLayout $paneLayout;
    private SearchActions $searchActions;
    private GitCommands $gitCommands;
    private LocalHistory $history;
    private LocalHistoryController $localHistory;
    private SessionStore $session;
    private LayoutController $layout;
    private TreeOperations $treeOps;
    private BufferManager $buffers;
    private PanelManager $panels;
    private CommandRegistry $commands;
    private SearchEverywhereController $searchEverywhere;

    private LspSessionPool $lsp;
    private LspBootstrapper $lspBootstrapper;
    private SessionRestorer $sessionRestorer;
    private WorkspaceEditor $workspaceEditor;
    private CompletionController $completion;
    private NavigationController $navigation;
    private RefactorController $refactor;

    private Renderer $renderer;
    private ContextMenu $contextMenu;
    private ContextMenuOpener $contextMenuOpener;
    private FocusCoordinator $focusCoordinator;
    private MenuRebuilder $menuRebuilder;
    private WorkbenchActions $workbench;
    private NotificationLog $notificationLog;

    /** Positions + composites the app-menu and context-menu floating overlays. */
    private MenuOverlayController $overlay;

    private Keybindings $keybindings;
    private Translator $translator;
    private ContainerWidget $main;
    private ContainerWidget $gitAreaContainer;
    private ContainerWidget $settingsAreaContainer;
    private ChangesListWidget $gitChanges;
    private DiffViewWidget $gitDiff;
    private CommitBoxWidget $gitCommitBox;
    private HistoryListWidget $gitHistory;
    private GitTabsWidget $gitTabs;
    private SeparatorWidget $gitSeparator;
    private SettingsNavWidget $settingsNav;
    private SettingsFormWidget $settingsForm;
    private KeybindingsListWidget $settingsKeys;
    private SeparatorWidget $settingsSeparator;
    private ContainerWidget $settingsContent;
    private SettingsController $settings;
    private SettingsLanguageController $settingsLanguages;
    private BufferService $bufferService;
    private MouseRouter $mouseRouter;
    private readonly SwitchablePalette $palette;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $initialFile = null,
        ?TerminalInterface $terminal = null,
        ?Renderer $renderer = null,
        private readonly ?int $initialLine = null,
        private readonly ?string $lspCommand = null,
        private readonly bool $lspDisabled = false,
        private readonly bool $ansi = true,
        ?string $docsDir = null,
    ) {
        // The shipped user guides (F1), package-relative like the translations.
        $this->docsDir = $docsDir ?? \dirname(__DIR__).'/docs';
        $config = IdeConfig::load($this->rootDir);
        $this->container = new IdeContainerFactory()->build($this->rootDir, (string) ($config->settings['language'] ?? 'en'), $this->ansi, (string) ($config->settings['theme'] ?? 'dark'));
        $locator = $this->container->get('porta.ide');
        \assert($locator instanceof ServiceLocator);
        $this->locator = $locator;
        // The parsed .porta.json is a synthetic service: it exists before the
        // container does (its settings steer the env parameters above), and
        // the plugin/keybindings services consume it by type.
        $this->container->set(IdeConfig::class, $config);
        $this->translator = $this->service(Translator::class);
        // The live-swappable theme holders (see applyTheme): widgets and buffers
        // hold these same instances via the Palette/TerminalTheme aliases.
        $this->palette = $this->service(SwitchablePalette::class);
        $this->dispatcher = $this->service(EventDispatcherInterface::class);
        // Live-setting reactions that must run in Ide (the menu bakes labels
        // from hand-assembled state; RTL re-mirroring walks the pane lists).
        // Priority 0 → they precede the -100 chrome sweep, like the old tails.
        $this->dispatcher->addListener(ThemeChanged::class, fn () => $this->menuRebuilder->rebuild());
        $this->dispatcher->addListener(LocaleChanged::class, function (): void {
            $this->menuRebuilder->rebuild();
            $this->paneLayout->applyDirection();
        });
        $pluginHost = $this->service(PluginHost::class);
        $this->pluginSettings = $pluginHost->settings();
        $this->plugins = $pluginHost->manager();
        $this->pluginLoader = $pluginHost->loader();
        // Resolving the Keybindings map triggers the one-time plugin load —
        // plugin default keys must merge into the immutable map.
        $keybindings = $this->service(Keybindings::class);
        $this->keybindings = $keybindings;
        $this->debugAdapters = new DebugAdapters($config->languages);

        // Own the Renderer so mouse hit-testing can query widget rects.
        $this->renderer = $renderer ?? new Renderer($this->buildStyleSheet());
        $this->bidiTui = new BidiTui(
            // The real terminal is decorated so frame writes can never block
            // the event loop when the outer terminal stops draining (the
            // freeze.log-proven cause); injected terminals (tests) bypass it.
            terminal: $terminal ?? new NonBlockingTerminal(new Terminal()),
            keybindings: $keybindings,
            renderer: $this->renderer,
        );
        $this->tui = $this->bidiTui;
        $this->terminalRefresher = new TerminalStateRefresher($this->tui->getTerminal());
        // Hand the manually-built infrastructure to the container as synthetic
        // services (declared synthetic in config/services.php) right after each
        // is created, so container-built controllers can resolve them by type.
        $this->container->set(Renderer::class, $this->renderer);
        $this->container->set(Tui::class, $this->tui);
        $this->container->set(BidiTui::class, $this->bidiTui);

        // The workbench primitives every later build step delegates to.
        $this->clipboard = $this->service(Clipboard::class);
        $this->docsUrls = $this->service(DocsUrlOpener::class);
        $this->editorArea = $this->service(EditorArea::class);
        $this->views = $this->service(WorkspaceViews::class);
        $this->chrome = $this->service(ChromeInvalidator::class);

        $this->buildEditorArea();

        $this->buildSidePanels();

        $this->git = $this->service(GitInfo::class);
        $this->gitRefresher = $this->service(GitRefresher::class);
        $this->gitCommands = new GitCommands($this->git, $this->editor, $this->notifier, $this->tui, $this->translator, $this->gitRefresher, $this->rootDir);
        $this->buildGitView();

        $this->buildSettingsWidgets();

        $this->history = $this->service(LocalHistory::class);
        $this->session = $this->service(SessionStore::class);
        $this->buildLayoutAndOverlay();

        $this->workspaceLayout->mount($this->tui);
        // The chrome roots swept by the live-setting appliers (theme/locale/
        // cursor/tab position); everything exists once the Z-order is mounted.
        $this->chrome->register([$this->menuBar, $this->main, $this->statusBar, $this->goToFile, $this->findBar, $this->resultsPanel, $this->terminalPanel, $this->contextMenu]);

        $this->wireWidgets($keybindings);
        $this->bufferService = new BufferService(
            $this->buffers,
            $this->editor,
            $this->tree,
            $this->notifier,
            $this->tui,
            $this->lsp,
            $this->history,
            $this->service(EditorConfigResolver::class),
            $this->rootDir,
            $this->translator,
            $this->gitRefresher,
            $this->imageViewer,
            $this->helpViewer,
            $this->service(ImageDecoder::class),
            $this->editorBody,
            $this->dispatcher,
            $this->service(FormatOnSave::class),
        );
        $this->bufferNavigator = new BufferNavigator($this->buffers, $this->bufferService, $this->goToFile, $this->editor, $this->tui, $this->rootDir);
        $this->wireEditorCallbacks();
        $this->workbench = new WorkbenchActions(
            $this->buffers,
            $this->bufferService,
            $this->notifier,
            $this->translator,
            $this->tui,
            $this->menuBar,
            $this->panels,
            $this->commandPalette,
            $this->commands,
            $this->notificationLog,
            $this->service(LspLog::class),
            $this->editor,
        );
        $this->lspBootstrapper = new LspBootstrapper(
            $this->lsp,
            $this->service(LspLog::class),
            $this->workspaceEditor,
            $this->bufferService,
            $this->notifier,
            $this->translator,
            $this->rootDir,
            $this->lspCommand,
            $this->lspDisabled,
        );
        $this->sessionRestorer = new SessionRestorer($this->session, $this->buffers, $this->bufferService, $this->editorArea, $this->tui);
        // The debugger orchestrator, after the buffer service it opens files
        // through; the decoration/state pieces are the same container-shared
        // instances the buffer service applies on every buffer switch.
        $this->debug = new DebugController(
            $this->service(BreakpointStore::class),
            $this->debugAdapters,
            $this->editor,
            $this->debugPanel,
            $this->terminalPanel,
            $this->notifier,
            $this->tui,
            $this->translator,
            $this->rootDir,
            $this->bufferService,
            $this->service(DapClientFactory::class),
            $this->dispatcher,
            $this->service(DebugSessionState::class),
            $this->service(EditorDecorations::class),
        );
        $this->wireStatusBar();
        // The search entry points; after wireCommands (ActionsSource reads the
        // populated registry) and after the buffer service both depend on.
        $this->searchActions = new SearchActions(
            $this->panels,
            $this->projectSearchController,
            $this->testNavigator,
            $this->buffers,
            $this->bufferService,
            $this->notifier,
            $this->translator,
            $this->editor,
            $this->rootDir,
        );
        $this->searchEverywhere = new SearchEverywhereFactory(
            $this->editor,
            $this->commands,
            $this->buffers,
            $this->bufferService,
            $this->lsp,
            $this->tui,
            $this->service(Scheduler::class),
            $this->rootDir,
        )->create();
        $this->wireImageViewer();
        $this->wireHelpViewer();

        // Apply persisted settings.
        $this->settings = new SettingsController(
            $config->settings,
            $this->editor,
            $this->notifier,
            $this->rootDir,
            $this->translator,
            $this->service(LiveSettingsApplier::class),
        );
        $this->settings->applyAll();
        $this->settingsLanguages = new SettingsLanguageController($this->editor, $this->notifier, $this->rootDir, $this->translator, $config->languages);
        $this->settingsLanguages->applyToEditor();
        $pluginInstaller = new PluginInstaller($this->rootDir, PluginLoader::globalBase(), $this->translator, new ExecutableFinder()->find('git'), PluginLoader::embeddedDir());
        $this->pluginLifecycle = new PluginLifecycle(
            $this->pluginLoader,
            $this->plugins,
            $this->commands,
            $pluginInstaller,
            $this->notifier,
            $this->translator,
            $this->menuRebuilder,
            $this->settingsNav,
            $this->views,
            $this->tui,
            $this->rootDir,
            $config->disabledPlugins,
        );
        new SettingsViewController(
            $this->settingsNav,
            $this->settingsForm,
            $this->settingsKeys,
            $this->main,
            $this->settingsAreaContainer,
            $this->settingsContent,
            $this->menuBar,
            fn (): array => $this->paneLayout->orderedEditorPanes(),
            $this->tui,
            $this->settings,
            new SettingsKeymapController($this->editor, $this->keybindings, $this->notifier, $this->rootDir, $this->translator),
            $this->settingsLanguages,
            new SettingsLspController($this->rootDir, $this->lsp, $config->lspServers, $this->lspCommand, $this->bufferService, $this->notifier, $this->translator),
            new SettingsDebugController($this->rootDir, $this->debugAdapters, $this->notifier, $this->translator),
            new SettingsPluginsController($pluginInstaller, $this->pluginSettings, $this->notifier, $this->translator, $this->pluginLifecycle),
            onClosed: function (): void {
                $this->tui->setFocus($this->editorArea->widget());
                $this->tui->requestRender();
            },
            views: $this->views,
        );
        $this->pluginLifecycle->refreshNav();

        $this->localHistory = new LocalHistoryController(
            $this->history,
            $this->panels,
            $this->editor,
            $this->buffers,
            $this->notifier,
            $this->tui,
            $this->translator,
            $this->completion,
        );

        $this->treeOps = new TreeOperations(
            $this->tree,
            $this->editor,
            $this->tui,
            $this->notifier,
            $this->buffers,
            $this->translator,
            $this->bufferService,
            $this->clipboard,
            $this->searchActions,
            $this->projectSearchController,
            $this->rootDir,
        );
        $hit = $this->service(HitTester::class);
        $contextItems = new ContextMenuBuilder($this->commands, $this->editor, $this->keybindings, $this->bufferService, $this->translator);
        $this->contextMenuOpener = new ContextMenuOpener(
            $this->tui,
            $this->renderer,
            $this->contextMenu,
            $this->overlay,
            $contextItems,
            $this->treeOps,
            $this->menuBar,
            $this->editor,
            $this->tree,
            $this->tabBar,
            $this->imageViewer,
            $this->helpViewer,
            $this->buffers,
            $this->notifier,
            $this->translator,
        );
        $this->focusCoordinator = $this->service(FocusCoordinator::class);
        $this->mouseRouter = new MouseRouter(
            $hit,
            $this->tui,
            new EditorLayoutPanes($this->tree, $this->tabBar, $this->editor, $this->breadcrumbs, $this->imageViewer, $this->helpViewer),
            new OverlayPanels($this->terminalPanel, $this->resultsPanel, $this->goToFile, $this->debugPanel),
            $this->menuBar,
            $this->contextMenu,
            $this->layout,
            $this->overlay,
            $this->buffers,
            $this->bufferService,
            $this->treeOps,
            $contextItems,
            new GitMouseRouter($hit, $this->tui, $this->gitChanges, $this->gitDiff, $this->gitCommitBox, $this->gitHistory, $this->gitTabs, $this->gitSeparator),
            $this->views,
            new SettingsMouseRouter($hit, $this->tui, $this->settingsNav, $this->settingsForm, $this->settingsKeys, $this->settingsSeparator),
            $this->bidiTui,
            $this->debug,
        );

        // Now that both view controllers exist, settle the layout for the
        // persisted locale's writing direction (RTL mirrors the sidebar right).
        $this->paneLayout->applyDirection();

        new SearchUiWiring(
            $this->findBar,
            $this->goToFile,
            $this->resultsPanel,
            $this->editor,
            $this->editorArea,
            $this->tui,
            $this->panels,
            $this->commandPalette,
            $this->navigation,
            $this->projectSearchController,
            $this->localHistory,
            $this->service(ResultContextProvider::class),
            $this->bufferService,
            $this->service(Scheduler::class),
            $this->rootDir,
        )->wire();
        $this->registerCommands();
        $this->wireMenuBar($keybindings);
        $inputDispatcher = new InputDispatcher($this->tui, $this->mouseRouter, $this->contextMenu, $this->editor, $this->commands, $keybindings);
        $this->tui->addListener($inputDispatcher->dispatch(...));

        // Everything plugin handlers need exists now — hand them the live seams.
        $this->plugins->wireRuntime(new PluginRuntime(
            flash: fn (string $message, float $seconds) => $this->notifier->flash($message, $seconds),
            translator: $this->translator,
            editor: $this->editor,
            openFile: fn (string $path) => $this->openFile($path, focusEditor: true),
            requestRender: fn () => $this->tui->requestRender(),
            setDiagnostics: fn (string $path, array $byLine) => $this->bufferService->applyDiagnostics(Uri::fromPath($path), $byLine),
        ));

        foreach ([...$config->warnings, ...$this->plugins->warnings()] as $warning) {
            $this->notifier->flash($warning, 5.0);
        }
    }

    /**
     * Resolve a shared service from the container, typed to the requested class.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        /** @var T $service */
        $service = $this->locator->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    /**
     * @internal exposed for tests
     */
    public function getTui(): Tui
    {
        return $this->tui;
    }

    public function run(): void
    {
        $this->lspBootstrapper->start();
        $this->gitRefresher->refreshBranch();

        // Poll the filesystem so external edits, git checkouts and deletions are
        // reflected in the open buffers and the tree without a manual reload. The
        // timer only fires once the event loop runs (inside tui->run()), so it is
        // safe to arm before setup; arming it before the try guarantees the finally
        // can always cancel it.
        $watcher = EventLoop::repeat(1.0, fn () => $this->pollFilesystem());

        // Loop-stall detector: evidence for "the app froze" reports lands in
        // .porta/freeze.log (drift lines + a backtrace on a hard hang).
        $this->watchdog->start();

        try {
            // Enter the alternate screen so quitting restores the terminal exactly
            // as it was before launch (like vim/htop). Plus SGR mouse reporting:
            // 1000 (button press/release), 1003 (any-event motion — a superset of
            // 1002's button-held drag, so we also see bare hover-moves for row
            // highlighting) and 1006 (SGR coordinates). VirtualTerminal records
            // these; tests feed synthetic seqs. Paired teardown is in the finally.
            $this->tui->getTerminal()->write("\x1b[?1049h\x1b[?1000h\x1b[?1003h\x1b[?1006h");

            $this->layout->applyLayout();

            if (null !== $this->initialFile) {
                $this->openFile($this->initialFile);
                if (null !== $this->initialLine) {
                    $this->editor->jumpTo($this->initialLine - 1, 0); // CLI lines are 1-based
                }
                $this->tui->setFocus($this->editorArea->widget());
            } elseif (!$this->sessionRestorer->restore()) {
                $this->tui->setFocus($this->tree);
            }

            $this->tui->run();
        } finally {
            $this->watchdog->stop();
            EventLoop::cancel($watcher);
            // Disable mouse reporting, then leave the alternate screen (restoring
            // the pre-launch terminal). Guaranteed even if startup above threw.
            $this->tui->getTerminal()->write("\x1b[?1000l\x1b[?1003l\x1b[?1006l\x1b[?1049l");
            $this->sessionRestorer->save();
            $this->lsp->stop();
            $this->git->cancelAll();
            $this->run->stop();
            $this->debug->stop();
        }
    }

    // --- Wiring ---------------------------------------------------------------

    private function wireWidgets(Keybindings $keybindings): void
    {
        $this->wireEditor();
        // The registry and menu rebuilder objects exist early (later build
        // steps hold them); commands register and menus bake at the end of
        // the constructor, once every provider dependency exists.
        $this->commands = new CommandRegistry();
        $this->menuRebuilder = new MenuRebuilder($this->menuBar, $this->commands, $this->editor, $this->keybindings, $this->translator, $this->views, $this->plugins);
        $this->wirePanels();
    }

    /** The file tree's open hook and the editor's outbound callback struct. */
    private function wireEditor(): void
    {
        $this->tree->onOpenFile(fn (string $path) => $this->openFile($path, focusEditor: true));
    }

    /**
     * The editor's outbound hooks, built once the buffer stack exists: file
     * lifecycle, buffer switching, and every LSP-powered feature routed to
     * its controller through the typed handler groups.
     */
    private function wireEditorCallbacks(): void
    {
        $this->editor->setCallbacks(new EditorCallbacks(
            file: new EditorFileActions($this->bufferService, $this->bufferNavigator, $this->clipboard, $this->findBar, $this->editor, $this->tui),
            navigation: $this->navigation,
            completion: $this->completion,
            refactor: $this->refactor,
            buffer: $this->bufferNavigator,
        ));
    }

    /** The image viewer's IDE hooks: tab management and the status-bar refresh. */
    private function wireImageViewer(): void
    {
        $this->imageViewer->setHandler(new ImageViewerActions($this->bufferNavigator, $this->statusBar, $this->tui));
    }

    private function wireHelpViewer(): void
    {
        $this->helpViewer->setDocsRoot($this->docsDir);
        $this->helpViewer->setHandler(new HelpViewerActions($this->bufferService, $this->bufferNavigator, $this->docsUrls, $this->notifier, $this->translator, $this->docsDir));
    }

    /** Terminal and diff panels: input forwarding and focus restoration on close. */
    private function wirePanels(): void
    {
        $this->terminalPanel->onInputForward(fn (string $data) => $this->run->forwardInput($data));
        $this->terminalPanel->onClose(fn () => $this->panels->returnFocusFrom($this->terminalPanel));
    }

    /**
     * Route a mouse event by hit-testing the tree, tab bar and editor rects
     * populated by the Renderer on the last frame.
     */
    private function wireStatusBar(): void
    {
        $provider = new StatusBarProvider($this->buffers, $this->editor, $this->imageViewer, $this->gitRefresher, $this->debug, $this->lsp, $this->palette, $this->translator, $this->rootDir);
        $this->statusBar->setProvider($provider->segments(...));
    }

    private function buildStyleSheet(): StyleSheet
    {
        $sheet = new StyleSheet();
        $sheet->addRule('#main', new Style(direction: Direction::Horizontal, gap: 0));

        return $sheet;
    }

    /** Construct the settings-view widgets and their horizontal pane container. */
    private function buildSettingsWidgets(): void
    {
        $this->settingsNav = $this->service(SettingsNavWidget::class);
        $this->settingsForm = $this->service(SettingsFormWidget::class);
        $this->settingsKeys = $this->service(KeybindingsListWidget::class);
        $this->settingsSeparator = $this->workspaceLayout->settingsSeparator();
        $this->settingsContent = $this->workspaceLayout->settingsContent();
        $this->settingsAreaContainer = $this->workspaceLayout->settingsArea();
        $this->paneLayout->arrangeSettingsArea();
    }

    /** Construct the git-view widgets, their horizontal pane container and the controller. */
    /** The resize/layout controller, the menu/context overlay compositor and the tree operations. */
    private function buildLayoutAndOverlay(): void
    {
        $this->layout = $this->service(LayoutController::class);
        $this->overlay = $this->service(MenuOverlayController::class);
    }

    /** The editor workspace: tree, editor + LSP backend, chrome widgets and the main horizontal layout. */
    private function buildEditorArea(): void
    {
        $this->buffers = $this->service(BufferManager::class);
        $this->lsp = $this->service(LspSessionPool::class);

        $this->tree = $this->service(FileTreeWidget::class);
        $this->editor = $this->service(CodeEditorWidget::class);
        $this->workspaceEditor = $this->service(WorkspaceEditor::class);
        $this->statusBar = $this->service(StatusBarWidget::class);
        $this->notifier = $this->service(Notifier::class);
        $this->notificationLog = $this->service(NotificationLog::class);
        $this->watchdog = $this->service(FreezeWatchdog::class);
        $this->menuBar = $this->service(MenuBarWidget::class);
        $this->contextMenu = $this->service(ContextMenu::class);
        $this->completion = $this->service(CompletionController::class);
        $this->tabBar = $this->service(TabBarWidget::class);
        $this->tabBar->setProvider($this->service(TabListProvider::class)->tabs(...));

        $this->breadcrumbs = $this->service(BreadcrumbsWidget::class);
        $this->breadcrumbs->setProvider($this->service(BreadcrumbTrailProvider::class)->trail(...));

        $this->imageViewer = $this->service(ImageViewerWidget::class);
        $this->helpViewer = $this->service(HelpViewerWidget::class);

        // The composite tree (layout containers, separators, assembly) lives in
        // WorkspaceLayout; the properties stay as aliases for the reflection
        // seams tests rely on and the remaining hand-wiring.
        $this->workspaceLayout = $this->service(WorkspaceLayout::class);
        $this->editorBody = $this->workspaceLayout->editorBody();
        $this->main = $this->workspaceLayout->main();
        $this->paneLayout = $this->service(PaneLayout::class);
        $this->paneLayout->arrangeEditorArea();
    }

    /** The floating panels, project search, command palette and the LSP/run controllers. */
    private function buildSidePanels(): void
    {
        $this->goToFile = $this->service(GoToFileWidget::class);
        $this->findBar = $this->service(FindBarWidget::class);
        $this->resultsPanel = $this->service(ResultsPanelWidget::class);
        $this->terminalPanel = $this->service(TerminalPanelWidget::class);
        $this->projectSearchController = $this->service(ProjectSearchController::class);
        $this->commandPalette = $this->service(CommandPalette::class);
        $this->panels = $this->service(PanelManager::class);
        $this->testNavigator = $this->service(TestNavigator::class);
        $this->navigation = new NavigationController(
            $this->lsp,
            $this->editor,
            $this->tui,
            $this->notifier,
            $this->translator,
            $this->buffers,
            $this->resultsPanel,
            $this->panels,
            $this->rootDir,
            fn (string $path, bool $focusEditor) => $this->openFile($path, $focusEditor),
            fn (string $url) => $this->docsUrls->open($url),
        );
        $this->refactor = new RefactorController(
            $this->lsp,
            $this->editor,
            $this->tui,
            $this->notifier,
            $this->translator,
            $this->buffers,
            $this->workspaceEditor,
            $this->rootDir,
            fn (): string => $this->lsp->statusFor($this->editor->getFilePath()),
        );
        $this->run = new RunController(
            new RunConfigs($this->rootDir),
            $this->terminalPanel,
            new TerminalKeyEncoder(fn (): bool => $this->tui->getTerminal()->isKittyProtocolActive()),
            $this->editor,
            $this->notifier,
            $this->tui,
            $this->translator,
            $this->rootDir,
        );
        $this->debugPanel = $this->service(DebugPanelWidget::class);
        $this->debugPanel->onClose(fn () => $this->panels->returnFocusFrom($this->debugPanel));
    }

    private function buildGitView(): void
    {
        $this->gitChanges = $this->service(ChangesListWidget::class);
        $this->gitDiff = $this->service(DiffViewWidget::class);
        $this->gitCommitBox = $this->service(CommitBoxWidget::class);
        $this->gitHistory = $this->service(HistoryListWidget::class);
        $this->gitTabs = $this->service(GitTabsWidget::class);
        $gitLeft = $this->workspaceLayout->gitLeft();
        $this->gitSeparator = $this->workspaceLayout->gitSeparator();
        $this->gitAreaContainer = $this->workspaceLayout->gitArea();
        $this->paneLayout->arrangeGitArea();
        new GitViewController(
            $this->git,
            $this->rootDir,
            new GitPanes($this->gitChanges, $this->gitDiff, $this->gitCommitBox, $this->gitHistory, $this->gitTabs),
            $this->main,
            $this->gitAreaContainer,
            $gitLeft,
            $this->menuBar,
            $this->tui,
            $this->notifier,
            $this->translator,
            $this->paneLayout,
            $this->editorArea,
            $this->dispatcher,
            $this->service(LayoutController::class),
            $this->clipboard,
            $this->views,
        );
    }

    // --- File handling ----------------------------------------------------------

    private function openFile(string $path, bool $focusEditor = false): void
    {
        $this->bufferService->openFile($path, $focusEditor);
    }

    /**
     * Reconcile the open buffers and the file tree with the on-disk state. Runs on
     * a timer (see {@see run()}); also invoked directly by tests.
     *
     * @internal exposed for tests
     */
    public function pollFilesystem(): void
    {
        $this->bufferService->pollFilesystem();
        $this->terminalRefresher->tick();
    }

    // --- LSP -------------------------------------------------------------------

    /**
     * Register every IDE-level command once: keybinding dispatch, the Find Action
     * palette, and their labels all read from this single source of truth.
     * Provider order is contractual — registration order is palette display
     * order, and core registers before plugins so core wins key collisions.
     */
    private function registerCommands(): void
    {
        $workbench = new WorkbenchCommands(
            $this->workbench,
            $this->focusCoordinator,
            $this->contextMenuOpener,
            $this->service(PaneResizer::class),
            $this->bufferService,
            $this->searchEverywhere,
            $this->localHistory,
            $this->run,
            $this->views,
            $this->editorArea,
            $this->tui,
            $this->editor,
            $this->imageViewer,
            $this->tree,
            $this->menuBar,
            $this->goToFile,
            $this->findBar,
        );
        $providers = [
            $workbench,
            new DebugCommands($this->debug),
        ];
        foreach ($providers as $provider) {
            $provider->register($this->commands);
        }

        // Plugin commands after core's key-bound groups: core wins when a
        // plugin default key collides.
        $this->plugins->registerCommands($this->commands);

        // Palette commands, in display order (some also key-bound).
        $palette = [
            new FileCommands($this->bufferService, $this->refactor),
            new SearchCommands($this->searchActions),
            new ViewCommands($this->workbench, $this->goToFile, $this->tui, $this->settings, $this->run, $this->views, $this->bufferService, $this->docsDir),
            new GitCommandProvider($this->gitCommands),
        ];
        foreach ($palette as $provider) {
            $provider->register($this->commands);
        }
    }

    /** Populate the application menu bar and return focus to the editor on close. */
    private function wireMenuBar(Keybindings $keybindings): void
    {
        $this->menuRebuilder->rebuild();
        $this->menuBar->onNavigate(function (): void {
            $this->overlay->refreshMenu();
        });
        $this->menuBar->onClose(function (): void {
            $this->overlay->refreshMenu();
            $this->tui->setFocus(match (true) {
                null !== ($target = $this->views->focusTarget()) => $target,
                default => $this->editorArea->widget(),
            });
            $this->tui->requestRender();
        });

        $this->contextMenu->onNavigate(function (): void {
            $this->overlay->refreshContextMenu();
        });
        $this->contextMenu->onClose(function (): void {
            $this->overlay->refreshContextMenu();
            $this->tui->requestRender();
        });
    }
}
