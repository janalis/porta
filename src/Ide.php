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
use Porta\Container\ToolLaunch;
use Porta\Container\ToolMode;
use Porta\Debug\DebugController;
use Porta\Debug\DebugStatePersistence;
use Porta\Editor\BufferService;
use Porta\Editor\ConflictMarkers;
use Porta\Editor\OpenFocus;
use Porta\Editor\PaneRegistrar;
use Porta\Event\DiffToolLaunched;
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Event\MergeToolLaunched;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\History\BookmarkPersistence;
use Porta\History\RecentProjectsStore;
use Porta\History\SessionRestorer;
use Porta\I18n\Translator;
use Porta\Input\InputDispatcher;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspSessionPool;
use Porta\Onboarding\OnboardingState;
use Porta\Overlay\State\TooltipCenter;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Presence\PresenceSync;
use Porta\Run\RunController;
use Porta\Search\SearchUiWiring;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsLanguageController;
use Porta\Support\BootTrace;
use Porta\Support\DiagnosticLog;
use Porta\Support\FreezeWatchdog;
use Porta\Support\PerfCollector;
use Porta\Support\RevoltScheduler;
use Porta\Ui\BidiTui;
use Porta\Ui\Graphics\PixelRatio;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\NonBlockingTerminal;
use Porta\Ui\TerminalBidi;
use Porta\Ui\TerminalRestorer;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Menu\MenuRegistrar;
use Porta\Workbench\Notifier;
use Porta\Workbench\ReopenRequest;
use Porta\Workbench\TerminalStateRefresher;
use Porta\Workbench\View\ViewRegistrar;
use Porta\Workbench\View\ViewRegistry;
use Revolt\EventLoop;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

    private readonly Tui $tui;
    private readonly BidiTui $bidiTui;

    /** @var list<string> the last frame composed, written to .porta/frame.txt at shutdown */
    private array $lastFrame = [];
    private FileTreeWidget $tree;
    private CodeEditorWidget $editor;
    private Notifier $notifier;
    private FreezeWatchdog $watchdog;
    private readonly PerfCollector $perf;
    private readonly TerminalGraphics $graphics;
    private readonly EditorArea $editorArea;
    private readonly TerminalStateRefresher $terminalRefresher;
    private readonly RunController $run;
    private readonly DebugController $debug;

    private readonly PluginLifecycle $pluginLifecycle;
    private readonly string $docsDir;
    private readonly ContainerInterface $container;

    /** @var ServiceLocator<object> The one public boundary; every service() resolution goes through it. */
    private readonly ServiceLocator $locator;
    private WorkspaceLayout $workspaceLayout;
    private PaneLayout $paneLayout;
    private readonly LayoutController $layout;

    private LspSessionPool $lsp;
    private readonly LspBootstrapper $lspBootstrapper;
    private readonly SessionRestorer $sessionRestorer;
    private readonly DebugStatePersistence $debugState;
    private readonly BookmarkPersistence $bookmarkState;

    private readonly Renderer $renderer;
    private readonly MenuRebuilder $menuRebuilder;

    private readonly SettingsController $settings;
    private readonly SettingsLanguageController $settingsLanguages;
    private readonly BufferService $bufferService;

    /** {@see exitCode()} */
    private int $exitCode = 0;

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
        ?BootTrace $bootTrace = null,
        // Trailing and optional, like every late addition here: a launch as git's
        // difftool/mergetool rather than as an editor ({@see ToolLaunch}).
        private readonly ?ToolLaunch $tool = null,
    ) {
        // Boot-phase evidence: the entrypoint hands in a trace whose prelude
        // covers the interpreter and autoload; test rigs get a fresh one so the
        // wiring below never branches.
        $boot = $bootTrace ?? new BootTrace();
        // The shipped user guides (F1), package-relative like the translations.
        $this->docsDir = $docsDir ?? \dirname(__DIR__).'/docs';
        $config = IdeConfig::load($this->rootDir);
        $this->container = new IdeContainerFactory()->build(
            $this->rootDir,
            $config,
            $this->ansi ? Ansi::Enabled : Ansi::Disabled,
            $this->lspCommand,
            // A remote project's cache holds what was opened, not the project:
            // a language server over it would report every class missing.
            $this->lspDisabled || ProjectLocation::forRoot($this->rootDir)->isRemote() ? LspMode::Disabled : LspMode::Enabled,
            $this->docsDir,
        );
        $locator = $this->container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'The porta.ide service is the ServiceLocator boundary');
        $this->locator = $locator;
        // The parsed .porta.json is a synthetic service: it exists before the
        // container does (its settings steer the env parameters above), and
        // the plugin/keybindings services consume it by type.
        $this->container->set(IdeConfig::class, $config);
        $boot->mark('container');
        // The trace is synthetic for the same reason IdeConfig is: it exists
        // before the container does, and PerfCollector consumes it by type to
        // stamp the first paint.
        $this->container->set(BootTrace::class, $boot);
        // Resolving the Keybindings map triggers the one-time plugin load —
        // plugin default keys must merge into the immutable map.
        $keybindings = $this->service(Keybindings::class);
        $boot->mark('plugins');

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
        // The frame-rate cap: an input flood coalesces to 60fps instead of one
        // full frame per wheel event. Wired by setter — BidiTui is built before
        // the container exists; unit rigs that skip this call stay uncapped.
        $this->bidiTui->setFramePacing(new NativeClock(), new RevoltScheduler());
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

        // The resize/layout controller, the menu/context overlay compositor
        // and the tree operations.
        $this->layout = $this->service(LayoutController::class);

        $this->workspaceLayout->mount($this->tui);
        // The chrome roots swept by the live-setting appliers (theme/locale/
        // cursor/tab position) declare themselves with #[AsChromeRoot];
        // ChromeInvalidator injects them as a tagged iterator — no register here.

        // The menu rebuilder is held for the ctor-tail bake; commands register
        // and menus bake at the end of the constructor, once every provider
        // dependency exists.
        $this->menuRebuilder = $this->service(MenuRebuilder::class);
        $this->bufferService = $this->service(BufferService::class);
        $this->run = $this->service(RunController::class);
        $this->lspBootstrapper = $this->service(LspBootstrapper::class);
        $this->sessionRestorer = $this->service(SessionRestorer::class);
        $this->debugState = $this->service(DebugStatePersistence::class);
        $this->bookmarkState = $this->service(BookmarkPersistence::class);
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
        // side effect (core's view controllers register themselves; the
        // presenters hook onBeforeFrame), in the tag's priority order — which
        // keeps the contractual find→picker→toast so a shared-span-slot
        // hand-over clears before it repaints.
        $this->service(EagerServices::class)->boot();
        $boot->mark('eager');
        $this->pluginLifecycle->refreshNav();

        $this->service(SearchUiWiring::class)->wire();
        // Panes before commands: a pane's registration is what makes its
        // routing id answerable, and nothing may open a file before it does.
        // Core's own panes first, so a plugin claiming a built-in id replaces it
        // rather than being replaced by it.
        $this->service(CorePanes::class)->register();
        $this->service(PaneRegistrar::class)->register();
        // After the eager boot below would be too late: a contributed view has
        // to be registered before anything can open it, and core's own two
        // register from their constructors during that boot.
        $this->service(ViewRegistrar::class)->register();
        $this->service(MenuRegistrar::class)->register();
        // AFTER the registrar, not before: a region exists only once its view
        // has minted one, and a plugin's view is built right above. Running
        // this at the eager boot — where it sat while both views were core's —
        // left a contributed view's area mounted and empty, which renders as a
        // blank workspace with no error anywhere.
        $this->paneLayout->applyDirection();
        // Before WidgetHooks: BottomDock::wire() installs a dismiss hook on
        // every registered panel, so the registry has to be populated first.
        $this->service(CoreDockPanels::class)->register();
        $this->service(CommandRegistryPopulator::class)->populate();
        $this->service(WidgetHooks::class)->wire();
        $this->menuRebuilder->rebuild();
        $this->tui->addListener($this->service(InputDispatcher::class)->dispatch(...));
        // The perf collector counts BEFORE the dispatcher: the dispatcher stops
        // propagation on most paths, so at default priority the counter would
        // miss nearly every event.
        $this->perf = $this->service(PerfCollector::class);
        $this->bidiTui->setPerfSink($this->perf);
        $terminal = $this->tui->getTerminal();
        if ($terminal instanceof NonBlockingTerminal) {
            // The backlog probe on the beat and slow-paint lines separates a
            // slow widget tree from a terminal that stopped draining.
            $this->perf->setPendingBytesProbe($terminal->pendingBytes(...));
            // The host answers the kitty keyboard query whenever it likes, and
            // the inner terminal replies with an UNQUEUED write that can land
            // inside a half-flushed frame. Repaint from scratch once it has:
            // the frame writer never repositions absolutely on its own, so a
            // desync there would otherwise last the whole session.
            $terminal->onKittyActivated($this->bidiTui->resyncScreen(...));
        }
        // A support hatch: PORTA_FRAME_DUMP=<path> writes the frame Porta
        // COMPOSED next to whatever the terminal actually drew, so a screen
        // nobody can explain stops being argued from screenshots.
        // A terminal that runs the bidi algorithm itself would reorder a row
        // Porta has already ordered. {@see TerminalBidi} says which, and why
        // the test is by name rather than by probe.
        if (TerminalBidi::isApplied(\getenv())) {
            $this->bidiTui->setTerminalBidi(true);
        }
        // Always REMEMBER the last frame; write it out at shutdown. A screen
        // nobody can explain then leaves its own evidence in .porta/frame.txt
        // next to error.log and perf.log, with nothing to switch on first --
        // five rounds of this were argued from photographs of Hebrew glyphs.
        // PORTA_FRAME_DUMP additionally writes every frame to a chosen path.
        $dump = \getenv('PORTA_FRAME_DUMP');
        $target = \is_string($dump) && '' !== $dump ? $dump : null;
        $this->bidiTui->setFrameSink(function (array $lines) use ($target): void {
            $this->lastFrame = \array_map(AnsiUtils::stripAnsiCodes(...), $lines);
            if (null !== $target) {
                \file_put_contents($target, \implode("\n", $this->lastFrame));
            }
        });
        $this->tui->addListener($this->perf->countInput(...), priority: 1000);
        // Ahead of the counter and the dispatcher: the graphics probe's
        // replies arrive as input, and must not count as, or act as, keys.
        $this->graphics = $this->service(TerminalGraphics::class);
        $this->tui->addListener($this->graphics->onInput(...), priority: 2000);
        // Last: hand the plugin handlers their live runtime seam and flash the
        // config/plugin load warnings, once every service they reach exists.
        $this->service(StartupWiring::class)->wire();
        $boot->mark('wired');
    }

    /**
     * Leave the last composed frame in `.porta/frame.txt`.
     *
     * The counterpart to a screenshot: that says what the terminal drew, this
     * says what Porta composed, and a disagreement between them names which
     * half is at fault without anyone having to read Hebrew off a photograph.
     * Best-effort — a session ending badly must not also fail here.
     */
    private function writeLastFrame(): void
    {
        if ([] === $this->lastFrame) {
            return;
        }

        // No error control operator: the directory is the one Porta already
        // writes error.log and perf.log into, so if it is there the write is
        // ordinary, and if it is not there is nothing to suppress.
        $dir = $this->rootDir.'/.porta';
        if (\is_dir($dir)) {
            \file_put_contents($dir.'/frame.txt', \implode("\n", $this->lastFrame));
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
        $this->service(ViewRegistry::class)->open('welcome');
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

    /**
     * Where to reopen once this session ends, or null on an ordinary quit.
     *
     * Read by the console entrypoint AFTER {@see run()} returns, which is the
     * only moment it can be acted on: the terminal is restored, the session is
     * written, and this process is free to become an IDE for somewhere else.
     * Not "the buffers are saved" — this said that, and the teardown has never
     * written buffer contents. The confirm for what that costs lives in
     * {@see \Porta\Workbench\ProjectSwitcher}, before the request is made.
     */
    /**
     * What the process should exit with: 0, except after a merge-tool run whose
     * `$MERGED` still holds a conflict block (or cannot be read) — git's
     * `mergetool.<tool>.trustExitCode` then leaves the file unresolved.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function reopenAt(): ?string
    {
        return $this->service(ReopenRequest::class)->root();
    }

    public function run(): void
    {
        $this->lspBootstrapper->start();

        // Poll the filesystem so external edits, branch switches and deletions are
        // reflected in the open buffers and the tree without a manual reload. The
        // timers only fire once the event loop runs (inside tui->run()), so it is
        // safe to arm them before setup; arming them before the try guarantees the
        // finally can always cancel them. The buffer/tree poll runs as bounded
        // SLICES at 4Hz (full coverage still lands within ~1s at typical sizes)
        // because the old full pass was the proven loop-stall in .porta/freeze.log;
        // the terminal-state refresher keeps its own tick-counting 1s cadence.
        $watcher = EventLoop::repeat(0.25, $this->bufferService->pollFilesystemSlice(...));
        $refresher = EventLoop::repeat(1.0, $this->terminalRefresher->tick(...));
        // A remote project's server is asked, much more slowly, what changed
        // there; for a local project each tick returns at once.
        $remote = $this->service(RemoteFilesystem::class);
        $remotePoll = EventLoop::repeat(RemoteFilesystem::POLL_SECONDS, $remote->poll(...));
        // …and once at once: the tree was built before the plugins that reach
        // the server activated, so its first listing waits for this.
        $remoteFirst = EventLoop::defer($remote->poll(...));
        // The other Porta instances on this project, through the board they
        // share. Not for a git tool run: that is nobody at work here.
        $presence = $this->service(PresenceSync::class);
        if (null === $this->tool) {
            $presence->start();
        }
        // Deferred, so it runs once tui->run() has started the terminal: the
        // answers come back as INPUT, which needs raw mode and the stdin
        // watcher. A pane paints half-blocks until they have arrived.
        $graphicsProbe = EventLoop::defer(function (): void {
            $this->graphics->probe(\getenv());
            $this->service(PixelRatio::class)->probe(\getenv());
        });

        // Loop-stall detector: evidence for "the app froze" reports lands in
        // .porta/freeze.log (drift lines + a backtrace on a hard hang).
        $this->watchdog->start();

        // Render/input evidence: one stats line per active second lands in
        // .porta/perf.log, so a "feels laggy" report carries its own numbers.
        $this->perf->start();

        // A termination signal must unwind through the finally below, not kill
        // the process mid-alternate-screen with mouse reporting still on:
        // stopping the Tui resumes its run suspension, so run() returns and
        // the ordinary teardown executes. Unreferenced, because a referenced
        // signal watcher would keep an otherwise-finished loop alive.
        $signals = [];
        foreach ([\SIGTERM, \SIGINT, \SIGHUP] as $signal) {
            $id = EventLoop::onSignal($signal, function (): void {
                $this->tui->stop();
            });
            EventLoop::unreference($id);
            $signals[] = $id;
        }
        // The net for what no finally can catch — a fatal error kills the
        // engine without unwinding: process shutdown re-emits the teardown
        // sequence unless the finally disarmed the net first.
        $restorer = new TerminalRestorer();
        $restorer->arm($this->tui->getTerminal());

        // One throwing loop callback — a keypress handler, a poll tick, a
        // subprocess exit — must not take the session and its unsaved buffers
        // with it: log the evidence, flash, and keep the loop alive. Deferring
        // to a pre-installed handler is what lets the test rig keep its
        // rethrow-and-fail policy underneath the same run() path.
        $ownsErrorHandler = null === EventLoop::getErrorHandler();
        if ($ownsErrorHandler) {
            $errors = DiagnosticLog::forRoot($this->rootDir, 'error');
            EventLoop::setErrorHandler(function (\Throwable $error) use ($errors): void {
                $errors->append(\sprintf("%s: %s\n%s", $error::class, $error->getMessage(), $error->getTraceAsString()));
                $this->notifier->error($this->service(Translator::class)->trans('Internal error — details in .porta/error.log'));
            });
        }

        try {
            // Enter the alternate screen so quitting restores the terminal exactly
            // as it was before launch (like vim/htop). Plus SGR mouse reporting:
            // 1000 (button press/release), 1003 (any-event motion — a superset of
            // 1002's button-held drag, so we also see bare hover-moves for row
            // highlighting) and 1006 (SGR coordinates). VirtualTerminal records
            // these; tests feed synthetic seqs. Paired teardown is in the finally.
            $this->tui->getTerminal()->write("\x1b[?1049h\x1b[?1000h\x1b[?1003h\x1b[?1006h");
            // Home the cursor the alternate screen just handed us. The first
            // frame is appended FROM the cursor, not written to an absolute
            // origin, and ScreenWriter's row bookkeeping starts from zero -- so
            // a cursor left anywhere below row 0 costs that many rows off the
            // top and desynchronises every RELATIVE repaint after it, for the
            // rest of the session. Measured in a real pty: one row of scroll,
            // and the menu bar gone.
            $this->tui->getTerminal()->clearScreen();

            $this->layout->applyLayout();

            // Before any buffer opens: the boot-time BufferSwitched pass then
            // paints restored breakpoints into the gutter for free.
            $this->debugState->restore();
            $this->bookmarkState->restore();

            // This project is now the most recent one, for the switcher's list.
            // Recorded HERE rather than from an IdeReady listener: core does not
            // listen to the lifecycle moments — they exist for observers, and a
            // gate holds them to it — and recorded at READY rather than at
            // construction so a project that fails to boot never displaces a
            // working one at the top of the list.
            // A git tool run is not the user opening their project: it leaves
            // the recent list, the session and the Welcome exactly as they were.
            if (null === $this->tool) {
                $this->service(RecentProjectsStore::class)->record($this->rootDir);
            }

            // Same reason, for observers: IdeReady lands before the first
            // BufferOpened instead of racing it.
            $this->service(EventDispatcherInterface::class)->dispatch(new IdeReady($this->rootDir));

            if (null !== $this->tool) {
                $this->startTool($this->tool);
                $this->tui->run();

                return;
            }

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
            // it takes focus over them, and so Esc reveals a settled editor.
            $this->showWelcomeOnFirstRun();

            $this->tui->run();
        } finally {
            // Observers first, while every service they might touch is still
            // up. The loop has stopped, so a listener that schedules work is a
            // no-op — that is the contract IdeShuttingDown documents.
            $this->service(EventDispatcherInterface::class)->dispatch(new IdeShuttingDown($this->rootDir));
            // Then: closing the view windows cancels their timers (the
            // git view's 2s status-poll repeat) so nothing re-arms below.
            $this->service(ViewRegistry::class)->closeAll();
            $this->writeLastFrame();
            $this->watchdog->stop();
            $this->perf->stop();
            EventLoop::cancel($watcher);
            EventLoop::cancel($refresher);
            EventLoop::cancel($remotePoll);
            EventLoop::cancel($remoteFirst);
            $remote->close();
            $presence->stop();
            EventLoop::cancel($graphicsProbe);
            foreach ($signals as $signal) {
                EventLoop::cancel($signal);
            }
            if ($ownsErrorHandler) {
                EventLoop::setErrorHandler(null);
            }
            // Disable mouse reporting, then leave the alternate screen (restoring
            // the pre-launch terminal). Guaranteed even if startup above threw.
            // Images live in the terminal, not in the alternate screen: free them.
            $this->graphics->release();
            $this->service(PixelRatio::class)->release();
            $this->tui->getTerminal()->write(TerminalRestorer::RESTORE);
            $restorer->disarm();
            if (null === $this->tool) {
                $this->sessionRestorer->save();
                $this->debugState->save();
                $this->bookmarkState->save();
            }
            if (ToolMode::Merge === $this->tool?->mode) {
                $this->exitCode = self::resolved($this->tool->editable()) ? 0 : 1;
            }
            $this->notifier->dismissAll(); // cancel pending toast timers — nothing may outlive the loop
            $this->service(TooltipCenter::class)->dismiss(); // and a tooltip's dwell
            $this->lsp->stop();
            $this->run->stop();
            $this->debug->stop();
        }
    }

    // --- Wiring ---------------------------------------------------------------

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

    /**
     * Hand the launch to whoever answers it — the git plugin — or, with nobody
     * listening, open the file that matters in the editor and say why.
     */
    private function startTool(ToolLaunch $tool): void
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);
        [$first, $second] = $tool->paths;
        $event = ToolMode::Merge === $tool->mode
            ? new MergeToolLaunched($first, $second, $tool->paths[2] ?? '', $tool->paths[3] ?? '')
            : new DiffToolLaunched($first, $second);
        if ($dispatcher->hasListeners($event::class)) {
            $dispatcher->dispatch($event);

            return;
        }
        // A --dir-diff hands over two directories: nothing to open, only to say.
        if (\is_file($tool->editable())) {
            $this->openFile($tool->editable());
            $this->tui->setFocus($this->editorArea->widget());
        }
        $this->notifier->warn($this->service(Translator::class)->trans('Enable the git plugin to see the comparison'));
    }

    /** Whether $path is readable and holds no conflict block. */
    private static function resolved(string $path): bool
    {
        $text = \is_file($path) ? \file_get_contents($path) : false;

        return false !== $text && [] === ConflictMarkers::find(\explode("\n", $text));
    }

    private function openFile(string $path): void
    {
        $this->bufferService->openFile($path, OpenFocus::Keep);
    }

    /**
     * The synchronous full pass: reconcile every open buffer and the file tree
     * with the on-disk state, and advance the terminal-state refresher. The
     * live timers in {@see run()} take bounded slices instead; tests fire this
     * directly so an external edit's effect is observable immediately.
     */
    public function pollFilesystem(): void
    {
        $this->bufferService->pollFilesystem();
        $this->terminalRefresher->tick();
    }

    // --- LSP -------------------------------------------------------------------
}
