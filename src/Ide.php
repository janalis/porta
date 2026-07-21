<?php

declare(strict_types=1);

namespace Porta;

use Porta\Bootstrap\WidgetHooks;
use Porta\Command\CommandRegistryPopulator;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsLanguageController;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Container\LspMode;
use Porta\Debug\DebugController;
use Porta\Debug\DebugStatePersistence;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitViewController;
use Porta\History\LocalHistoryController;
use Porta\History\SessionRestorer;
use Porta\Input\InputDispatcher;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\ResponsiveLayout;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\WorkspaceEditor;
use Porta\Onboarding\OnboardingState;
use Porta\Onboarding\WelcomeViewController;
use Porta\Plugin\PluginHost;
use Porta\Plugin\PluginLifecycle;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginRuntime;
use Porta\Run\RunController;
use Porta\Search\SearchActions;
use Porta\Search\SearchUiWiring;
use Porta\Settings\SettingsViewController;
use Porta\Support\FreezeWatchdog;
use Porta\Ui\BidiTui;
use Porta\Ui\ChromeInvalidator;
use Porta\Ui\EditorArea;
use Porta\Ui\MenuRebuilder;
use Porta\Ui\NonBlockingTerminal;
use Porta\Ui\Notifier;
use Porta\Ui\TerminalStateRefresher;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\TailwindStylesheet;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
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
    private StatusBarWidget $statusBar;
    private Notifier $notifier;
    private FreezeWatchdog $watchdog;
    private EditorArea $editorArea;
    private ChromeInvalidator $chrome;
    private TerminalStateRefresher $terminalRefresher;
    private MenuBarWidget $menuBar;
    private FindBarWidget $findBar;
    private ResultsPanelWidget $resultsPanel;
    private TerminalPanelWidget $terminalPanel;
    private RunController $run;
    private DebugController $debug;
    private DebugPanelWidget $debugPanel;

    private readonly PluginManager $plugins;
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
    private LocalHistoryController $localHistory;
    private LayoutController $layout;
    private BufferManager $buffers;
    private CommandRegistry $commands;

    private LspSessionPool $lsp;
    private LspBootstrapper $lspBootstrapper;
    private SessionRestorer $sessionRestorer;
    private DebugStatePersistence $debugState;
    private WorkspaceEditor $workspaceEditor;
    private NavigationController $navigation;
    private RefactorController $refactor;

    private Renderer $renderer;
    private ContextMenu $contextMenu;
    private MenuRebuilder $menuRebuilder;

    /** Positions + composites the app-menu and context-menu floating overlays. */

    private ContainerWidget $main;
    private SettingsController $settings;
    private SettingsLanguageController $settingsLanguages;
    private BufferService $bufferService;

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
        $this->container = new IdeContainerFactory()->build(
            $this->rootDir,
            $config,
            $this->ansi ? Ansi::Enabled : Ansi::Disabled,
            $this->lspCommand,
            $this->lspDisabled ? LspMode::Disabled : LspMode::Enabled,
            $this->docsDir,
        );
        $locator = $this->container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'The porta.ide service is the ServiceLocator boundary');
        $this->locator = $locator;
        // The parsed .porta.json is a synthetic service: it exists before the
        // container does (its settings steer the env parameters above), and
        // the plugin/keybindings services consume it by type.
        $this->container->set(IdeConfig::class, $config);
        $pluginHost = $this->service(PluginHost::class);
        $this->plugins = $pluginHost->manager();
        // Resolving the Keybindings map triggers the one-time plugin load —
        // plugin default keys must merge into the immutable map.
        $keybindings = $this->service(Keybindings::class);

        // Own the Renderer so mouse hit-testing can query widget rects. The
        // sheet is a TailwindStylesheet so rules and utility classes both
        // resolve (the Renderer clones it, preserving the subclass).
        $this->renderer = $renderer ?? new Renderer(new TailwindStylesheet());
        $this->bidiTui = new BidiTui(
            // The real terminal is decorated so frame writes can never block
            // the event loop when the outer terminal stops draining (the
            // freeze.log-proven cause); injected terminals (tests) bypass it.
            terminal: $terminal ?? new NonBlockingTerminal(new Terminal()),
            keybindings: $keybindings,
            renderer: $this->renderer,
        );
        $this->tui = $this->bidiTui;
        // Hand the manually-built infrastructure to the container as synthetic
        // services (declared synthetic in config/services.php) right after each
        // is created, so container-built controllers can resolve them by type.
        $this->container->set(Renderer::class, $this->renderer);
        $this->container->set(Tui::class, $this->tui);
        $this->container->set(BidiTui::class, $this->bidiTui);
        $this->terminalRefresher = $this->service(TerminalStateRefresher::class);

        // The workbench primitives every later build step delegates to.
        $this->editorArea = $this->service(EditorArea::class);
        $this->chrome = $this->service(ChromeInvalidator::class);

        $this->buildEditorArea();

        $this->buildSidePanels();

        $this->git = $this->service(GitInfo::class);
        $this->gitRefresher = $this->service(GitRefresher::class);
        $this->buildGitView();

        $this->buildSettingsWidgets();

        $this->buildLayoutAndOverlay();

        $this->workspaceLayout->mount($this->tui);
        // The chrome roots swept by the live-setting appliers (theme/locale/
        // cursor/tab position); everything exists once the Z-order is mounted.
        $this->chrome->register([
            $this->menuBar,
            $this->main,
            $this->statusBar,
            $this->findBar,
            $this->service(DockTabsWidget::class),
            $this->resultsPanel,
            $this->debugPanel,
            $this->terminalPanel,
            $this->contextMenu,
        ]);

        $this->wireWidgets($keybindings);
        $this->bufferService = $this->service(BufferService::class);
        $this->navigation = $this->service(NavigationController::class);
        $this->refactor = $this->service(RefactorController::class);
        $this->run = $this->service(RunController::class);
        $this->lspBootstrapper = $this->service(LspBootstrapper::class);
        $this->sessionRestorer = $this->service(SessionRestorer::class);
        $this->debugState = $this->service(DebugStatePersistence::class);
        // The debugger orchestrator, after the buffer service it opens files
        // through; the decoration/state pieces are the same container-shared
        // instances the buffer service applies on every buffer switch.
        $this->debug = $this->service(DebugController::class);
        // The search entry points; after wireCommands (ActionsSource reads the
        // populated registry) and after the buffer service both depend on.
        $this->searchActions = $this->service(SearchActions::class);

        // Apply persisted settings.
        $this->settings = $this->service(SettingsController::class);
        $this->settings->applyAll();
        $this->settingsLanguages = $this->service(SettingsLanguageController::class);
        $this->settingsLanguages->applyToEditor();
        $this->pluginLifecycle = $this->service(PluginLifecycle::class);
        // Eager: constructing a view controller attaches it to WorkspaceViews
        // (an unreferenced service would otherwise never exist).
        $this->service(SettingsViewController::class);
        $this->service(WelcomeViewController::class);
        $this->pluginLifecycle->refreshNav();

        $this->localHistory = $this->service(LocalHistoryController::class);

        // Now that both view controllers exist, settle the layout for the
        // persisted locale's writing direction (RTL mirrors the sidebar right).
        $this->paneLayout->applyDirection();

        $this->service(SearchUiWiring::class)->wire();
        $this->service(CommandRegistryPopulator::class)->populate();
        $this->service(WidgetHooks::class)->wire();
        $this->menuRebuilder->rebuild();
        $this->tui->addListener($this->service(InputDispatcher::class)->dispatch(...));

        // Everything plugin handlers need exists now — hand them the live seams.
        $this->plugins->wireRuntime($this->service(PluginRuntime::class));

        foreach ([...$config->warnings, ...$this->plugins->warnings()] as $warning) {
            $this->notifier->flash($warning, 5.0);
        }
    }

    /**
     * Open the Welcome screen the first time a project is opened, and record
     * that it happened so it never reappears on its own.
     *
     * Marked as seen whether or not the user works through the steps: it is a
     * signpost, not a checklist, and re-showing it would nag. `ide_welcome`
     * brings it back on demand.
     */
    private function showWelcomeOnFirstRun(): void
    {
        $onboarding = $this->service(OnboardingState::class);
        if ($onboarding->hasSeenWelcome()) {
            return;
        }

        $onboarding->markWelcomeSeen();
        $this->service(WorkspaceViews::class)->openWelcome();
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
        \assert($service instanceof $id, description: 'The locator entry id matches the requested service type');

        return $service;
    }

    /**
     * @internal exposed for tests
     */
    public function getTui(): Tui
    {
        return $this->tui;
    }

    /**
     * @internal exposed for tests
     */
    public function getCommands(): CommandRegistry
    {
        return $this->commands;
    }

    /**
     * @internal exposed for tests
     */
    public function getBuffers(): BufferManager
    {
        return $this->buffers;
    }

    /**
     * @internal exposed for tests
     */
    public function getSearchActions(): SearchActions
    {
        return $this->searchActions;
    }

    /**
     * @internal exposed for tests
     */
    public function getLocalHistory(): LocalHistoryController
    {
        return $this->localHistory;
    }

    /**
     * @internal exposed for tests
     */
    public function getWorkspaceEditor(): WorkspaceEditor
    {
        return $this->workspaceEditor;
    }

    /**
     * @internal exposed for tests
     */
    public function getNavigation(): NavigationController
    {
        return $this->navigation;
    }

    /**
     * @internal exposed for tests
     */
    public function getRefactor(): RefactorController
    {
        return $this->refactor;
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
        $watcher = EventLoop::repeat(1.0, $this->pollFilesystem(...));

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

            // Before any buffer opens: the boot-time BufferSwitched pass then
            // paints restored breakpoints into the gutter for free.
            $this->debugState->restore();

            $openedInitial = false;
            if (null !== $this->initialFile) {
                $this->openFile($this->initialFile);
                if (null !== $this->initialLine) {
                    $this->editor->jumpTo($this->initialLine - 1, 0); // CLI lines are 1-based
                }
                $this->tui->setFocus($this->editorArea->widget());
                $openedInitial = true;
            }
            if (!$openedInitial && !$this->sessionRestorer->restore()) {
                $this->tui->setFocus($this->tree);
            }

            // Last: the initial file and the restored session decide what the
            // editor shows, and both claim focus. Welcome has to come after so
            // it owns the main area, and so Esc reveals a settled editor.
            $this->showWelcomeOnFirstRun();

            $this->tui->run();
        } finally {
            $this->watchdog->stop();
            EventLoop::cancel($watcher);
            // Disable mouse reporting, then leave the alternate screen (restoring
            // the pre-launch terminal). Guaranteed even if startup above threw.
            $this->tui->getTerminal()->write("\x1b[?1000l\x1b[?1003l\x1b[?1006l\x1b[?1049l");
            $this->sessionRestorer->save();
            $this->debugState->save();
            $this->lsp->stop();
            $this->git->cancelAll();
            $this->run->stop();
            $this->debug->stop();
        }
    }

    // --- Wiring ---------------------------------------------------------------

    private function wireWidgets(Keybindings $keybindings): void
    {
        // The registry and menu rebuilder objects exist early (later build
        // steps hold them); commands register and menus bake at the end of
        // the constructor, once every provider dependency exists.
        $this->commands = $this->service(CommandRegistry::class);
        $this->menuRebuilder = $this->service(MenuRebuilder::class);
    }

    /** Construct the settings-view widgets and their horizontal pane container. */
    private function buildSettingsWidgets(): void
    {
        $this->paneLayout->arrangeSettingsArea();
    }

    /** Construct the git-view widgets, their horizontal pane container and the controller. */
    /** The resize/layout controller, the menu/context overlay compositor and the tree operations. */
    private function buildLayoutAndOverlay(): void
    {
        $this->layout = $this->service(LayoutController::class);
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
        $this->watchdog = $this->service(FreezeWatchdog::class);
        $this->menuBar = $this->service(MenuBarWidget::class);
        $this->contextMenu = $this->service(ContextMenu::class);

        // The composite tree (layout containers, separators, assembly) lives in
        // WorkspaceLayout; the properties stay as aliases for the reflection
        // seams tests rely on and the remaining hand-wiring.
        $this->workspaceLayout = $this->service(WorkspaceLayout::class);
        $this->main = $this->workspaceLayout->main();
        $this->paneLayout = $this->service(PaneLayout::class);
        $this->paneLayout->arrangeEditorArea();
    }

    /** The floating panels, project search, command palette and the LSP/run controllers. */
    private function buildSidePanels(): void
    {
        $this->findBar = $this->service(FindBarWidget::class);
        $this->resultsPanel = $this->service(ResultsPanelWidget::class);
        $this->terminalPanel = $this->service(TerminalPanelWidget::class);
        $this->debugPanel = $this->service(DebugPanelWidget::class);
    }

    private function buildGitView(): void
    {
        $this->paneLayout->arrangeGitArea();
        // Eager: constructing the git view attaches it to WorkspaceViews (an
        // unreferenced service would otherwise never exist); its remote-
        // completed refresh rides the compiled #[AsEventListener] map.
        $this->service(GitViewController::class);
        // Eager for the same reason: registers itself on the render loop's
        // width hook so the sidebar auto-collapses in narrow terminals.
        $this->service(ResponsiveLayout::class);
    }

    // --- File handling ----------------------------------------------------------

    private function openFile(string $path): void
    {
        $this->bufferService->openFile($path, OpenFocus::Keep);
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
}
