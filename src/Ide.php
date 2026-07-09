<?php

declare(strict_types=1);

namespace Porta;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsKeymapController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Container\IdeContainerFactory;
use Porta\Editor\BreadcrumbScanner;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Git\GitCommands;
use Porta\Git\GitInfo;
use Porta\Git\GitPanes;
use Porta\Git\GitViewCallbacks;
use Porta\Git\GitViewController;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\TerminalThemeFactory;
use Porta\History\LocalHistory;
use Porta\History\LocalHistoryController;
use Porta\History\SessionStore;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Input\EditorLayoutPanes;
use Porta\Input\GitMouseRouter;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;
use Porta\Input\OverlayPanels;
use Porta\Input\SettingsMouseRouter;
use Porta\Layout\LayoutController;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspMapper;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\ServerLocator;
use Porta\Lsp\WorkspaceEditor;
use Porta\Lsp\WorkspaceSymbolClient;
use Porta\Overlay\MenuOverlayController;
use Porta\Overlay\MenuOverlayPanes;
use Porta\Run\RunConfigs;
use Porta\Run\RunController;
use Porta\Search\Everywhere\ActionsSource;
use Porta\Search\Everywhere\FilesSource;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\TextSource;
use Porta\Search\Everywhere\WorkspaceSymbolSource;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Settings\SettingsViewController;
use Porta\Support\AsyncProcess;
use Porta\Support\BrowserOpener;
use Porta\Support\ProjectPaths;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Tree\TreeOperations;
use Porta\Ui\BidiTui;
use Porta\Ui\CommandPalette;
use Porta\Ui\ContextMenuBuilder;
use Porta\Ui\MenuModel;
use Porta\Ui\Notifier;
use Porta\Ui\PaletteFactory;
use Porta\Ui\PanelManager;
use Porta\Ui\PaneSegmenter;
use Porta\Ui\ResultsPanelMode;
use Porta\Ui\SwitchablePalette;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\BufferCallbacks;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\CompletionCallbacks;
use Porta\Widget\ContextMenu;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\FileActionCallbacks;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\NavigationCallbacks;
use Porta\Widget\RefactorCallbacks;
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
use Symfony\Component\Tui\Event\InputEvent;
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
    private PaneSegmenter $paneSegmenter;
    private PaneReorder $paneReorder;
    private FileTreeWidget $tree;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;
    private Notifier $notifier;
    private MenuBarWidget $menuBar;
    private TabBarWidget $tabBar;
    private BreadcrumbsWidget $breadcrumbs;
    private SeparatorWidget $separator;
    private FindBarWidget $findBar;
    private GoToFileWidget $goToFile;
    private ResultsPanelWidget $resultsPanel;
    private ProjectSearchController $projectSearchController;
    private CommandPalette $commandPalette;
    private TerminalPanelWidget $terminalPanel;
    private RunController $run;
    private readonly ContainerInterface $container;
    private GitInfo $git;
    private GitCommands $gitCommands;
    private ?string $gitBranch = null;
    private LocalHistory $history;
    private LocalHistoryController $localHistory;
    private SessionStore $session;
    private LayoutController $layout;
    private TreeOperations $treeOps;
    private BufferManager $buffers;
    private ?string $searchDebounce = null;
    private PanelManager $panels;
    private CommandRegistry $commands;
    private SearchEverywhereController $searchEverywhere;

    private LspSessionPool $lsp;
    private WorkspaceEditor $workspaceEditor;
    private CompletionController $completion;
    private NavigationController $navigation;
    private RefactorController $refactor;
    private float $lastQuitPress = 0.0;

    private Renderer $renderer;
    private ContextMenu $contextMenu;

    /** Positions + composites the app-menu and context-menu floating overlays. */
    private MenuOverlayController $overlay;

    private Keybindings $keybindings;
    private Translator $translator;
    private ContainerWidget $main;
    private ContainerWidget $gitAreaContainer;
    private ContainerWidget $settingsAreaContainer;
    /** @var list<AbstractWidget> the main editor layout's panes, in canonical LTR order (sidebar first) */
    private array $editorPanes;
    /** @var list<AbstractWidget> the git view's panes, in canonical LTR order (sidebar first) */
    private array $gitPanes;
    /** @var list<AbstractWidget> the settings view's panes, in canonical LTR order (sidebar first) */
    private array $settingsPanes;
    private GitViewController $gitView;
    private SettingsViewController $settingsView;
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
    private readonly SwitchableTerminalTheme $syntaxTheme;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $initialFile = null,
        ?TerminalInterface $terminal = null,
        ?Renderer $renderer = null,
        private readonly ?int $initialLine = null,
        private readonly ?string $lspCommand = null,
        private readonly bool $lspDisabled = false,
        private readonly bool $ansi = true,
    ) {
        $config = IdeConfig::load($this->rootDir);
        $this->container = new IdeContainerFactory()->build($this->rootDir, (string) ($config->settings['language'] ?? 'en'), $this->ansi, (string) ($config->settings['theme'] ?? 'dark'));
        $this->translator = $this->service(Translator::class);
        // The live-swappable theme holders (see applyTheme): widgets and buffers
        // hold these same instances via the Palette/TerminalTheme aliases.
        $this->palette = $this->service(SwitchablePalette::class);
        $this->syntaxTheme = $this->service(SwitchableTerminalTheme::class);
        $keybindings = new Keybindings(array_merge(ActionCatalog::ideDefaults(), $config->keymap));
        $this->keybindings = $keybindings;

        // Own the Renderer so mouse hit-testing can query widget rects.
        $this->renderer = $renderer ?? new Renderer($this->buildStyleSheet());
        $this->bidiTui = new BidiTui(
            terminal: $terminal ?? new Terminal(),
            keybindings: $keybindings,
            renderer: $this->renderer,
        );
        $this->tui = $this->bidiTui;
        // Hand the manually-built infrastructure to the container as synthetic
        // services (declared synthetic in config/services.php) right after each
        // is created, so container-built controllers can resolve them by type.
        $this->container->set(Renderer::class, $this->renderer);
        $this->container->set(Tui::class, $this->tui);

        $this->buildEditorArea();

        $this->buildSidePanels();

        $this->git = $this->service(GitInfo::class);
        $this->gitCommands = new GitCommands(
            $this->git,
            $this->editor,
            $this->notifier,
            $this->tui,
            $this->translator,
            fn () => $this->refreshActiveGitMarks(),
            fn () => $this->refreshBranch(),
            fn (string $path) => $this->relativePath($path),
        );
        $this->buildGitView();

        $this->buildSettingsWidgets();

        $this->history = $this->service(LocalHistory::class);
        $this->session = $this->service(SessionStore::class);
        $this->buildLayoutAndOverlay();

        $this->tui
            ->add($this->menuBar)
            ->add($this->goToFile)
            ->add($this->main)
            ->add($this->findBar)
            ->add($this->resultsPanel)
            ->add($this->terminalPanel)
            ->add($this->contextMenu)
            ->add($this->statusBar);

        $this->wireWidgets($keybindings);
        $this->wireStatusBar();
        $this->buildSearchEverywhere();

        $this->bufferService = new BufferService(
            $this->buffers,
            $this->editor,
            $this->tree,
            $this->notifier,
            $this->tui,
            $this->lsp,
            $this->completion,
            $this->history,
            $this->service(EditorConfigResolver::class),
            $this->rootDir,
            (bool) ($config->settings['editorConfig'] ?? true),
            $this->translator,
            fn (Buffer $buffer) => $this->refreshGitMarks($buffer),
            fn () => $this->refreshBranch(),
        );

        // Apply persisted settings. bufferService is built first: applyAll() fires
        // the editorConfig apply closure below, which re-resolves open buffers.
        $this->settings = new SettingsController(
            $config->settings,
            $this->editor,
            $this->notifier,
            $this->rootDir,
            $this->translator,
            fn (string $locale) => $this->applyLanguage($locale),
            fn (string $theme) => $this->applyTheme($theme),
            fn (bool $enabled) => $this->bufferService->setEditorConfigEnabled($enabled),
        );
        $this->settings->applyAll();
        $this->settingsLanguages = new SettingsLanguageController($this->editor, $this->notifier, $this->rootDir, $this->translator, $config->languages);
        $this->settingsLanguages->applyToEditor();
        $this->settingsView = new SettingsViewController(
            $this->settingsNav,
            $this->settingsForm,
            $this->settingsKeys,
            $this->main,
            $this->settingsAreaContainer,
            $this->settingsContent,
            $this->menuBar,
            fn (): array => $this->orderPanes($this->editorPanes),
            $this->tui,
            $this->settings,
            new SettingsKeymapController($this->editor, $this->keybindings, $this->notifier, $this->rootDir, $this->translator),
            $this->settingsLanguages,
            new SettingsLspController($this->rootDir, $this->lsp, $config->lspServers, $this->lspCommand, fn () => $this->reopenLspBuffers(), $this->notifier, $this->translator),
            onClosed: function (): void {
                $this->tui->setFocus($this->editor);
                $this->tui->requestRender();
            },
        );

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

        $hit = $this->service(HitTester::class);
        $this->mouseRouter = new MouseRouter(
            $hit,
            $this->tui,
            new EditorLayoutPanes($this->tree, $this->tabBar, $this->editor, $this->breadcrumbs),
            new OverlayPanels($this->terminalPanel, $this->resultsPanel, $this->goToFile),
            $this->menuBar,
            $this->contextMenu,
            $this->layout,
            $this->overlay,
            $this->buffers,
            $this->bufferService,
            $this->treeOps,
            new ContextMenuBuilder($this->commands, $this->editor, $this->keybindings, $this->bufferService, $this->translator),
            new GitMouseRouter($hit, $this->tui, $this->gitChanges, $this->gitDiff, $this->gitCommitBox, $this->gitHistory, $this->gitTabs, $this->gitSeparator),
            fn (): bool => $this->gitView->isOpen(),
            new SettingsMouseRouter($hit, $this->tui, $this->settingsNav, $this->settingsForm, $this->settingsKeys, $this->settingsSeparator),
            fn (): bool => $this->settingsView->isOpen(),
            fn (int $row, int $col): int => $this->bidiTui->logicalColumn($row, $col),
        );

        // Now that both view controllers exist, settle the layout for the
        // persisted locale's writing direction (RTL mirrors the sidebar right).
        $this->applyDirection();

        foreach ($config->warnings as $warning) {
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
        $service = $this->container->get($id);
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
        $this->startLsp();
        $this->refreshBranch();

        // Poll the filesystem so external edits, git checkouts and deletions are
        // reflected in the open buffers and the tree without a manual reload. The
        // timer only fires once the event loop runs (inside tui->run()), so it is
        // safe to arm before setup; arming it before the try guarantees the finally
        // can always cancel it.
        $watcher = EventLoop::repeat(1.0, fn () => $this->pollFilesystem());

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
                $this->tui->setFocus($this->editor);
            } elseif (!$this->restoreSession()) {
                $this->tui->setFocus($this->tree);
            }

            $this->tui->run();
        } finally {
            EventLoop::cancel($watcher);
            // Disable mouse reporting, then leave the alternate screen (restoring
            // the pre-launch terminal). Guaranteed even if startup above threw.
            $this->tui->getTerminal()->write("\x1b[?1000l\x1b[?1003l\x1b[?1006l\x1b[?1049l");
            $this->saveSession();
            $this->lsp->stop();
            $this->git->cancelAll();
            $this->run->stop();
        }
    }

    /**
     * Reopen the previous session's buffers. Returns true if any were opened.
     */
    private function restoreSession(): bool
    {
        $data = $this->session->load();
        if (null === $data || [] === $data['buffers']) {
            return false;
        }

        $opened = false;
        foreach ($data['buffers'] as $buffer) {
            if (!is_file($buffer['path'])) {
                continue;
            }
            $this->openFile($buffer['path']);
            $active = $this->buffers->active();
            if (null !== $active) {
                $active->document->setCursor($buffer['line'], $buffer['col']);
            }
            $opened = true;
        }

        if ($opened && null !== $data['active'] && $this->buffers->has(realpath($data['active']) ?: $data['active'])) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive(realpath($data['active']) ?: $data['active']);
            $this->showBuffer($active);
        }
        if ($opened) {
            $this->tui->setFocus($this->editor);
        }

        return $opened;
    }

    private function saveSession(): void
    {
        $buffers = [];
        foreach ($this->buffers->all() as $buffer) {
            $buffers[] = [
                'path' => $buffer->path,
                'line' => $buffer->document->getCursorLine(),
                'col' => $buffer->document->getCursorCol(),
            ];
        }
        $this->session->save($buffers, $this->buffers->active()?->path);
    }

    // --- Wiring ---------------------------------------------------------------

    private function wireWidgets(Keybindings $keybindings): void
    {
        $this->wireEditor();
        $this->wireFindAndGoto();
        $this->wireResultsPanel();
        $this->wireInputDispatch($keybindings);
        $this->wirePanels();
    }

    /** The file tree's open hook and the editor's outbound callback struct. */
    private function wireEditor(): void
    {
        $this->tree->onOpenFile(fn (string $path) => $this->openFile($path, focusEditor: true));

        // The editor's outbound hooks, built once: file lifecycle, buffer
        // switching, and every LSP-powered feature routed to its controller.
        $this->editor->setCallbacks(new EditorCallbacks(
            file: new FileActionCallbacks(
                onSaveRequested: fn () => $this->saveCurrentFile(),
                onSaveAllRequested: fn () => $this->saveAllBuffers(),
                // Mirror editor copies to the system clipboard via OSC 52
                onCopy: fn (string $text) => $this->copyToClipboard($text),
                onFindRequested: function (bool $withReplace): void {
                    $initial = $this->editor->getDocument()->getSelectedText();
                    $this->findBar->open(
                        $this->editor->getDocument(),
                        $withReplace,
                        str_contains($initial, "\n") ? '' : $initial,
                    );
                    $this->tui->setFocus($this->findBar);
                    $this->tui->requestRender();
                },
                onGotoFile: function (): void {
                    $this->goToFile->open();
                    $this->tui->setFocus($this->goToFile);
                    $this->tui->requestRender();
                },
            ),
            navigation: new NavigationCallbacks(
                onDefinitionRequested: fn (int $line, int $utf16Col) => $this->navigation->requestDefinition($line, $utf16Col),
                onImplementationRequested: fn (int $line, int $utf16Col) => $this->navigation->requestImplementation($line, $utf16Col),
                onTypeDefinitionRequested: fn (int $line, int $utf16Col) => $this->navigation->requestTypeDefinition($line, $utf16Col),
                onNavigateBack: fn () => $this->navigation->navigateBack(),
                // LSP-powered features: hover, usages, structure, peek
                onQuickDoc: fn (int $line, int $col) => $this->navigation->requestHover($line, $col),
                onFindUsages: fn (int $line, int $col) => $this->navigation->requestReferences($line, $col),
                onFileStructure: fn () => $this->navigation->requestFileStructure(),
                onPeek: fn (int $line, int $col) => $this->navigation->requestPeek($line, $col),
            ),
            completion: new CompletionCallbacks(
                onCompletionRequested: fn (int $line, int $utf16Col) => $this->completion->requestCompletion($line, $utf16Col),
                onCompletionResolve: /** @param array<string, mixed> $item */ fn (array $item) => $this->completion->resolveCompletion($item),
                onSignatureHelp: fn (int $line, int $col) => $this->completion->requestSignatureHelp($line, $col),
                onChanged: fn (Document $doc) => $this->completion->scheduleDidChange($doc),
            ),
            refactor: new RefactorCallbacks(
                onRenameRequested: fn (int $line, int $col, string $word) => $this->refactor->requestPrepareRename($line, $col, $word),
                onCodeAction: fn (int $line, int $col) => $this->refactor->requestCodeActions($line, $col),
                onReformat: fn () => $this->refactor->requestReformat(),
            ),
            buffer: new BufferCallbacks(
                onBufferCycle: function (int $offset): void {
                    if (null !== ($buffer = $this->buffers->cycle($offset))) {
                        $this->showBuffer($buffer);
                    }
                },
                onRecentFiles: function (): void {
                    $recent = $this->buffers->recent();
                    if (\count($recent) < 2) {
                        return;
                    }
                    $items = array_map(fn (Buffer $b) => [
                        'label' => basename($b->path).($b->document->isDirty() ? ' ●' : ''),
                        'hint' => $this->relativePath($b->path),
                        'value' => $b->path,
                    ], $recent);
                    // Pre-select the previous file, like PhpStorm's Ctrl+E
                    $items[] = $items[0];
                    array_shift($items);

                    $this->editor->openListOverlay('Recent Files', $items, function (string $path): void {
                        if (null !== ($buffer = $this->buffers->setActive($path))) {
                            $this->showBuffer($buffer);
                        }
                    });
                    $this->tui->requestRender();
                },
                onCloseBuffer: fn () => $this->closeActiveBuffer(),
            ),
        ));
    }

    /** Find bar and go-to-file palette: search-match mirroring and focus flow. */
    private function wireFindAndGoto(): void
    {
        $this->findBar->onUpdate(function (): void {
            $this->editor->setSearchMatches($this->findBar->getMatchesByLine());
            $this->tui->requestRender();
        });

        $this->findBar->onClose(function (): void {
            $this->editor->setSearchMatches([]);
            $this->tui->setFocus($this->editor);
            $this->tui->requestRender();
        });

        $this->goToFile->onPick(function (string $path): void {
            $this->openFile($path, focusEditor: true);
        });

        $this->goToFile->onClose(function (): void {
            if ($this->tui->getFocus() === $this->goToFile) {
                $this->tui->setFocus($this->editor);
            }
            $this->tui->requestRender();
        });
    }

    /** The shared bottom results panel: live queries, toggles, jumps, close. */
    private function wireResultsPanel(): void
    {
        // The results panel serves two live-query modes (project text search
        // and workspace symbol search); the PanelManager's mode selects the handler.
        $this->resultsPanel->onQueryChanged(function (string $query): void {
            if (null !== $this->searchDebounce) {
                EventLoop::cancel($this->searchDebounce);
            }
            $this->searchDebounce = null;
            if (ResultsPanelMode::Action === $this->panels->mode()) {
                // The command palette filters synchronously (no I/O).
                $this->commandPalette->filter($query);

                return;
            }
            $this->resultsPanel->setSearching();
            $this->searchDebounce = EventLoop::delay(0.25, function () use ($query): void {
                $this->searchDebounce = null;
                if (ResultsPanelMode::Symbol === $this->panels->mode()) {
                    $this->navigation->requestWorkspaceSymbols($query);
                } else {
                    $this->projectSearchController->search($query);
                }
            });
        });

        $this->resultsPanel->onToggleOption(function (string $name): void {
            $this->projectSearchController->toggleOption($name);
        });

        $this->resultsPanel->onReplaceAll(function (string $replacement): void {
            $this->projectSearchController->replaceAll($replacement);
        });

        $this->resultsPanel->onJump(function (string $relativePath, int $line): void {
            if (ResultsPanelMode::Action === $this->panels->mode()) {
                $this->commandPalette->runAt($line);

                return;
            }
            if (ResultsPanelMode::History === $this->panels->mode()) {
                $this->localHistory->restore($line);

                return;
            }
            $this->openFile($this->rootDir.'/'.$relativePath, focusEditor: true);
            $this->editor->jumpTo($line, 0);
            $this->tui->requestRender();
        });

        $this->resultsPanel->onClose(function (): void {
            $this->projectSearchController->cancel();
            $this->panels->focusEditor();
        });
    }

    /** Command registry, menu bar, and the raw input listener (mouse + key-bound commands). */
    private function wireInputDispatch(Keybindings $keybindings): void
    {
        $this->wireCommands();
        $this->wireMenuBar($keybindings);
        $this->tui->addListener(function (InputEvent $event) use ($keybindings): void {
            $data = $event->getData();

            if (null !== ($mouse = MouseEvent::parse($data))) {
                $event->stopPropagation();
                $this->mouseRouter->route($mouse);

                return;
            }

            // An open context menu captures the keyboard modally: arrows/Enter/Esc
            // drive it and every other key is swallowed, so nothing leaks to the
            // widget that still owns focus underneath it.
            if ($this->contextMenu->isOpen()) {
                $event->stopPropagation();
                $this->contextMenu->handleInput($data);
                $this->tui->requestRender();

                return;
            }

            // The Search Everywhere modal likewise captures the keyboard while
            // open, so its query field and result navigation take every key.
            if ($this->editor->isSearchOverlayOpen()) {
                $event->stopPropagation();
                $this->editor->handleSearchOverlayInput($data);
                $this->tui->requestRender();

                return;
            }

            foreach ($this->commands->keyBoundIds() as $id) {
                if ($keybindings->matches($data, $id)) {
                    $event->stopPropagation();
                    $this->commands->run($id);

                    return;
                }
            }
        });
    }

    /** Terminal and diff panels: input forwarding and focus restoration on close. */
    private function wirePanels(): void
    {
        $this->terminalPanel->onInputForward(fn (string $data) => $this->run->forwardInput($data));
        $this->terminalPanel->onClose(function (): void {
            if ($this->tui->getFocus() === $this->terminalPanel) {
                $this->tui->setFocus($this->editor);
            }
            $this->tui->requestRender();
        });
    }

    /**
     * The caret's scope path for the breadcrumbs bar. The PHP scan is memoized by
     * document version so caret-only moves (the common case) just re-select from
     * the cached scopes; non-PHP files show no bar.
     *
     * @return list<array{label: string, line: int}>
     */
    private function breadcrumbTrail(): array
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !str_ends_with($path, '.php')) {
            return [];
        }

        return BreadcrumbScanner::select($this->editor->scopes(), $this->editor->getDocument()->getCursorLine());
    }

    /**
     * Route a mouse event by hit-testing the tree, tab bar and editor rects
     * populated by the Renderer on the last frame.
     */
    /** Move the active tab $offset positions in the strip (keyboard reordering). */
    private function moveTab(int $offset): void
    {
        if ($this->buffers->moveActive($offset)) {
            $this->tui->requestRender();
        }
    }

    /** Copy $text to the system clipboard via OSC 52 (skipping oversized payloads). */
    private function copyToClipboard(string $text): void
    {
        if (\strlen($text) < 100_000) {
            $this->tui->getTerminal()->write("\x1b]52;c;".base64_encode($text)."\x07");
        }
    }

    /**
     * Open a documentation URL in the system browser (native-symbol docs from
     * go-to-declaration). Without an opener on PATH, flash the URL itself —
     * most terminals auto-link it.
     */
    private function openDocsUrl(string $url): void
    {
        $command = BrowserOpener::command($url);
        if (null === $command) {
            $this->notifier->flash($this->translator->trans('Docs: %url%', ['%url%' => $url]), 5.0);

            return;
        }
        new AsyncProcess($command, $this->rootDir)->start(static function (): void {});
        $this->notifier->flash($this->translator->trans('Opened %url%', ['%url%' => $url]), 3.0);
    }

    private function wireStatusBar(): void
    {
        $this->statusBar->setProvider(function (): array {
            $doc = $this->editor->getDocument();
            $path = $this->editor->getFilePath();

            $left = null === $path
                ? $this->translator->trans('porta — open a file from the tree (Enter)')
                : $this->relativePath($path).($doc->isDirty() ? ' ●' : '');

            if (null !== $this->gitBranch) {
                $left = "\x1b[38;5;114m\u{e0a0} {$this->gitBranch}\x1b[39m  ".$left;
            }

            if (null !== ($diagnostic = $this->editor->getCursorDiagnostic())) {
                $left .= '  '."\x1b[38;5;203m".$diagnostic."\x1b[39m";
            }

            $counts = $this->editor->getDiagnosticCounts();
            $problems = '';
            if ($counts['errors'] > 0) {
                $problems .= "\x1b[38;5;203m✗ {$counts['errors']}\x1b[39m ";
            }
            if ($counts['warnings'] > 0) {
                $problems .= "\x1b[38;5;179m▲ {$counts['warnings']}\x1b[39m ";
            }

            $location = \sprintf('%d:%d', $doc->getCursorLine() + 1, $doc->getCursorDisplayCol() + 1);
            $legend = $this->translator->trans('LSP:%status%  ^S save ^T panel ^Space complete ^Q quit', [
                '%status%' => $this->lsp->statusFor($this->editor->getFilePath()),
            ]);
            $right = $problems.$location.'  '.$legend;

            return ['left' => $left, 'right' => $right];
        });
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
        $this->settingsSeparator = new SeparatorWidget();
        $this->settingsSeparator->setId('settings-separator')->setStyle(new Style(flex: 0));
        $this->settingsContent = new ContainerWidget();
        $this->settingsContent->setId('settings-content')->setStyle(new Style(flex: 1));
        $this->settingsContent->expandVertically(true);
        $this->settingsAreaContainer = new ContainerWidget();
        $this->settingsAreaContainer->setId('settings-area');
        $this->settingsAreaContainer->setStyle(new Style(direction: Direction::Horizontal, gap: 0));
        $this->settingsAreaContainer->expandVertically(true);
        $this->settingsPanes = [$this->settingsNav, $this->settingsSeparator, $this->settingsContent];
        $this->setPanes($this->settingsAreaContainer, $this->settingsPanes);
    }

    /** Construct the git-view widgets, their horizontal pane container and the controller. */
    /** The resize/layout controller, the menu/context overlay compositor and the tree operations. */
    private function buildLayoutAndOverlay(): void
    {
        $this->layout = $this->service(LayoutController::class);
        $this->overlay = new MenuOverlayController(
            $this->renderer,
            $this->menuBar,
            $this->contextMenu,
            $this->editor,
            new MenuOverlayPanes(
                [$this->tree, $this->separator, $this->tabBar, $this->editor, $this->breadcrumbs],
                [$this->gitTabs, $this->gitChanges, $this->gitCommitBox, $this->gitHistory, $this->gitSeparator, $this->gitDiff],
                [$this->settingsNav, $this->settingsSeparator, $this->settingsForm, $this->settingsKeys],
                fn (): bool => $this->gitView->isOpen(),
                fn (): bool => $this->settingsView->isOpen(),
            ),
        );
        $this->treeOps = new TreeOperations(
            $this->tree,
            $this->editor,
            $this->tui,
            $this->notifier,
            $this->buffers,
            $this->translator,
            fn (string $p, bool $focus) => $this->openFile($p, focusEditor: $focus),
            fn (Buffer $b) => $this->showBuffer($b),
            fn (string $p) => $this->relativePath($p),
            fn (string $t) => $this->copyToClipboard($t),
            fn (string $dir) => $this->openFindInFolder($dir),
            fn (string $dir) => $this->openReplaceInFolder($dir),
        );

        // RTL bidi reorder, applied per pane by BidiTui when the locale is RTL.
        // The floating menu/context dropdowns are the only true overlays; they
        // feed in as topmost runs so the reorder flips them within their box.
        $this->paneReorder = new PaneReorder();
        $this->paneSegmenter = new PaneSegmenter(
            $this->renderer,
            fn (): array => array_values(array_filter([$this->overlay->menuDropdown(), $this->overlay->contextMenuDropdown()])),
        );
    }

    /** The editor workspace: tree, editor + LSP backend, chrome widgets and the main horizontal layout. */
    private function buildEditorArea(): void
    {
        $this->buffers = $this->service(BufferManager::class);
        $this->lsp = new LspSessionPool($this->rootDir, fn (): ?string => $this->editor->getFilePath(), $this->translator);
        $this->container->set(LspSessionPool::class, $this->lsp);

        $this->tree = $this->service(FileTreeWidget::class);
        $this->editor = $this->service(CodeEditorWidget::class);
        $this->workspaceEditor = $this->service(WorkspaceEditor::class);
        $this->statusBar = $this->service(StatusBarWidget::class);
        $this->notifier = $this->service(Notifier::class);
        $this->menuBar = new MenuBarWidget();
        $this->menuBar->setId('menu');
        $this->contextMenu = new ContextMenu();
        $this->contextMenu->setId('context-menu');
        $this->completion = $this->service(CompletionController::class);
        $this->tabBar = new TabBarWidget();
        $this->tabBar->setId('tabs');
        $this->tabBar->setProvider(fn (): array => array_map(fn (Buffer $b) => [
            'name' => basename($b->path),
            'dirty' => $b->isDirty(),
            'active' => $b === $this->buffers->active(),
        ], $this->buffers->all()));

        $this->breadcrumbs = new BreadcrumbsWidget();
        $this->breadcrumbs->setId('breadcrumbs');
        $this->breadcrumbs->setProvider(fn (): array => $this->breadcrumbTrail());

        $editorPane = new ContainerWidget();
        $editorPane->setId('editor-pane');
        $editorPane->setStyle(new Style(flex: 1));
        $editorPane->expandVertically(true);
        $editorPane->add($this->tabBar)->add($this->breadcrumbs)->add($this->editor);

        $this->separator = new SeparatorWidget();
        $this->separator->setId('separator')->setStyle(new Style(flex: 0));

        $this->main = new ContainerWidget();
        $this->main->setId('main');
        $this->main->setStyle(new Style(direction: Direction::Horizontal, gap: 0));
        $this->main->expandVertically(true);
        $this->editorPanes = [$this->tree, $this->separator, $editorPane];
        $this->setPanes($this->main, $this->editorPanes);
    }

    /** The floating panels, project search, command palette and the LSP/run controllers. */
    private function buildSidePanels(): void
    {
        $this->goToFile = $this->service(GoToFileWidget::class);
        $this->findBar = $this->service(FindBarWidget::class);
        $this->resultsPanel = $this->service(ResultsPanelWidget::class);
        $this->terminalPanel = $this->service(TerminalPanelWidget::class);
        $this->projectSearchController = new ProjectSearchController(
            new ProjectSearch($this->rootDir),
            $this->resultsPanel,
            $this->editor,
            $this->statusBar,
            $this->tui,
            $this->translator,
            $this->buffers,
            $this->rootDir,
            fn (string $path, Document $document) => $this->lsp->notifyFullChange($path, $document),
        );
        $this->commandPalette = $this->service(CommandPalette::class);
        $this->panels = $this->service(PanelManager::class);
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
            fn (string $url) => $this->openDocsUrl($url),
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
    }

    /**
     * The Search Everywhere modal: five sources (Files/Classes/Symbols/Actions/
     * Text) feeding one aggregator that drives the editor's centered overlay.
     * Hand-built here because the sources close over Ide's open/jump orchestration
     * and the debounce needs the shared event loop. Runs after wireCommands() so
     * ActionsSource sees the populated CommandRegistry.
     */
    private function buildSearchEverywhere(): void
    {
        $openFile = fn (string $path, bool $focus) => $this->openFile($path, focusEditor: $focus);
        $jumpTo = fn (int $line, int $col) => $this->editor->jumpTo($line, $col);
        $searchSymbols = new WorkspaceSymbolClient($this->lsp, $this->rootDir)->search(...);

        $this->searchEverywhere = new SearchEverywhereController(
            $this->editor,
            [
                new FilesSource(new FileIndex($this->rootDir), $this->rootDir, $openFile),
                new ActionsSource($this->commands),
            ],
            [
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Classes, [5, 10, 11, 23], $this->rootDir, $openFile, $jumpTo),
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Symbols, [], $this->rootDir, $openFile, $jumpTo),
                new TextSource(new ProjectSearch($this->rootDir), $this->rootDir, $openFile, $jumpTo),
            ],
            $this->buffers,
            $this->tui,
            $this->rootDir,
            $openFile,
            fn (float $seconds, \Closure $callback): string => EventLoop::delay($seconds, $callback),
            fn (string $id) => EventLoop::cancel($id),
        );
    }

    private function buildGitView(): void
    {
        $this->gitChanges = $this->service(ChangesListWidget::class);
        $this->gitDiff = $this->service(DiffViewWidget::class);
        $this->gitCommitBox = $this->service(CommitBoxWidget::class);
        $this->gitHistory = $this->service(HistoryListWidget::class);
        $this->gitTabs = $this->service(GitTabsWidget::class);
        $gitLeft = new ContainerWidget();
        $gitLeft->setId('git-left');
        $gitLeft->setStyle(new Style(flex: 0));
        $gitLeft->expandVertically(true);
        $gitLeft->add($this->gitTabs)->add($this->gitChanges)->add($this->gitCommitBox);
        $this->gitSeparator = new SeparatorWidget();
        $this->gitSeparator->setId('git-separator')->setStyle(new Style(flex: 0));
        $this->gitAreaContainer = new ContainerWidget();
        $this->gitAreaContainer->setId('git-area');
        $this->gitAreaContainer->setStyle(new Style(direction: Direction::Horizontal, gap: 0));
        $this->gitAreaContainer->expandVertically(true);
        $this->gitPanes = [$gitLeft, $this->gitSeparator, $this->gitDiff];
        $this->setPanes($this->gitAreaContainer, $this->gitPanes);
        $this->gitView = new GitViewController(
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
            new GitViewCallbacks(
                editArea: fn (): array => $this->orderPanes($this->editorPanes),
                onClosed: function (): void {
                    $this->tui->setFocus($this->editor);
                    $this->tui->requestRender();
                },
                onCommitted: function (): void {
                    $this->refreshActiveGitMarks();
                    $this->refreshBranch();
                },
                loadDiffPrefs: fn (): array => $this->layout->gitDiffPrefs(),
                saveDiffPrefs: fn (bool $split, bool $hideWhitespace) => $this->layout->setGitDiffPrefs($split, $hideWhitespace),
                copyToClipboard: fn (string $text) => $this->copyToClipboard($text),
            ),
        );
    }

    // --- File handling ----------------------------------------------------------

    private function openFile(string $path, bool $focusEditor = false): void
    {
        $this->bufferService->openFile($path, $focusEditor);
    }

    private function showBuffer(Buffer $buffer): void
    {
        $this->bufferService->showBuffer($buffer);
    }

    private function refreshBranch(): void
    {
        if (!$this->git->isAvailable()) {
            return;
        }
        $this->git->branch(function (?string $branch): void {
            $this->gitBranch = $branch;
            $this->statusBar->invalidate();
            $this->tui->requestRender();
        });
    }

    /**
     * Recompute git change marks for a buffer (on open and after save).
     */
    private function refreshGitMarks(Buffer $buffer): void
    {
        if (!$this->git->isAvailable()) {
            return;
        }
        $this->git->diff($buffer->path, function (array $marks) use ($buffer): void {
            $buffer->gitMarks = $marks;
            if ($buffer === $this->buffers->active()) {
                $this->editor->setGitMarks($marks);
            }
            $this->tui->requestRender();
        });
    }

    /** Recompute git change marks for the active buffer, if any. */
    private function refreshActiveGitMarks(): void
    {
        if (null !== ($buffer = $this->buffers->active())) {
            $this->refreshGitMarks($buffer);
        }
    }

    private function closeActiveBuffer(bool $force = false): void
    {
        $this->bufferService->closeActiveBuffer($force);
    }

    private function saveCurrentFile(): void
    {
        $this->bufferService->saveCurrentFile();
    }

    private function saveAllBuffers(): void
    {
        $this->bufferService->saveAllBuffers();
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
    }

    private function handleQuit(): void
    {
        $dirty = $this->buffers->dirtyCount();
        if ($dirty > 0 && (microtime(true) - $this->lastQuitPress) > 3.0) {
            $this->lastQuitPress = microtime(true);
            $noun = 1 === $dirty
                ? $this->translator->trans('1 file has')
                : $this->translator->trans('%count% files have', ['%count%' => $dirty]);
            $this->notifier->flash($this->translator->trans('Unsaved changes — %noun% edits. Ctrl+Q again to quit, Ctrl+S to save', ['%noun%' => $noun]), 3.0);

            return;
        }
        $this->tui->stop();
    }

    // --- LSP -------------------------------------------------------------------

    /** Live UI-locale switch: point the translator at $locale and rebuild the chrome. */
    private function applyLanguage(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->rebuildMenu(); // the menu bakes its labels at setMenus time
        $this->applyDirection();
        // Widgets cache their rendered lines; clear the whole tree so the next
        // frame rebuilds every label under the new locale.
        foreach ([$this->menuBar, $this->main, $this->statusBar, $this->goToFile, $this->findBar, $this->resultsPanel, $this->terminalPanel, $this->contextMenu] as $widget) {
            $this->invalidateTree($widget);
        }
        $this->tui->requestRender();
    }

    /**
     * Live theme switch: swap the shared palette + syntax-theme holders every
     * widget and buffer holds, then flush the colored caches so the next frame
     * repaints. Chrome re-renders from the tree clear; buffers and the git diff
     * cache colored output by content, so those are busted explicitly.
     */
    private function applyTheme(string $theme): void
    {
        $this->palette->switchTo(PaletteFactory::create($this->ansi, $theme));
        $this->syntaxTheme->switchTo(TerminalThemeFactory::create($this->ansi, $theme));
        // Existing highlighters baked the old theme at construction, so rebuild
        // them (the diff view re-highlights its shown hunks in the same step);
        // buffers opened later pick up the new theme from the swapped holder.
        foreach ($this->buffers->all() as $buffer) {
            $buffer->highlighter->refreshTheme();
        }
        $this->gitDiff->refreshTheme();
        $this->rebuildMenu(); // the menu bakes its colored labels at setMenus time
        foreach ([$this->menuBar, $this->main, $this->statusBar, $this->goToFile, $this->findBar, $this->resultsPanel, $this->terminalPanel, $this->contextMenu] as $widget) {
            $this->invalidateTree($widget);
        }
        $this->tui->requestRender();
    }

    /**
     * Panes in visual order: reversed (sidebar on the right) for RTL locales.
     *
     * @param list<AbstractWidget> $ltr
     *
     * @return list<AbstractWidget>
     */
    private function orderPanes(array $ltr): array
    {
        return $this->translator->isRtl() ? array_reverse($ltr) : $ltr;
    }

    /**
     * Replace a horizontal container's children with $ltr panes in visual order.
     *
     * @param list<AbstractWidget> $ltr
     */
    private function setPanes(ContainerWidget $container, array $ltr): void
    {
        $container->clear();
        foreach ($this->orderPanes($ltr) as $pane) {
            $container->add($pane);
        }
    }

    /**
     * Mirror the horizontal layouts for the active writing direction: the git
     * and settings areas always, and the editor layout when it currently owns
     * the main container (a swapped-in view restores in order when it closes).
     */
    private function applyDirection(): void
    {
        // The boot-time applyAll() fires this before the view controllers exist;
        // the containers were already ordered for the persisted locale at build
        // time, so there is nothing to re-mirror until a live locale switch.
        if (!isset($this->gitView, $this->settingsView)) {
            return;
        }
        $this->translator->isRtl()
            ? $this->bidiTui->setPaneReorder($this->paneSegmenter, $this->paneReorder)
            : $this->bidiTui->setPaneReorder(null, null);
        $this->setPanes($this->gitAreaContainer, $this->gitPanes);
        $this->setPanes($this->settingsAreaContainer, $this->settingsPanes);
        if (!$this->gitView->isOpen() && !$this->settingsView->isOpen()) {
            $this->setPanes($this->main, $this->editorPanes);
        }
    }

    private function invalidateTree(AbstractWidget $widget): void
    {
        $widget->clearRenderCache();
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $this->invalidateTree($child);
            }
        }
    }

    /** Re-send didOpen for every open file, so a live LSP reconfigure relaunches its servers. */
    private function reopenLspBuffers(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->lsp->notifyDidOpen($buffer->path, $buffer->document->getText(), ProjectPaths::languageId($buffer->path), $buffer->document->getVersion());
        }
    }

    private function startLsp(): void
    {
        if ($this->lspDisabled) {
            $this->lsp->disable();

            return;
        }
        // Declare every configured/detected server; each session starts lazily
        // on the first didOpen of a file in its language.
        $this->lsp->configure(
            ServerLocator::locateAll($this->rootDir, $this->lspCommand),
            static function (string $line): void {
                // LSP server log lines are currently discarded.
            },
            function (array $params): void {
                /** @var array{uri?: string, diagnostics?: list<array{severity?: int, message?: string, range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}}>} $params */
                $this->handleDiagnostics($params);
            },
            function (array $edit): bool {
                /** @var array<string, mixed> $edit */
                $count = $this->workspaceEditor->applyWorkspaceEdit($edit);
                if ($count > 0) {
                    $this->notifier->flash(
                        1 === $count
                            ? $this->translator->trans('Applied server edit to %count% file', ['%count%' => $count])
                            : $this->translator->trans('Applied server edit to %count% files', ['%count%' => $count]),
                    );
                }

                return $count > 0;
            },
            function (): void {
                // The status label lives outside the widget tree; without an
                // explicit invalidation the render cache serves a stale frame.
                $this->statusBar->invalidate();
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Validate a rename position with textDocument/prepareRename before opening
     * the rename prompt, pre-filling the server's placeholder when it offers one.
     */
    private function openProjectSearch(): void
    {
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearchController->open();
    }

    /** Open the results panel as a text search scoped to a folder (tree "Find in Files…"). */
    private function openFindInFolder(string $absDir): void
    {
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearchController->openInFolder($absDir);
    }

    /** Open the same scoped panel titled for replacing (tree "Replace in Files…"). */
    private function openReplaceInFolder(string $absDir): void
    {
        $this->panels->setMode(ResultsPanelMode::Search);
        $this->projectSearchController->replaceInFolder($absDir);
    }

    private function openSymbolSearch(): void
    {
        $this->panels->openQuery(ResultsPanelMode::Symbol, 'Go to Symbol in Project');
    }

    /**
     * Register every IDE-level command once: keybinding dispatch, the Find Action
     * palette, and their labels all read from this single source of truth.
     */
    private function wireCommands(): void
    {
        $this->commands = new CommandRegistry();

        // Key-bound only (not offered in the palette).
        $this->commands
            ->add('ide_quit', fn () => $this->handleQuit(), keyBound: true, inPalette: false)
            ->add(
                'ide_toggle_focus',
                function (): void {
                    $focus = $this->tui->getFocus() === $this->editor ? $this->tree : $this->editor;
                    $this->tui->setFocus($focus);
                    $this->tui->requestRender();
                },
                keyBound: true,
                inPalette: false,
            )
            ->add('ide_command_palette', fn () => $this->openCommandPalette(), keyBound: true, inPalette: false)
            ->add('ide_search_everywhere', fn () => $this->searchEverywhere->open(), keyBound: true, inPalette: false)
            ->add('ide_local_history', fn () => $this->localHistory->show(), keyBound: true, inPalette: false)
            ->add('ide_run_last', fn () => $this->run->runLast(), keyBound: true, inPalette: false)
            ->add('ide_menu', fn () => $this->openMenu(), keyBound: true, inPalette: false);

        // Palette commands, in display order (some also key-bound).
        $this->commands
            ->add('save', fn () => $this->saveCurrentFile())
            ->add('save_all', fn () => $this->saveAllBuffers())
            ->add('reformat', fn () => $this->refactor->requestReformat())
            ->add('ide_problems', fn () => $this->showProblems(), keyBound: true)
            ->add('ide_find_in_project', fn () => $this->openProjectSearch(), keyBound: true)
            ->add('ide_goto_symbol', fn () => $this->openSymbolSearch(), keyBound: true)
            ->add('move_tab_left', fn () => $this->moveTab(-1), keyBound: true)
            ->add('move_tab_right', fn () => $this->moveTab(1), keyBound: true)
            ->add('goto_file', function (): void {
                $this->goToFile->open();
                $this->tui->setFocus($this->goToFile);
            })
            ->add('toggle_inline_diagnostics', fn () => $this->settings->toggle('inlineDiagnostics'), label: 'Toggle Inline Diagnostics')
            ->add('toggle_relative_line_numbers', fn () => $this->settings->toggle('relativeLineNumbers'), label: 'Toggle Relative Line Numbers')
            ->add('toggle_auto_pairs', fn () => $this->settings->toggle('autoPairs'), label: 'Toggle Auto-Pairs')
            ->add('ide_terminal', fn () => $this->run->toggleTerminal(), keyBound: true)
            ->add('ide_run', fn () => $this->run->openRunMenu(), keyBound: true)
            ->add(
                'ide_git',
                function (): void {
                    $this->settingsView->close();
                    $this->gitView->toggle();
                },
                keyBound: true,
            )
            ->add(
                'ide_settings',
                function (): void {
                    $this->gitView->close();
                    $this->settingsView->toggle();
                },
                keyBound: true,
            )
            ->add('git_stage', fn () => $this->gitCommands->stageCurrent(), label: 'Git: Stage Current File')
            ->add('git_commit', fn () => $this->gitCommands->commit(), label: 'Git: Commit…')
            ->add('git_blame', fn () => $this->gitCommands->blameLine(), label: 'Git: Blame Current Line')
            ->add('git_undo_commit', fn () => $this->gitCommands->undoLastCommit(), label: 'Git: Undo Last Commit');
    }

    private function openCommandPalette(): void
    {
        $this->panels->setMode(ResultsPanelMode::Action);
        $this->commandPalette->open($this->commands->paletteCommands());
    }

    /** (Re)build the menu-bar labels for the current locale. */
    private function rebuildMenu(): void
    {
        $this->menuBar->setMenus(MenuModel::build(
            $this->commands,
            $this->editor,
            $this->keybindings,
            $this->translator,
            fn (): bool => $this->gitView->isOpen(),
            fn (): bool => $this->settingsView->isOpen(),
        ));
    }

    /** Populate the application menu bar and return focus to the editor on close. */
    private function wireMenuBar(Keybindings $keybindings): void
    {
        $this->rebuildMenu();
        $this->menuBar->onNavigate(function (): void {
            $this->overlay->refreshMenu();
        });
        $this->menuBar->onClose(function (): void {
            $this->overlay->refreshMenu();
            $this->tui->setFocus(match (true) {
                $this->settingsView->isOpen() => $this->settingsView->focusTarget(),
                $this->gitView->isOpen() => $this->gitView->focusTarget(),
                default => $this->editor,
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

    private function openMenu(): void
    {
        $this->menuBar->open(0);
        $this->tui->setFocus($this->menuBar);
        $this->tui->requestRender();
    }

    private function showProblems(): void
    {
        $results = [];
        foreach ($this->buffers->all() as $buffer) {
            $rel = $this->relativePath($buffer->path);
            foreach ($buffer->diagnostics as $line => $list) {
                foreach ($list as $diag) {
                    $marker = 1 === $diag['severity'] ? '✗' : '▲';
                    $results[] = [
                        'path' => $rel,
                        'line' => $line,
                        'text' => $marker.' '.$diag['message'],
                    ];
                }
            }
        }

        // Sort by file then line for a stable, scannable list.
        usort($results, static fn (array $a, array $b) => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        $this->panels->showList(ResultsPanelMode::Problems, 'Problems', $results);
    }

    /**
     * @param array{
     *     uri?: string,
     *     diagnostics?: list<array{
     *         severity?: int,
     *         message?: string,
     *         range: array{
     *             start: array{line?: int, character?: int},
     *             end: array{line?: int, character?: int},
     *         },
     *     }>,
     * } $params
     */
    private function handleDiagnostics(array $params): void
    {
        $this->bufferService->applyDiagnostics($params['uri'] ?? '', LspMapper::diagnosticsByLine($params));
    }

    // --- Helpers -----------------------------------------------------------------

    private function relativePath(string $path): string
    {
        return ProjectPaths::relative($this->rootDir, $path);
    }
}
