<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Debug\DebugAdapters;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\SettingsSectionRequested;
use Porta\Highlight\ColorMode;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\ViewRegions;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\ToolInstaller;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\FileIndex;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\Section\GeneralSection;
use Porta\Settings\Section\HighlightingSection;
use Porta\Settings\Section\InterfaceSection;
use Porta\Settings\Section\LanguageSection;
use Porta\Settings\Section\PluginsSection;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsDebugController;
use Porta\Settings\SettingsHighlightController;
use Porta\Settings\SettingsKeymapController;
use Porta\Settings\SettingsLanguageController;
use Porta\Settings\SettingsLspController;
use Porta\Settings\SettingsMouseRouter;
use Porta\Settings\SettingsPluginsController;
use Porta\Settings\SettingsSection;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class SettingsViewControllerTest extends TestCase
{
    use NavigatesSettings;

    private TempProject $project;
    private Tui $tui;
    private EventDispatcher $sectionEvents;
    private SettingsNavWidget $nav;
    private SettingsFormWidget $form;
    private KeybindingsListWidget $keymapList;
    private ContainerWidget $main;
    private ContainerWidget $content;
    private CodeEditorWidget $editorWidget;
    private PluginSettings $pluginSettings;
    private FakePluginLifecycle $lifecycle;
    private ViewRegions $regions;

    /** @param list<SettingsSection>|null $sections */
    private function controller(?ViewRegistry $views = null, ?array $sections = null): SettingsViewController
    {
        $this->project = new TempProject();
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);

        $this->nav = new SettingsNavWidget(new Translator('en'));
        $this->sectionEvents = new EventDispatcher();
        $this->form = new SettingsFormWidget(new Translator('en'), events: $this->sectionEvents);
        $this->form->setId('settings-form');
        $this->keymapList = new KeybindingsListWidget(new Translator('en'), events: $this->sectionEvents);
        $this->keymapList->setId('settings-keys');
        // The controller mints its region and puts the nav into the sidebar
        // itself; the test reads the same containers back off the registry.
        $regions = new ViewRegions(Styles::dark());
        $this->regions = $regions;
        $this->content = $regions->mint('settings')->content();
        $menuBar = new MenuBarWidget();
        $menuBar->setId('menu');

        $renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: $renderer);
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $notifier = new Notifier($status, $this->tui);
        $applier = new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer($renderer),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            new Translator('en'),
            $regions,
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(
                rootDir: $this->project->dir,
                editor: $editor,
                menuBar: $menuBar,
                statusBar: $status,
            ),
            new EventDispatcher(),
            self::formatOnSave(),
            true,
        );
        $settings = new SettingsController(
            IdeConfig::DEFAULT_SETTINGS,
            $editor,
            $notifier,
            $this->project->dir,
            new Translator('en'),
            new PortaJsonWriter(),
            $applier,
            new BreadcrumbsWidget(),
            new FileIndex($this->project->dir),
        );
        $bootKeys = new Keybindings([]);
        $keysHost = new PluginHost(IdeConfig::load($this->project->dir), new EventDispatcher(), $this->project->dir);
        $keysFactory = new KeybindingsFactory($keysHost, IdeConfig::load($this->project->dir), new ActionRegistry($keysHost->manager()));
        $liveKeys = new LiveKeybindings($keysFactory, $bootKeys, $this->project->dir);
        $keymapRebuilder = new MenuRebuilder(
            new MenuBarWidget(),
            new CommandRegistry(),
            $editor,
            $liveKeys,
            new Translator('en'),
            new ViewRegistry(),
            new PluginManager(new EventDispatcher(), $this->pluginSettings ?? new PluginSettings($this->project->dir, [])),
            new MenuContributions(),
        );
        $keymap = new SettingsKeymapController(
            new ActionRegistry($keysHost->manager()),
            new CommandRegistry(),
            $editor,
            new EditorPanes(),
            $liveKeys,
            $keymapRebuilder,
            $notifier,
            $this->project->dir,
            new Translator('en'),
            new PortaJsonWriter(),
        );
        $languages = new SettingsLanguageController($editor, $notifier, $this->project->dir, new Translator('en'), [], new PortaJsonWriter());
        $pool = new LspSessionPool($this->project->dir, new FixedActiveFile(), new Translator('en'));
        $reconfigurer = new LspReconfigurer(
            $pool,
            new class implements BufferReopener {
                public function reopenAllInLsp(): void {}
            },
            new class implements LanguageServerProvider {
                public function languageServers(): array
                {
                    return [];
                }
            },
            $this->project->dir,
            null,
        );
        $lsp = new SettingsLspController($this->project->dir, [], $reconfigurer, $notifier, new Translator('en'), new PortaJsonWriter());
        $debug = new SettingsDebugController($this->project->dir, new DebugAdapters([]), $notifier, new Translator('en'), new PortaJsonWriter());
        $this->pluginSettings = new PluginSettings($this->project->dir, []);
        $this->lifecycle = new FakePluginLifecycle();
        $plugins = new SettingsPluginsController(
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            $this->pluginSettings,
            $notifier,
            new Translator('en'),
            $this->lifecycle,
            new PluginManager(new EventDispatcher(), $this->pluginSettings),
            new ToolInstaller($this->project->dir, []),
        );
        $editorArea = new EditorArea(
            new BufferManager(),
            $this->editorWidget = new CodeEditorWidget(),
            new EditorPanes(),
            $this->tui,
        );

        $controller = new SettingsViewController(
            $this->nav,
            $this->form,
            $this->keymapList,
            $regions,
            new ViewHost(
                $this->main,
                $menuBar,
                new class($editWidget) implements EditorAreaPanes {
                    public function __construct(
                        private readonly ContainerWidget $editWidget,
                    ) {}

                    public function orderedEditorPanes(): array
                    {
                        return [$this->editWidget];
                    }
                },
                $editorArea,
                $this->tui,
            ),
            new SettingsMouseRouter(
                new HitTester(new Renderer(new StyleSheet())),
                $this->tui,
                $this->nav,
                $this->form,
                $this->keymapList,
                $regions,
            ),
            $this->tui,
            // Same order the porta.settings_section tag priorities produce, with
            // the catch-all general section last.
            $sections ?? [
                new InterfaceSection($settings),
                new HighlightingSection(new SettingsHighlightController(
                    new HighlightRegistry(new NoHighlightPlugins(), new HighlightPreferences()),
                    new Translator('en'),
                    $this->project->dir,
                    new HighlightPreferences(),
                    new PortaJsonWriter(),
                    $this->lifecycle,
                )),
                new PluginsSection($plugins),
                new LanguageSection($languages, $lsp, $debug),
                new GeneralSection($settings),
            ],
            $keymap,
            $plugins,
            views: $views ?? new ViewRegistry(),
        );
        // Compiled from #[AsEventListener] in the container; wired by hand here.
        $this->sectionEvents->addListener(SettingsSectionRequested::class, $controller->onSectionRequested(...));

        return $controller;
    }

    /** @return list<string> */
    private function ids(ContainerWidget $container): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $container->all());
    }

    public function testOpenSwapsMainAndShowsGeneralFocused(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertTrue($controller->isOpen());
        $this->assertSame(['settings-area'], $this->ids($this->main));
        $this->assertSame(['settings-form'], $this->ids($this->content));
        $this->assertSame($this->form, $this->tui->getFocus());
        $this->assertSame('general', $this->nav->selectedId());
    }

    public function testTheRegistryResolvesTheFocusTargetWhileOpen(): void
    {
        $views = new ViewRegistry();
        $controller = $this->controller($views);

        $this->assertNull($views->focusTarget(), 'the editor layout owns focus while settings is closed');
        $controller->open();
        $this->assertSame($controller->focusTarget(), $views->focusTarget());
    }

    /**
     * The Highlighting section: its rows are derived from loaded backends, and
     * an edit routes to the highlight controller rather than the settings one —
     * it persists to the top-level `highlight` block, not `settings`.
     */
    public function testNavSelectingHighlightingShowsItsRowsAndRoutesChanges(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->selectSettingsSection($this->nav, 'highlighting');
        $this->assertSame('highlighting', $this->nav->selectedId());

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 12)));
        $this->assertStringContainsString('Default highlighter', $screen);

        $this->form->handleInput("\r"); // cycle the default choice off "auto"
        $config = json_decode((string) file_get_contents($this->project->path('.porta.json')), associative: true);

        $this->assertArrayHasKey('highlight', \is_array($config) ? $config : []);
    }

    public function testNavSelectingKeybindingsSwapsTheContent(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->selectSettingsSection($this->nav, 'keybindings');
        $this->assertSame(['settings-keys'], $this->ids($this->content));
        $this->assertSame($this->keymapList, $controller->focusTarget());

        $screen = implode("\n", $this->keymapList->render(new \Symfony\Component\Tui\Render\RenderContext(80, 20)));
        $this->assertStringContainsString('actions', $screen, 'the keybindings list is populated');
    }

    public function testNavSelectingInterfaceShowsTheAppearanceRowsAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->selectSettingsSection($this->nav, 'interface');
        $this->assertSame('interface', $this->nav->selectedId());
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 12)));
        $this->assertStringContainsString('Theme', $screen);
        $this->assertStringContainsString('Line numbers', $screen);
        $this->assertStringContainsString('Sticky scroll', $screen);
        $this->assertStringContainsString('Breadcrumbs', $screen);
        $this->assertStringNotContainsString('Inline diagnostics', $screen, 'editor behavior stays in General');

        // Toggle Sticky scroll (a Bool) → routes through changeSetting and persists.
        $this->form->handleInput("\x1b[B"); // Theme
        $this->form->handleInput("\x1b[B"); // Cursor shape
        $this->form->handleInput("\x1b[B"); // Tab bar position
        $this->form->handleInput("\x1b[B"); // Line numbers
        $this->form->handleInput("\x1b[B"); // Sticky scroll
        $this->form->handleInput(' ');
        $this->assertFalse(IdeConfig::load($this->project->dir)->settings['stickyScroll']);
    }

    public function testNavSelectingALanguageShowsIndentRowsAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->selectSettingsSection($this->nav, 'lang:php');
        $this->assertSame('lang:php', $this->nav->selectedId());
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringContainsString('Indent style', $screen);
        $this->assertStringContainsString('Indent size', $screen);
        $this->assertStringContainsString('LSP server', $screen, 'the language section also shows its LSP server row');

        // Actuate the indent-style row (auto → spaces) → persists to the languages block.
        // Pane row 1: row 0 is the title, and the first setting starts selected
        // so one click on it actuates.
        $this->form->handleClick(1);
        $this->assertSame('spaces', IdeConfig::load($this->project->dir)->languages['php']['indentStyle']);
    }

    public function testEditingTheLspRowRoutesToTheLspControllerAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'lang:php');

        // The LSP row is the fourth row of a language section (after indent + run command); edit it inline.
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\r"); // start editing
        foreach (str_split('phpactor') as $char) {
            $this->form->handleInput($char);
        }
        $this->form->handleInput("\r"); // commit

        $this->assertSame('phpactor', IdeConfig::load($this->project->dir)->lspServers['php']);
    }

    public function testEditingTheDebugAdapterRowRoutesToTheDebugControllerAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'lang:php');

        // The debug-adapter row is the fifth row of a language section (after indent + run command + LSP).
        for ($i = 0; $i < 4; ++$i) {
            $this->form->handleInput("\x1b[B");
        }
        $this->form->handleInput("\r"); // start editing
        foreach (str_split('node dbg.js') as $char) {
            $this->form->handleInput($char);
        }
        $this->form->handleInput("\r"); // commit

        $this->assertSame('node dbg.js', IdeConfig::load($this->project->dir)->languages['php']['debug']['adapter'] ?? null);
    }

    public function testChangingAGeneralSettingPersistsToTheSettingsBlock(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->form->handleClick(1); // pane row 1 = General's first row, Inline diagnostics (default true → false)
        $this->assertFalse(IdeConfig::load($this->project->dir)->settings['inlineDiagnostics']);
    }

    public function testRebindingFromTheKeybindingsSection(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'keybindings');

        $this->keymapList->handleInput("\r"); // arm capture on the first action
        $this->keymapList->handleInput('a');
        $this->assertSame(['a'], IdeConfig::load($this->project->dir)->keymap['cursor_up']);
    }

    public function testTabCyclesBetweenNavAndContent(): void
    {
        $controller = $this->controller();
        $controller->open(); // focus starts on the form (content)

        $this->form->handleInput("\t"); // content → nav
        $this->assertSame($this->nav, $this->tui->getFocus());

        $this->nav->handleInput("\t"); // nav → content
        $this->assertSame($this->form, $this->tui->getFocus());
    }

    public function testCycleFocusBouncesBetweenNavAndContent(): void
    {
        $controller = $this->controller();
        $controller->open(); // focus starts on the form (content)

        $controller->cycleFocus(1);
        $this->assertSame($this->nav, $this->tui->getFocus(), 'content → nav');

        $controller->cycleFocus(1);
        $this->assertSame($this->form, $this->tui->getFocus(), 'nav → content');
    }

    public function testEscFromNavOrContentClosesTheView(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->nav->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], $this->ids($this->main));
        $this->assertSame($this->editorWidget, $this->tui->getFocus(), 'close restores focus to the editor area');

        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
    }

    public function testPluginsSectionShowsItsRowsAndRoutesChanges(): void
    {
        $controller = $this->controller();
        mkdir($this->project->dir.'/.porta/plugins/demo', permissions: 0o777, recursive: true);
        file_put_contents($this->project->dir.'/.porta/plugins/demo/plugin.php', data: "<?php\n");
        $controller->open();

        $this->selectSettingsSection($this->nav, 'plugins');
        $this->assertSame('plugins', $this->nav->selectedId());
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringContainsString('Install into', $screen);
        $this->assertStringContainsString('Install plugin', $screen);
        $this->assertMatchesRegularExpression('/demo \(project\)\s+\[Remove\]/', $screen);

        // Actuating the Remove row twice (two-press confirm) routes to the
        // plugins controller and the refreshed rows drop the plugin.
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\r");
        $this->form->handleInput("\r");
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/demo');
        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringNotContainsString('demo (project)', $screen);
    }

    public function testRefreshOnlyActsWhileOpenOnThePluginsSection(): void
    {
        $controller = $this->controller();
        $controller->refresh(); // closed → no-op
        $this->assertSame([], $this->content->all());

        $controller->open(); // General section
        $controller->refresh();
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $this->selectSettingsSection($this->nav, 'plugins');
        mkdir($this->project->dir.'/.porta/plugins/late', permissions: 0o777, recursive: true);
        file_put_contents($this->project->dir.'/.porta/plugins/late/plugin.php', data: "<?php\n");
        $controller->refresh(); // re-reads the rows: the late plugin appears
        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertMatchesRegularExpression('/late \(project\)\s+\[Remove\]/', $screen);
    }

    public function testPluginPageRoutesRowsAndChangesAndFallsBackWhenRemoved(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary path', 'kind' => 'text', 'default' => '', 'options' => null, 'hint' => null, 'min' => null, 'max' => null, 'step' => null],
        ]);
        $controller->open();

        // The plugin sub-item appears in the nav and selecting it shows its page.
        $this->nav->setPluginEntries(['demo']);
        $this->selectSettingsSection($this->nav, 'plugin:demo');
        $this->assertSame('plugin:demo', $this->nav->selectedId());
        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringContainsString('Enabled', $screen);
        $this->assertStringContainsString('Binary path', $screen);

        // Editing the text row routes into PluginSettings.
        $this->form->handleInput("\x1b[B"); // → the binary row
        $this->form->handleInput("\r");
        foreach (str_split('/opt/x') as $char) {
            $this->form->handleInput($char);
        }
        $this->form->handleInput("\r");
        $this->assertSame('/opt/x', $this->pluginSettings->value('demo', 'binary'));

        // When the shown plugin vanishes, refresh() falls back to the Plugins section.
        $this->lifecycle->names = [];
        $controller->refresh();
        $this->assertSame('plugins', $this->nav->selectedId());
        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringContainsString('Install plugin', $screen);
    }

    public function testToggleAndCloseWhenClosedIsANoOp(): void
    {
        $controller = $this->controller();
        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $controller->open(); // idempotent
        $this->assertSame(['settings-area'], $this->ids($this->main));
        $controller->toggle();
        $this->assertFalse($controller->isOpen());

        $this->tui->setFocus($this->nav);
        $controller->close(); // already closed: a no-op that must not steal focus
        $this->assertSame($this->nav, $this->tui->getFocus());
    }

    public function testAnUnclaimedSectionIsAProgrammingError(): void
    {
        // The general section claims every id, so in the wired IDE this can
        // never happen — the guard exists so a future section list that drops
        // the catch-all fails loudly instead of rendering a blank page.
        $controller = $this->controller(sections: []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No settings section claimed general');
        $controller->open();
    }

    public function testTheNavIsMintedIntoTheSidebarAsTheResizableUnit(): void
    {
        $this->controller();

        $sidebar = $this->regions->find('settings')?->sidebar();
        $this->assertNotNull($sidebar);
        $this->assertSame([$this->nav], $sidebar->all(), 'the nav widget is actually added to the sidebar container');
        $this->assertSame($this->nav, $this->regions->find('settings')?->sidebarPanes(), 'the nav is registered as the resizable sidebar unit');
    }

    public function testOwnsMainAreaIsTrue(): void
    {
        $controller = $this->controller();

        $this->assertTrue($controller->ownsMainArea());
    }

    public function testOpenIsIdempotentAndDoesNotResetAnAlreadyOpenView(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'interface');
        $this->form->handleInput("\t"); // content -> nav, so a re-run of open() would steal focus back

        $controller->open(); // already open: must be a complete no-op

        $this->assertSame('interface', $this->nav->selectedId(), 'a second open() must not reset the section back to general');
        $this->assertSame($this->nav, $this->tui->getFocus(), 'a second open() must not steal focus back to the form');
    }

    public function testOpenRequestsARender(): void
    {
        $controller = $this->controller();
        // The first open() also moves focus onto the form, which requests its
        // own render — close and reopen with focus already on the form so
        // that side effect can't mask invalidate()'s own render request.
        $controller->open();
        $controller->close();
        $this->tui->setFocus($this->form);
        Probe::set($this->tui, 'renderRequested', false);

        $controller->open();

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testSelectingASectionRequestsARender(): void
    {
        $controller = $this->controller();
        $controller->open();
        Probe::set($this->tui, 'renderRequested', false);

        $this->selectSettingsSection($this->nav, 'interface');

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testRefreshWhileClosedIsACompleteNoOpEvenOnAPluginSection(): void
    {
        $controller = $this->controller();
        // Reach a plugin: section the ordinary way, then close — refresh()
        // must not act on it while closed, even though closed leaves $section
        // pointing at it.
        $this->lifecycle->names = ['demo'];
        $this->pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary path', 'kind' => 'text', 'default' => '', 'options' => null, 'hint' => null, 'min' => null, 'max' => null, 'step' => null],
        ]);
        $controller->open();
        $this->nav->setPluginEntries(['demo']);
        $this->selectSettingsSection($this->nav, 'plugin:demo');
        $controller->close();

        $this->lifecycle->names = []; // the plugin "vanishes" while closed
        Probe::set($this->tui, 'renderRequested', false);

        $controller->refresh();

        $this->assertSame('plugin:demo', $this->nav->selectedId(), 'refresh() while closed must not fall back to Plugins');
        $this->assertFalse(Probe::get($this->tui, 'renderRequested'), 'refresh() while closed must not request a render either');
    }

    /**
     * refresh()'s plugin-vanished check reads exactly `substr($section, 7)`
     * (the length of "plugin:"); an off-by-one or a dropped substr entirely
     * would misread a still-existing plugin's id and wrongly bounce to the
     * Plugins section.
     */
    public function testRefreshOnAStillExistingPluginPageStaysPutRegardlessOfSubstrPrecision(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['x']; // a one-character id: any offset error mangles it
        $this->pluginSettings->registerFor('x', []);
        $controller->open();
        $this->nav->setPluginEntries(['x']);
        $this->selectSettingsSection($this->nav, 'plugin:x');

        $controller->refresh();

        $this->assertSame('plugin:x', $this->nav->selectedId(), 'the plugin still exists, so refresh() must not bounce away from its page');
    }

    /**
     * The first `if` uses && between the plugin-prefix check and the
     * vanished-plugin check; a mutant that turns it into || would make
     * ANY non-plugin section wrongly bounce to Plugins too (hasPlugin('')
     * on a plugin-less id is false, so !hasPlugin(...) is true, and ||
     * only needs one true side).
     */
    public function testRefreshOnAnOrdinarySectionNeverBouncesToPlugins(): void
    {
        $controller = $this->controller();
        $controller->open(); // opens on 'general'
        Probe::set($this->tui, 'renderRequested', false);

        $controller->refresh();

        $this->assertSame('general', $this->nav->selectedId());
        $this->assertFalse(Probe::get($this->tui, 'renderRequested'), 'an ordinary section is unaffected by refresh() and must not re-render either');
    }

    /**
     * The vanished-plugin fallback branch calls selectSection('plugins') —
     * which already calls setRows()+invalidate() itself — and then must
     * `return`, not fall into the second `if` and repeat both calls. Both
     * calls end up producing the SAME rows either way (nothing changed in
     * between), so no state assertion can catch a redundant repeat; only the
     * form's own render-revision counter (bumped once per invalidate()) can.
     */
    public function testFallingBackToPluginsAfterARemovedPluginInvalidatesOnlyOnce(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->pluginSettings->registerFor('demo', []);
        $controller->open();
        $this->nav->setPluginEntries(['demo']);
        $this->selectSettingsSection($this->nav, 'plugin:demo');
        $this->lifecycle->names = []; // vanished
        $revisionBefore = $this->form->getRenderRevision();
        $controller->refresh();
        $refreshDelta = $this->form->getRenderRevision() - $revisionBefore;

        // The yardstick: selectSection('plugins') called directly, from the
        // exact same starting state, costs however many invalidate()s it
        // costs on its own — a repeat-without-return would cost strictly
        // more than that on top of it.
        $controller2 = $this->controller();
        $this->lifecycle->names = ['demo'];
        $controller2->open();
        $this->nav->setPluginEntries(['demo']);
        $this->selectSettingsSection($this->nav, 'plugin:demo');
        $revisionBefore2 = $this->form->getRenderRevision();
        Probe::invoke($controller2, 'selectSection', 'plugins');
        $directCallDelta = $this->form->getRenderRevision() - $revisionBefore2;

        $this->assertSame($directCallDelta, $refreshDelta, 'the fallback must not fall through and repeat setRows()+invalidate() a second time, on top of selectSection()\'s own');
    }

    public function testRefreshOnThePluginsListingSectionReRendersItsRows(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'plugins');
        mkdir($this->project->dir.'/.porta/plugins/late', permissions: 0o777, recursive: true);
        file_put_contents($this->project->dir.'/.porta/plugins/late/plugin.php', data: "<?php\n");
        Probe::set($this->tui, 'renderRequested', false);

        $controller->refresh();

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'refresh() on the plugins section must request a render');
        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertMatchesRegularExpression('/late \(project\)\s+\[Remove\]/', $screen);
    }

    /**
     * The widget's own capture-completion handler already clears its
     * capturingIndex and calls the onRebind callback BEFORE the controller
     * does anything, so neither "capture mode ended" nor a bare "does 'a'
     * appear anywhere on screen" (many labels already contain the letter)
     * distinguishes a missing setRows() call — only the specific row's own
     * text does, since setRows() is the only thing that ever refreshes it
     * with the freshly-persisted binding.
     */
    public function testRebindingReRendersTheKeybindingsList(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->selectSettingsSection($this->nav, 'keybindings');
        // cursor_up (row 0, the default selection) is shadowed by the
        // editor's own baked-in arrow-key binding, which always wins over an
        // ideKeys rebind in rows()'s priority order — its displayed binding
        // would never change either way. move_tab_left has no default at any
        // priority level, so a rebind is the only thing that could ever fill
        // it in.
        $rows = Probe::get($this->keymapList, 'rows');
        $targetIndex = array_search('move_tab_left', array_column($rows, 'action'), strict: true);
        \assert(false !== $targetIndex, description: 'move_tab_left is a registered action');
        for ($i = 0; $i < $targetIndex; ++$i) {
            $this->keymapList->handleInput("\x1b[B");
        }
        $rowBefore = Probe::get($this->keymapList, 'rows')[$targetIndex]->binding;

        $this->keymapList->handleInput("\r"); // arm capture on move_tab_left
        $this->keymapList->handleInput('a');

        $rowAfter = Probe::get($this->keymapList, 'rows')[$targetIndex]->binding;
        $this->assertSame('', $rowBefore, 'move_tab_left starts unbound');
        $this->assertSame('a', $rowAfter, "the rebound action's own row must reflect the new binding, proving setRows() re-ran");
    }

    public function testCycleFocusAlwaysReportsItHandledTheKey(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertTrue($controller->cycleFocus(1), 'content -> nav is handled');
        $this->assertTrue($controller->cycleFocus(1), 'nav -> content is handled too');
    }

    /**
     * focusNav()/focusContent() both call setFocus() then their own explicit
     * invalidate() — but Tui::setFocus() itself already requests a render on
     * any ACTUAL focus change, which would mask a removed invalidate() call
     * in a test that changes focus. Pre-position focus at the target first,
     * so setFocus() is a no-op and only the explicit invalidate() is left.
     */
    public function testFocusNavAndFocusContentEachRequestARenderEvenWhenAlreadyFocused(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->tui->setFocus($this->nav);
        Probe::set($this->tui, 'renderRequested', false);
        Probe::invoke($controller, 'focusNav');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'focusNav() must request a render even when nav was already focused');

        $this->tui->setFocus($this->form);
        Probe::set($this->tui, 'renderRequested', false);
        Probe::invoke($controller, 'focusContent');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'focusContent() must request a render even when the form was already focused');
    }

    private static function formatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            new WorkspaceEditor(new BufferManager(), $editor, $pool),
            $editor,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            new FakeScheduler(),
            TestTui::create(80, 24),
        );
    }

    public function testTheOneTwoKeysJumpBetweenFormAndKeybindings(): void
    {
        $controller = $this->controller();
        $controller->open();

        // '2' on the form jumps to the Keybindings page...
        $this->form->setFocused(true);
        $this->form->handleInput('2');
        $this->assertSame('keybindings', $this->nav->selectedId(), "the form's advertised '2' key swaps to the keybindings page");

        // ...and '1' on the keybindings list jumps back to the Settings form.
        $this->keymapList->setFocused(true);
        $this->keymapList->handleInput('1');
        $this->assertSame('general', $this->nav->selectedId(), "the list's advertised '1' key swaps back");
    }
}
