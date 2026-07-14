<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Editor\BufferManager;
use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Settings\LiveSettingsApplier;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Porta\Ui\CursorStyle;
use Porta\Ui\Notifier;
use Porta\Ui\PaletteFactory;
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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsControllerTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /** @var list<string> */
    private array $languageChanges = [];

    /** @var list<string> */
    private array $themeChanges = [];

    /** @var list<string> */
    private array $cursorShapeChanges = [];

    /** @var list<string> */
    private array $tabBarPositionChanges = [];

    /**
     * @param array<string, int|bool|string> $settings
     */
    private function controller(string $rootDir, CodeEditorWidget $editor, StatusBarWidget $status, array $settings = []): SettingsController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $this->languageChanges = [];
        $this->themeChanges = [];
        $this->cursorShapeChanges = [];
        $this->tabBarPositionChanges = [];

        // A real applier over real holders; the dispatched events are the spies.
        $translator = new Translator('en');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LocaleChanged::class, function (LocaleChanged $e): void {
            $this->languageChanges[] = $e->locale;
        });
        $dispatcher->addListener(ThemeChanged::class, function (ThemeChanged $e): void {
            $this->themeChanges[] = $e->theme;
        });
        $dispatcher->addListener(CursorShapeChanged::class, function (CursorShapeChanged $e): void {
            $this->cursorShapeChanges[] = $e->shape;
        });
        $dispatcher->addListener(TabBarPositionChanged::class, function (TabBarPositionChanged $e): void {
            $this->tabBarPositionChanges[] = $e->position->value;
        });
        $t = new Translator('en');
        $applier = new LiveSettingsApplier(
            PaletteFactory::createSwitchable(true, 'dark'),
            TerminalThemeFactory::createSwitchable(true, 'dark'),
            new CursorStyle(),
            $translator,
            new BufferManager(),
            new DiffViewWidget($t),
            new HelpViewerWidget(),
            new TabBarWidget(),
            new WorkspaceLayout(
                new FileTreeWidget($rootDir),
                new TabBarWidget(),
                new BreadcrumbsWidget(),
                new CodeEditorWidget(),
                new GitTabsWidget($t),
                new ChangesListWidget($t),
                new CommitBoxWidget($t),
                new DiffViewWidget($t),
                new HistoryListWidget($t),
                new SettingsNavWidget($t),
                new SettingsFormWidget($t),
                new KeybindingsListWidget($t),
                new MenuBarWidget(),
                new ContextMenu(),
                new GoToFileWidget($t, $rootDir),
                new FindBarWidget($t),
                new ResultsPanelWidget(),
                new DebugPanelWidget(),
                new TerminalPanelWidget(),
                new StatusBarWidget(),
            ),
            $dispatcher,
            self::formatOnSave(),
            true,
        );

        return new SettingsController(
            $settings + IdeConfig::DEFAULT_SETTINGS,
            $editor,
            new Notifier($status, $tui),
            $rootDir,
            $translator,
            $applier,
        );
    }

    private function flashText(StatusBarWidget $status): string
    {
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);

        return (string) ($flash['text'] ?? '');
    }

    public function testFormRowsExposeEveryDescriptorWithCurrentValues(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['theme' => 'light', 'autoPairs' => false]);

        $rows = $controller->formRows();
        $byKey = array_column($rows, null, 'key');

        $this->assertSame(
            ['language', 'inlineDiagnostics', 'relativeLineNumbers', 'autoPairs', 'formatOnSave', 'theme', 'cursorShape', 'tabBarPosition'],
            array_column($rows, 'key'),
            'language first; indent settings moved to the Languages section',
        );
        $this->assertSame(['en', 'fr', 'de', 'es', 'it', 'ja', 'he'], $byKey['language']->options);
        $this->assertSame(Translator::LOCALE_NAMES, $byKey['language']->optionLabels, 'the picker shows language names');
        $this->assertFalse($byKey['autoPairs']->value);
        $this->assertSame('bool', $byKey['autoPairs']->kind);
        $this->assertFalse($byKey['inlineDiagnostics']->restart, 'live toggles need no restart');
        $this->assertSame('light', $byKey['theme']->value);
        $this->assertSame(['dark', 'light', 'high-contrast'], $byKey['theme']->options);
        $this->assertSame('bar', $byKey['cursorShape']->value);
        $this->assertSame(['bar', 'block', 'underline'], $byKey['cursorShape']->options);
        $this->assertSame('top', $byKey['tabBarPosition']->value);
        $this->assertSame(['top', 'bottom', 'left', 'right'], $byKey['tabBarPosition']->options);
    }

    public function testChangingTheTabBarPositionFiresItsApplyClosureAndPersists(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('tabBarPosition', 'left');

        $this->assertSame(['left'], $this->tabBarPositionChanges, 'the apply closure fired with the new position');
        $this->assertSame('left', IdeConfig::load($project->dir)->settings['tabBarPosition'], 'persisted');
        $this->assertStringNotContainsString('restart', $this->flashText($status), 'the strip moves live');
    }

    public function testChangingTheCursorShapeFiresItsApplyClosureAndPersists(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget());

        $controller->changeSetting('cursorShape', 'block');

        $this->assertSame(['block'], $this->cursorShapeChanges, 'the apply closure fired with the new shape');
        $this->assertSame('block', IdeConfig::load($project->dir)->settings['cursorShape'], 'persisted');
    }

    public function testChangingALiveSettingAppliesToTheEditorAndPersists(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $controller = $this->controller($project->dir, $editor, $status, ['relativeLineNumbers' => false]);

        $controller->changeSetting('relativeLineNumbers', true);

        $this->assertTrue($editor->hasRelativeLineNumbers(), 'applied to the editor');
        $this->assertTrue(IdeConfig::load($project->dir)->settings['relativeLineNumbers'], 'persisted');
        $this->assertStringContainsString('Relative line numbers on', $this->flashText($status));
        $this->assertStringNotContainsString('restart', $this->flashText($status), 'live change is immediate');
    }

    public function testChangingLanguageFiresTheLocaleHookAndPersists(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('language', 'fr');

        $this->assertSame(['fr'], $this->languageChanges, 'the UI-locale hook fired live');
        $this->assertSame('fr', IdeConfig::load($project->dir)->settings['language'], 'persisted');
        $this->assertStringNotContainsString('restart', $this->flashText($status), 'language switches live');
    }

    public function testChangingThemeFiresTheThemeHookAndPersists(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('theme', 'light');

        $this->assertSame(['light'], $this->themeChanges, 'the theme hook fired live');
        $this->assertSame('light', IdeConfig::load($project->dir)->settings['theme'], 'persisted');
        $this->assertStringNotContainsString('restart', $this->flashText($status), 'theme switches live');
    }

    public function testChangingAnUnknownSettingFlashesAndPersistsNothing(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('bogus', true);

        $this->assertStringContainsString('Unknown setting "bogus"', $this->flashText($status));
        $this->assertFalse(is_file($project->path('.porta.json')));
    }

    public function testAFailedPersistIsFlaggedInTheFlash(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json')); // a directory blocks the file write

        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());
        $controller->changeSetting('autoPairs', false);

        $this->assertStringContainsString('(not saved)', $this->flashText($status));
    }

    public function testApplyAllAppliesEverySetting(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), ['relativeLineNumbers' => true, 'theme' => 'light']);

        $controller->applyAll(); // every descriptor applies live now
        $this->assertTrue($editor->hasRelativeLineNumbers());
        $this->assertSame(['light'], $this->themeChanges, 'the theme hook applies at boot too');
        $this->assertSame(['bar'], $this->cursorShapeChanges, 'the cursor-shape hook applies at boot too');
        $this->assertSame(['top'], $this->tabBarPositionChanges, 'the tab-bar-position hook applies at boot too');
    }

    public function testUnknownKeyFlashesInsteadOfTogglingAnything(): void
    {
        $project = new TempProject(['composer.json' => '{}']);
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        $settings = new \ReflectionProperty($ide, 'settings')->getValue($ide);
        \assert($settings instanceof SettingsController);

        $settings->toggle('doesNotExist');

        $status = $ide->getTui()->getById('status');
        \assert($status instanceof StatusBarWidget);
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);
        $this->assertStringContainsString('Unknown setting "doesNotExist"', (string) ($flash['text'] ?? ''));
        $this->assertFalse(is_file($project->path('.porta.json')), 'nothing was persisted');
    }

    private static function formatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            new WorkspaceEditor(new BufferManager(), $editor, $pool),
            $editor,
            new FakeScheduler(),
            new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet())),
        );
    }
}
