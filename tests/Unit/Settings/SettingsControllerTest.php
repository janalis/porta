<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Editor\LineNumberMode;
use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\ColorMode;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsControllerTest extends TestCase
{
    use PreservesPath;
    use StatusReads;

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
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
    private function controller(
        string $rootDir,
        CodeEditorWidget $editor,
        StatusBarWidget $status,
        array $settings = [],
        ?BreadcrumbsWidget $breadcrumbs = null,
    ): SettingsController {
        $breadcrumbs ??= new BreadcrumbsWidget();
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
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            $translator,
            new DiffViewWidget($t),
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: $rootDir),
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
            $breadcrumbs,
        );
    }

    public function testFormRowsExposeOnlyTheGeneralGroup(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['autoPairs' => false]);

        $rows = $controller->formRows();
        $byKey = array_column($rows, null, 'key');

        $this->assertSame(
            ['inlineDiagnostics', 'autoPairs', 'formatOnSave', 'dotPath', 'svgConverter', 'plantuml', 'graphRender'],
            array_column($rows, 'key'),
            'General holds editor behavior (incl. the restart-required converter rows); appearance moved to Interface',
        );
        $this->assertFalse($byKey['autoPairs']->value);
        $this->assertSame(SettingsRowKind::Bool, $byKey['autoPairs']->kind);
        $this->assertFalse($byKey['inlineDiagnostics']->restart, 'live toggles need no restart');
        $this->assertSame(SettingsRowKind::Text, $byKey['dotPath']->kind, 'a path is a free-text row');
    }

    public function testInterfaceRowsExposeTheAppearanceSettings(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['theme' => 'light']);

        $rows = $controller->interfaceRows();
        $byKey = array_column($rows, null, 'key');

        $this->assertSame(
            ['language', 'theme', 'cursorShape', 'tabBarPosition', 'lineNumbers', 'stickyScroll', 'indentGuides', 'breadcrumbs'],
            array_column($rows, 'key'),
        );
        $this->assertSame(['en', 'fr', 'de', 'es', 'it', 'ja', 'he'], $byKey['language']->options);
        $this->assertSame(Translator::LOCALE_NAMES, $byKey['language']->optionLabels, 'the picker shows language names');
        $this->assertSame('light', $byKey['theme']->value);
        $this->assertSame(['dark', 'light', 'high-contrast'], $byKey['theme']->options);
        $this->assertSame(['bar', 'block', 'underline'], $byKey['cursorShape']->options);
        $this->assertSame(['top', 'bottom', 'left', 'right'], $byKey['tabBarPosition']->options);
        $this->assertSame('absolute', $byKey['lineNumbers']->value);
        $this->assertSame(['off', 'absolute', 'relative'], $byKey['lineNumbers']->options);
        $this->assertTrue($byKey['stickyScroll']->value);
        $this->assertSame(SettingsRowKind::Bool, $byKey['breadcrumbs']->kind);
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
        $controller = $this->controller($project->dir, $editor, $status);

        $controller->changeSetting('lineNumbers', 'relative');

        $this->assertSame(LineNumberMode::Relative, EditorState::display($editor)->lineNumbers(), 'applied to the editor');
        $this->assertSame('relative', IdeConfig::load($project->dir)->settings['lineNumbers'], 'persisted');
        $this->assertStringContainsString('Line numbers relative', $this->flashText($status));
        $this->assertStringNotContainsString('restart', $this->flashText($status), 'live change is immediate');
    }

    public function testChangingLineNumbersToOffHidesTheGutterNumbers(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget());

        $controller->changeSetting('lineNumbers', 'off');
        $this->assertSame(LineNumberMode::Off, EditorState::display($editor)->lineNumbers());
        $this->assertSame('off', IdeConfig::load($project->dir)->settings['lineNumbers']);
    }

    public function testCycleLineNumbersWalksOffAbsoluteRelative(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget());

        // Default absolute → relative → off → absolute.
        $controller->cycleLineNumbers();
        $this->assertSame(LineNumberMode::Relative, EditorState::display($editor)->lineNumbers());
        $controller->cycleLineNumbers();
        $this->assertSame(LineNumberMode::Off, EditorState::display($editor)->lineNumbers());
        $controller->cycleLineNumbers();
        $this->assertSame(LineNumberMode::Absolute, EditorState::display($editor)->lineNumbers());
    }

    public function testChangingTheNewInterfaceBoolsAppliesToTheEditorAndBreadcrumbs(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $breadcrumbs = new BreadcrumbsWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), breadcrumbs: $breadcrumbs);

        $controller->changeSetting('stickyScroll', false);
        $this->assertFalse(EditorState::display($editor)->stickyScroll());
        $controller->changeSetting('indentGuides', false);
        $this->assertFalse(EditorState::display($editor)->indentGuides());
        $controller->changeSetting('breadcrumbs', false);
        $this->assertFalse($breadcrumbs->isVisible());

        $controller->changeSetting('stickyScroll', true);
        $this->assertTrue(EditorState::display($editor)->stickyScroll());
        $controller->changeSetting('breadcrumbs', true);
        $this->assertTrue($breadcrumbs->isVisible());
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
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), ['lineNumbers' => 'relative', 'theme' => 'light']);

        $controller->applyAll(); // every descriptor applies live now
        $this->assertSame(LineNumberMode::Relative, EditorState::display($editor)->lineNumbers());
        $this->assertSame(['light'], $this->themeChanges, 'the theme hook applies at boot too');
        $this->assertSame(['bar'], $this->cursorShapeChanges, 'the cursor-shape hook applies at boot too');
        $this->assertSame(['top'], $this->tabBarPositionChanges, 'the tab-bar-position hook applies at boot too');
    }

    public function testApplyAllSkipsADescriptorWithNoApplyClosure(): void
    {
        // Every shipping descriptor applies live, but the model type permits a
        // null-apply (restart-only) descriptor; applyAll must skip such an entry
        // instead of invoking null. The descriptor table is readonly and built in
        // the constructor, so exercise the guard on an instance whose table
        // carries one null-apply row.
        $controller = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        $applied = [];
        Probe::set($controller, 'descriptors', [
            'live' => [
                'label' => 'Live',
                'kind' => 'bool',
                'restart' => false,
                'apply' => static function () use (&$applied): void {
                    $applied[] = 'live';
                },
            ],
            'restartOnly' => ['label' => 'Restart only', 'kind' => 'bool', 'restart' => true, 'apply' => null],
        ]);
        Probe::set($controller, 'settings', ['live' => true, 'restartOnly' => false]);

        $controller->applyAll();

        $this->assertSame(['live'], $applied, 'the null-apply descriptor is skipped; the live one still applies');
    }

    public function testUnknownKeyFlashesInsteadOfTogglingAnything(): void
    {
        $project = new TempProject(['composer.json' => '{}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        $settings = Services::resolve($ide, SettingsController::class);
        \assert($settings instanceof SettingsController, description: 'the settings property is a SettingsController');

        $settings->toggle('doesNotExist');

        $this->assertStringContainsString('Unknown setting "doesNotExist"', \Porta\Tests\Support\Toasts::last($ide));
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
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            new FakeScheduler(),
            TestTui::create(80, 24),
        );
    }
}
