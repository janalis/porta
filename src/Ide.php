<?php

declare(strict_types=1);

namespace Porta;

use Porta\Bootstrap\CoreDockPanels;
use Porta\Bootstrap\CorePanes;
use Porta\Bootstrap\EagerServices;
use Porta\Bootstrap\StartupWiring;
use Porta\Bootstrap\WidgetHooks;
use Porta\Command\CommandRegistryPopulator;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Container\LspMode;
use Porta\Debug\DebugController;
use Porta\Debug\DebugStatePersistence;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Editor\PaneRegistrar;
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\History\SessionRestorer;
use Porta\Input\InputDispatcher;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspSessionPool;
use Porta\Onboarding\OnboardingState;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Run\RunController;
use Porta\Search\SearchUiWiring;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsLanguageController;
use Porta\Support\FreezeWatchdog;
use Porta\Ui\BidiTui;
use Porta\Ui\NonBlockingTerminal;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Porta\Workbench\TerminalStateRefresher;
use Porta\Workbench\WorkspaceView;
use Porta\Workbench\WorkspaceViews;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\TailwindStylesheet;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

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
    private Notifier $notifier;
    private FreezeWatchdog $watchdog;
    private EditorArea $editorArea;
    private TerminalStateRefresher $terminalRefresher;
    private RunController $run;
    private DebugController $debug;

    private PluginLifecycle $pluginLifecycle;
    private readonly string $docsDir;
    private readonly ContainerInterface $container;

    /** @var ServiceLocator<object> The one public boundary; every service() resolution goes through it. */
    private readonly ServiceLocator $locator;
    private GitInfo $git;
    private GitRefresher $gitRefresher;
    private WorkspaceLayout $workspaceLayout;
    private PaneLayout $paneLayout;
    private LayoutController $layout;

    private LspSessionPool $lsp;
    private LspBootstrapper $lspBootstrapper;
    private SessionRestorer $sessionRestorer;
    private DebugStatePersistence $debugState;

    private Renderer $renderer;
    private MenuRebuilder $menuRebuilder;

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

        $this->buildEditorArea();

        $this->git = $this->service(GitInfo::class);
        $this->gitRefresher = $this->service(GitRefresher::class);

        $this->buildLayoutAndOverlay();

        $this->workspaceLayout->mount($this->tui);
        // The chrome roots swept by the live-setting appliers (theme/locale/
        // cursor/tab position) declare themselves with #[AsChromeRoot];
        // ChromeInvalidator injects them as a tagged iterator — no register here.

        $this->wireWidgets($keybindings);
        $this->bufferService = $this->service(BufferService::class);
        $this->run = $this->service(RunController::class);
        $this->lspBootstrapper = $this->service(LspBootstrapper::class);
        $this->sessionRestorer = $this->service(SessionRestorer::class);
        $this->debugState = $this->service(DebugStatePersistence::class);
        // The debugger orchestrator, after the buffer service it opens files
        // through; the decoration/state pieces are the same container-shared
        // instances the buffer service applies on every buffer switch.
        $this->debug = $this->service(DebugController::class);

        // Apply persisted settings.
        $this->settings = $this->service(SettingsController::class);
        $this->settings->applyAll();
        $this->settingsLanguages = $this->service(SettingsLanguageController::class);
        $this->settingsLanguages->applyToEditor();
        $this->pluginLifecycle = $this->service(PluginLifecycle::class);
        // Construct every #[AsEager] service for its self-registering ctor
        // side effect (view controllers attach to WorkspaceViews; the
        // presenters hook onBeforeFrame), in the tag's priority order — which
        // keeps the contractual find→picker→toast so a shared-span-slot
        // hand-over clears before it repaints.
        $this->service(EagerServices::class)->boot();
        $this->pluginLifecycle->refreshNav();

        // Now that the view controllers exist — and have MINTED their regions,
        // which is why this cannot run earlier — settle the layout for the
        // persisted locale's writing direction (RTL mirrors the sidebar right)
        // and give every region its pane order.
        $this->paneLayout->applyDirection();

        $this->service(SearchUiWiring::class)->wire();
        // Panes before commands: a pane's registration is what makes its
        // routing id answerable, and nothing may open a file before it does.
        // Core's own panes first, so a plugin claiming a built-in id replaces it
        // rather than being replaced by it.
        $this->service(CorePanes::class)->register();
        $this->service(PaneRegistrar::class)->register();
        // Before WidgetHooks: BottomDock::wire() installs a dismiss hook on
        // every registered panel, so the registry has to be populated first.
        $this->service(CoreDockPanels::class)->register();
        $this->service(CommandRegistryPopulator::class)->populate();
        $this->service(WidgetHooks::class)->wire();
        $this->menuRebuilder->rebuild();
        $this->tui->addListener($this->service(InputDispatcher::class)->dispatch(...));
        // Last: hand the plugin handlers their live runtime seam and flash the
        // config/plugin load warnings, once every service they reach exists.
        $this->service(StartupWiring::class)->wire();
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
        $this->service(WorkspaceViews::class)->open(WorkspaceView::Welcome);
    }

    /**
     * Resolve a service through the `porta.ide` locator — deliberately
     * locator-ONLY, so a class that forgets #[AsIdeService] fails loudly here
     * instead of silently resolving through the raw container ({@see
     * \Porta\Tests\Unit\Container\IdeLocatorMembershipTest} pins every call
     * site). Tests reach a running IDE's services through
     * `tests/Support/Services` (the same locator-first semantics plus the
     * synthetics fallback) — src carries no test-only resolution API.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        /** @var T $service */
        $service = $this->locator->get($class);
        \assert($service instanceof $class, description: 'The locator entry id matches the requested service type');

        return $service;
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

            // Same reason, for observers: IdeReady lands before the first
            // BufferOpened instead of racing it.
            $this->service(EventDispatcherInterface::class)->dispatch(new IdeReady($this->rootDir));

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
            // Observers first, while every service they might touch is still
            // up. The loop has stopped, so a listener that schedules work is a
            // no-op — that is the contract IdeShuttingDown documents.
            $this->service(EventDispatcherInterface::class)->dispatch(new IdeShuttingDown($this->rootDir));
            // Then: closing the full-screen views cancels their timers (the
            // git view's 2s status-poll repeat) so nothing re-arms below.
            $this->service(WorkspaceViews::class)->closeAll();
            $this->watchdog->stop();
            EventLoop::cancel($watcher);
            // Disable mouse reporting, then leave the alternate screen (restoring
            // the pre-launch terminal). Guaranteed even if startup above threw.
            $this->tui->getTerminal()->write("\x1b[?1000l\x1b[?1003l\x1b[?1006l\x1b[?1049l");
            $this->sessionRestorer->save();
            $this->debugState->save();
            $this->notifier->dismissAll(); // cancel pending toast timers — nothing may outlive the loop
            $this->lsp->stop();
            $this->git->cancelAll();
            $this->run->stop();
            $this->debug->stop();
        }
    }

    // --- Wiring ---------------------------------------------------------------

    private function wireWidgets(Keybindings $keybindings): void
    {
        // The menu rebuilder is held for the ctor-tail bake; commands register
        // and menus bake at the end of the constructor, once every provider
        // dependency exists.
        $this->menuRebuilder = $this->service(MenuRebuilder::class);
    }

    /** The resize/layout controller, the menu/context overlay compositor and the tree operations. */
    private function buildLayoutAndOverlay(): void
    {
        $this->layout = $this->service(LayoutController::class);
    }

    /** The editor workspace: tree, editor + LSP backend, chrome widgets and the main horizontal layout. */
    private function buildEditorArea(): void
    {
        $this->lsp = $this->service(LspSessionPool::class);

        $this->tree = $this->service(FileTreeWidget::class);
        $this->editor = $this->service(CodeEditorWidget::class);
        $this->notifier = $this->service(Notifier::class);
        $this->watchdog = $this->service(FreezeWatchdog::class);

        // The composite tree (layout containers, separators, assembly) lives in
        // WorkspaceLayout; Ide holds the layout and pane controllers for the
        // build steps and the runtime.
        $this->workspaceLayout = $this->service(WorkspaceLayout::class);
        $this->paneLayout = $this->service(PaneLayout::class);
        $this->paneLayout->arrangeEditorArea();
    }

    // --- File handling ----------------------------------------------------------

    private function openFile(string $path): void
    {
        $this->bufferService->openFile($path, OpenFocus::Keep);
    }

    /**
     * Timer entry point — {@see run()}'s 1s EventLoop::repeat reconciles the open
     * buffers and the file tree with the on-disk state (tests fire it directly
     * instead of waiting out the timer).
     */
    public function pollFilesystem(): void
    {
        $this->bufferService->pollFilesystem();
        $this->terminalRefresher->tick();
    }

    // --- LSP -------------------------------------------------------------------
}
