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
                    IdeConfig::load($this->project->dir),
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
        $this->form->handleClick(0);
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

        $this->form->handleClick(0); // Inline diagnostics is General's first row now (default true → false)
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
        $this->assertStringContainsString('demo (project)  [Remove]', $screen);

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
        $this->assertStringContainsString('late (project)  [Remove]', $screen);
    }

    public function testPluginPageRoutesRowsAndChangesAndFallsBackWhenRemoved(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary path', 'kind' => 'text', 'default' => '', 'options' => null, 'hint' => null],
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
