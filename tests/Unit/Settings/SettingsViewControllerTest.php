<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsDebugController;
use Porta\Config\SettingsGitController;
use Porta\Config\SettingsHighlightController;
use Porta\Config\SettingsKeymapController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Config\SettingsPluginsController;
use Porta\Debug\DebugAdapters;
use Porta\Editor\BufferManager;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitSettings;
use Porta\Highlight\ColorMode;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Layout\EditorAreaPanes;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginSettings;
use Porta\Plugin\ToolInstaller;
use Porta\Search\TodoTags;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\CursorStyle;
use Porta\Ui\EditorArea;
use Porta\Ui\Notifier;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
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
    private TempProject $project;
    private Tui $tui;
    private SettingsNavWidget $nav;
    private SettingsFormWidget $form;
    private KeybindingsListWidget $keymapList;
    private ContainerWidget $main;
    private ContainerWidget $content;
    private CodeEditorWidget $editorWidget;
    private PluginSettings $pluginSettings;
    private FakePluginLifecycle $lifecycle;

    private function controller(?WorkspaceViews $views = null): SettingsViewController
    {
        $this->project = new TempProject();
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);

        $this->nav = new SettingsNavWidget(new Translator('en'));
        $this->form = new SettingsFormWidget(new Translator('en'));
        $this->form->setId('settings-form');
        $this->keymapList = new KeybindingsListWidget(new Translator('en'));
        $this->keymapList->setId('settings-keys');
        $this->content = new ContainerWidget();
        $area = new ContainerWidget();
        $area->setId('settings-area');
        $area->add($this->nav)->add($this->content);
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
            new DiffViewWidget(new Translator('en')),
            new HelpViewerWidget(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(
                rootDir: $this->project->dir,
                editor: $editor,
                settingsNav: $this->nav,
                settingsForm: $this->form,
                settingsKeys: $this->keymapList,
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
            $applier,
            new BreadcrumbsWidget(),
            new TodoTags(),
        );
        $keymap = new SettingsKeymapController($editor, new Keybindings([]), $notifier, $this->project->dir, new Translator('en'));
        $languages = new SettingsLanguageController($editor, $notifier, $this->project->dir, new Translator('en'), []);
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
        $lsp = new SettingsLspController($this->project->dir, [], $reconfigurer, $notifier, new Translator('en'));
        $debug = new SettingsDebugController($this->project->dir, new DebugAdapters([]), $notifier, new Translator('en'));
        $this->pluginSettings = new PluginSettings($this->project->dir, []);
        $this->lifecycle = new FakePluginLifecycle();
        $plugins = new SettingsPluginsController(
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            $this->pluginSettings,
            $notifier,
            new Translator('en'),
            $this->lifecycle,
            new PluginManager(new PluginEvents(), $this->pluginSettings),
            new ToolInstaller($this->project->dir, []),
        );
        $gitSettings = new GitSettings([]);
        $status = new StatusBarWidget();
        $git = new SettingsGitController(
            IdeConfig::DEFAULT_SETTINGS,
            $gitSettings,
            $editor,
            new GitRefresher(new GitInfo($this->project->dir), new BufferManager(), $editor, $status, $this->tui, $gitSettings),
            $status,
            $this->tui,
            $notifier,
            new Translator('en'),
            $this->project->dir,
        );

        return new SettingsViewController(
            $this->nav,
            $this->form,
            $this->keymapList,
            $this->main,
            $area,
            $this->content,
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
            $this->tui,
            $settings,
            $keymap,
            $languages,
            $lsp,
            $debug,
            $plugins,
            $git,
            new SettingsHighlightController(
                new HighlightRegistry(new NoHighlightPlugins(), new HighlightPreferences()),
                new Translator('en'),
                $this->project->dir,
                IdeConfig::load($this->project->dir),
            ),
            editorArea: new EditorArea(new BufferManager(), $this->editorWidget = new CodeEditorWidget(), new ImageViewerWidget(), new HelpViewerWidget(), $this->tui),
            views: $views ?? new WorkspaceViews(),
        );
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

    public function testWorkspaceViewsResolveTheFocusTargetWhileOpen(): void
    {
        $views = new WorkspaceViews();
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

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
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

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // → Keybindings
        $this->assertSame(['settings-keys'], $this->ids($this->content));
        $this->assertSame($this->keymapList, $controller->focusTarget());

        $screen = implode("\n", $this->keymapList->render(new \Symfony\Component\Tui\Render\RenderContext(80, 20)));
        $this->assertStringContainsString('actions', $screen, 'the keybindings list is populated');
    }

    public function testNavSelectingInterfaceShowsTheAppearanceRowsAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->nav->handleInput("\x1b[B"); // → Interface
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

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
        $this->nav->handleInput("\x1b[B"); // skips the Version control header → Git
        $this->nav->handleInput("\x1b[B"); // skips the Languages header → PHP
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

    public function testNavSelectingGitShowsGitRowsAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
        $this->nav->handleInput("\x1b[B"); // skips the Version control header → Git
        $this->assertSame('vcs:git', $this->nav->selectedId());
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 12)));
        $this->assertStringContainsString('Show branch in status bar', $screen);
        $this->assertStringContainsString('Git executable path', $screen);

        // Toggle the first row (Show branch) → routes to the git controller and persists.
        $this->form->handleClick(0);
        $this->assertFalse(IdeConfig::load($this->project->dir)->settings['gitShowBranch']);
    }

    public function testEditingTheLspRowRoutesToTheLspControllerAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
        $this->nav->handleInput("\x1b[B"); // Git
        $this->nav->handleInput("\x1b[B"); // PHP

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
        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
        $this->nav->handleInput("\x1b[B"); // Git
        $this->nav->handleInput("\x1b[B"); // PHP

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
        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings

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

        $controller->cycleFocus();
        $this->assertSame($this->nav, $this->tui->getFocus(), 'content → nav');

        $controller->cycleFocus();
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

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
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

        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
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
        $this->nav->handleInput("\x1b[B"); // → Interface
        $this->nav->handleInput("\x1b[B"); // → Highlighting
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // Plugins
        $this->nav->handleInput("\x1b[B"); // demo
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

    private static function formatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            new WorkspaceEditor(new BufferManager(), $editor, $pool),
            $editor,
            new Notifier(new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()))),
            new Translator('en'),
            new FakeScheduler(),
            new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet())),
        );
    }
}
