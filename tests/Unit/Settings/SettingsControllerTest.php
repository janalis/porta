<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\EditorPanes;
use Porta\Editor\InlayHintMode;
use Porta\Editor\LineNumberMode;
use Porta\Editor\PreviewRoutes;
use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\ColorMode;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Layout\ViewRegions;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\WorkspaceEditor;
use Porta\Onboarding\OnboardingState;
use Porta\Overlay\State\TooltipCenter;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\FileIndex;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestCompletionController;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Tests\Support\Toasts;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Ui\Tooltip;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsControllerTest extends TestCase
{
    use PreservesPath;
    use StatusReads;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
    }

    /** @var list<string> */
    private array $languageChanges = [];

    /** @var list<string> */
    private array $themeChanges = [];

    /** @var list<CursorShape> */
    private array $cursorShapeChanges = [];

    /** @var list<string> */
    private array $tabBarPositionChanges = [];

    private FormatOnSave $formatOnSave;
    private FileIndex $files;
    private TooltipCenter $tooltips;
    private PreviewRoutes $previews;

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
            $this->themeChanges[] = $e->theme->value;
        });
        $dispatcher->addListener(CursorShapeChanged::class, function (CursorShapeChanged $e): void {
            $this->cursorShapeChanges[] = $e->shape;
        });
        $dispatcher->addListener(TabBarPositionChanged::class, function (TabBarPositionChanged $e): void {
            $this->tabBarPositionChanges[] = $e->position->value;
        });
        new Translator('en');
        $applier = new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            $translator,
            new ViewRegions(Styles::dark()),
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: $rootDir),
            $dispatcher,
            $this->formatOnSave = self::formatOnSave(),
            TestCompletionController::idle($editor),
            PixelGraphics::ratio(),
            true,
        );

        // Every assertion below reads the project's .porta.json back, so the
        // session scope is pinned to Project; SettingsScope defaults to Global.
        $scope = new SettingsScope(ConfigFiles::for($rootDir));
        $scope->set(ConfigScope::Project);

        return new SettingsController(
            $settings + IdeConfig::DEFAULT_SETTINGS,
            $editor->display,
            new Notifier($status, $tui),
            $translator,
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($rootDir)),
            $applier,
            $breadcrumbs,
            $this->files = new FileIndex($rootDir),
            $this->tooltips = new TooltipCenter(new FakeScheduler(), $tui),
            $this->previews = new PreviewRoutes(new PluginManager(new EventDispatcher(), new PluginSettings($rootDir, []))),
        );
    }

    /**
     * Held here rather than in the method that makes it: TempProject removes
     * its directory in the destructor, and a local would take the writer's
     * target with it the moment the helper returned.
     */
    private TempProject $descriptorTableProject;

    /**
     * A SettingsController with only the collaborators a descriptor-table case
     * touches — no constructor, so the real table is never built.
     *
     * The table is readonly and assembled in the constructor, so a case about
     * ONE descriptor's shape (a restart-only note, a key absent from settings)
     * can only be written by standing a controller up around a hand-built one.
     * The caller supplies the status bar because reading the flash back off it
     * is what most of those cases assert.
     */
    private function controllerForAHandBuiltDescriptorTable(StatusBarWidget $status): SettingsController
    {
        $controller = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        Probe::set($controller, 'notifier', new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24))));
        Probe::set($controller, 'translator', new Translator('en'));
        $this->descriptorTableProject = new TempProject();
        Probe::set($controller, 'writer', new PortaJsonWriter(ConfigFiles::for($this->descriptorTableProject->dir)));
        // Pinned to Project so the write lands in the temp project rather than
        // the suite's shared fixture home, which the Global default names.
        $scope = new SettingsScope(ConfigFiles::for($this->descriptorTableProject->dir));
        $scope->set(ConfigScope::Project);
        Probe::set($controller, 'scope', $scope);

        return $controller;
    }

    public function testFormRowsExposeOnlyTheGeneralGroup(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['autoPairs' => false]);

        $rows = $controller->formRows();
        $byKey = \array_column($rows, null, 'key');

        $this->assertSame(
            ['autoPairs', 'formatOnSave', 'markdownPreview', 'searchIncludeVendor'],
            \array_column($rows, 'key'),
            'General holds editor behavior (the viewer-tool paths live on their plugins\' pages now); appearance moved to Interface',
        );
        $this->assertFalse($byKey['autoPairs']->value);
        $this->assertSame(SettingsRowKind::Bool, $byKey['autoPairs']->kind);
        $this->assertFalse($byKey['autoPairs']->restart, 'live toggles need no restart');
    }

    public function testInterfaceRowsExposeTheAppearanceSettings(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['theme' => 'light']);

        $rows = $controller->interfaceRows();
        $byKey = \array_column($rows, null, 'key');

        $this->assertSame(
            [
                'language',
                'theme',
                'cursorShape',
                'pixelRatio',
                'tabBarPosition',
                'breadcrumbs',
                'lineNumbers',
                'stickyScroll',
                'indentGuides',
                'showWhitespace',
                'methodSeparators',
                'diagnosticMessages',
                'inlayHints',
                'tooltips',
                'tooltipDelay',
            ],
            \array_column($rows, 'key'),
        );
        $this->assertSame(['auto', 'en', 'fr', 'de', 'es', 'it', 'ja', 'he'], $byKey['language']->options);
        $this->assertSame(['auto' => 'System (English)'] + Translator::LOCALE_NAMES, $byKey['language']->optionLabels, 'the picker shows language names, auto as the system one');
        $this->assertSame('auto', $byKey['language']->default, 'an unconfigured install follows the system');
        $this->assertSame('light', $byKey['theme']->value);
        $this->assertSame(['dark', 'light', 'high-contrast'], $byKey['theme']->options);
        $this->assertSame(['bar', 'block', 'underline'], $byKey['cursorShape']->options);
        $this->assertSame(['auto', '1', '1.25', '1.5', '2', '3'], $byKey['pixelRatio']->options);
        $this->assertSame('auto', $byKey['pixelRatio']->value);
        $this->assertFalse($byKey['pixelRatio']->restart, 'a ratio applies live');
        $this->assertSame(['top', 'bottom', 'left', 'right'], $byKey['tabBarPosition']->options);
        $this->assertSame('absolute', $byKey['lineNumbers']->value);
        $this->assertSame(['off', 'absolute', 'relative'], $byKey['lineNumbers']->options);
        $this->assertSame(['none', 'inline', 'menu'], $byKey['diagnosticMessages']->options);
        $this->assertSame(['none', 'inline', 'tooltip'], $byKey['inlayHints']->options);
        $this->assertFalse($byKey['diagnosticMessages']->restart, 'a mode switch applies live');
        $this->assertFalse($byKey['inlayHints']->restart, 'a mode switch applies live');
        $this->assertTrue($byKey['stickyScroll']->value);
        $this->assertSame(SettingsRowKind::Bool, $byKey['breadcrumbs']->kind);
    }

    /** The tooltip rows: a switch and a stepped delay, both applied to the one tooltip live. */
    public function testTheTooltipRowsApplyLive(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['tooltipDelay' => 300]);

        $byKey = \array_column($controller->interfaceRows(), null, 'key');
        $this->assertTrue($byKey['tooltips']->value);
        $this->assertSame(SettingsRowKind::Int, $byKey['tooltipDelay']->kind);
        $this->assertSame([300, 0, 2000, 100, 500], [
            $byKey['tooltipDelay']->value,
            $byKey['tooltipDelay']->min,
            $byKey['tooltipDelay']->max,
            $byKey['tooltipDelay']->step,
            $byKey['tooltipDelay']->default,
        ]);
        $this->assertTrue($byKey['tooltipDelay']->isModified(), 'an int compares as an int, so 300 differs from 500 and 500 would not');

        $controller->applyAll();
        $this->tooltips->point(new Tooltip('k', 't', 0, 0, 1));
        $this->assertNull($this->tooltips->shown(), 'on, with a dwell');

        $controller->changeSetting('tooltipDelay', 0);
        $this->tooltips->point(new Tooltip('j', 't', 0, 0, 1));
        $this->assertSame('j', $this->tooltips->shown()?->key, 'no dwell at 0');

        $controller->changeSetting('tooltips', false);
        $this->assertNull($this->tooltips->shown(), 'off takes the card');
        $this->assertSame(0, IdeConfig::load($project->dir)->settings['tooltipDelay'], 'persisted as an int');
    }

    /**
     * The docblock states every row in this table is live (no restart-required
     * row exists here anymore — those moved to their plugins' own pages), so
     * this is a table-wide invariant, not a per-row spot check.
     */
    public function testNoRowInEitherGroupEverRequiresARestart(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget());

        foreach ([...$controller->formRows(), ...$controller->interfaceRows()] as $row) {
            $this->assertFalse($row->restart, $row->key.' never needs a restart');
        }
    }

    /**
     * A Bool-kind row's value must be a strict bool and a non-Bool row's value
     * a strict string, even when the underlying setting was stored as some
     * other scalar (e.g. a hand-edited .porta.json) — rowsForGroup()'s casts
     * are what normalize it for the widget.
     */
    public function testRowValuesAreCoercedToTheirDeclaredKindRegardlessOfTheStoredScalarType(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['autoPairs' => 1, 'theme' => 0]);

        $general = \array_column($controller->formRows(), null, 'key');
        $interface = \array_column($controller->interfaceRows(), null, 'key');

        $this->assertTrue($general['autoPairs']->value, 'a truthy non-bool coerces to strict bool true');
        $this->assertSame('0', $interface['theme']->value, 'a non-string coerces to a strict string');
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

        $this->assertSame([CursorShape::Block], $this->cursorShapeChanges, 'the apply closure fired with the new shape');
        $this->assertSame('block', IdeConfig::load($project->dir)->settings['cursorShape'], 'persisted');
    }

    public function testChangingALiveSettingAppliesToTheEditorAndPersists(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $controller = $this->controller($project->dir, $editor, $status);

        $controller->changeSetting('lineNumbers', 'relative');

        $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers, 'applied to the editor');
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
        $this->assertSame(LineNumberMode::Off, $editor->display->lineNumbers);
        $this->assertSame('off', IdeConfig::load($project->dir)->settings['lineNumbers']);
    }

    public function testCycleLineNumbersWalksOffAbsoluteRelative(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget());

        // Default absolute → relative → off → absolute.
        $controller->cycleChoice('lineNumbers');
        $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers);
        $controller->cycleChoice('lineNumbers');
        $this->assertSame(LineNumberMode::Off, $editor->display->lineNumbers);
        $controller->cycleChoice('lineNumbers');
        $this->assertSame(LineNumberMode::Absolute, $editor->display->lineNumbers);
        $this->assertSame('absolute', $controller->choice('lineNumbers'));
    }

    /** Both hint modes walk their three options and wrap, applied to the editor live. */
    public function testCyclingTheHintModesAppliesEachStepLive(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), IdeConfig::DEFAULT_SETTINGS);

        $seen = [];
        for ($i = 0; $i < 3; ++$i) {
            $controller->cycleChoice('diagnosticMessages');
            $seen[] = $editor->display->diagnosticMessages;
        }
        $this->assertSame([DiagnosticMessageMode::Menu, DiagnosticMessageMode::None, DiagnosticMessageMode::Inline], $seen, 'inline → menu → none → inline');

        $seen = [];
        for ($i = 0; $i < 3; ++$i) {
            $controller->cycleChoice('inlayHints');
            $seen[] = $editor->display->inlayHints;
        }
        $this->assertSame([InlayHintMode::Tooltip, InlayHintMode::None, InlayHintMode::Inline], $seen, 'inline → tooltip → none → inline');
        $this->assertSame('inline', $this->written($project)['settings']['inlayHints'] ?? null, 'and each step persists');
    }

    /** A value outside the options (a hand-edited file) restarts the cycle at the first option. */
    public function testCyclingFromAnUnknownValueStartsAtTheFirstOption(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), ['inlayHints' => 'sideways'] + IdeConfig::DEFAULT_SETTINGS);

        $controller->cycleChoice('inlayHints');

        $this->assertSame(InlayHintMode::None, $editor->display->inlayHints);
    }

    /** Only a choice cycles: an unknown key, or a switch, flashes and changes nothing. */
    public function testCyclingSomethingThatIsNotAChoiceFlashesInstead(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $controller = $this->controller($project->dir, $editor, $status, IdeConfig::DEFAULT_SETTINGS);

        $controller->cycleChoice('autoPairs');

        $this->assertTrue($editor->display->autoPairs, 'a switch is not cycled');
        $this->assertStringContainsString('Unknown setting "autoPairs"', $this->flashText($status));
    }

    public function testChangingTheNewInterfaceBoolsAppliesToTheEditorAndBreadcrumbs(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $breadcrumbs = new BreadcrumbsWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), breadcrumbs: $breadcrumbs);

        $controller->changeSetting('stickyScroll', false);
        $this->assertFalse($editor->display->stickyScroll);
        $controller->changeSetting('indentGuides', false);
        $this->assertFalse($editor->display->indentGuides);
        $controller->changeSetting('breadcrumbs', false);
        $this->assertFalse($breadcrumbs->isVisible());

        // The two newest rows, for the same reason the others are here: a row
        // whose `apply` names the wrong setter is a wire nothing else checks,
        // and both of these render nothing until the option is on.
        $controller->changeSetting('methodSeparators', true);
        $this->assertTrue($editor->display->methodSeparators);
        $controller->changeSetting('showWhitespace', true);
        $this->assertTrue($editor->display->showWhitespace);

        $controller->changeSetting('stickyScroll', true);
        $this->assertTrue($editor->display->stickyScroll);
        $controller->changeSetting('breadcrumbs', true);
        $this->assertTrue($breadcrumbs->isVisible());
        $controller->changeSetting('methodSeparators', false);
        $this->assertFalse($editor->display->methodSeparators);
        $controller->changeSetting('showWhitespace', false);
        $this->assertFalse($editor->display->showWhitespace);
    }

    /**
     * The General group's three bool rows apply in BOTH directions — the
     * table-construction closures each have an (enable():disable()) ternary,
     * and the existing suite only ever exercised the default (enable) branch
     * via applyAll(), never disable().
     */
    public function testChangingTheGeneralBoolsAppliesToTheEditorAndFormatOnSaveInBothDirections(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget());

        $controller->changeSetting('autoPairs', false);
        $this->assertFalse($editor->display->autoPairs);
        $controller->changeSetting('formatOnSave', true);
        $this->assertTrue($this->formatOnSave->isEnabled());

        $controller->changeSetting('autoPairs', true);
        $this->assertTrue($editor->display->autoPairs);
        $controller->changeSetting('formatOnSave', false);
        $this->assertFalse($this->formatOnSave->isEnabled());
    }

    /** The flash's on/off state word must match the value that was actually set, not its opposite. */
    public function testChangingABoolSettingFlashesTheMatchingOnOrOffWord(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('autoPairs', true);
        $this->assertStringContainsString(' on', $this->flashText($status));

        $controller->changeSetting('autoPairs', false);
        $this->assertStringContainsString(' off', $this->flashText($status));
    }

    /**
     * No shipping descriptor is restart-required today (see the class
     * docblock), so the " · applies on restart" note's exact formatting can
     * only be exercised through a hand-built descriptor table, the same
     * technique testApplyAllSkipsADescriptorWithNoApplyClosure uses.
     */
    public function testARestartRequiredDescriptorAppendsTheExactRestartNote(): void
    {
        $status = new StatusBarWidget();
        $controller = $this->controllerForAHandBuiltDescriptorTable($status);
        Probe::set($controller, 'settings', ['restartOnly' => false]);
        Probe::set($controller, 'descriptors', [
            'restartOnly' => ['label' => 'Restart only', 'kind' => SettingsRowKind::Bool, 'group' => 'general', 'restart' => true, 'apply' => null],
        ]);

        $controller->changeSetting('restartOnly', true);

        $this->assertStringContainsString('Restart only on · applies on restart', $this->flashText($status));
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
        $this->assertFalse(\is_file($project->path('.porta.json')));
    }

    public function testAFailedPersistIsFlaggedInTheFlash(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json')); // a directory blocks the file write

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
        $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers);
        $this->assertSame(['light'], $this->themeChanges, 'the theme hook applies at boot too');
        $this->assertSame([CursorShape::Bar], $this->cursorShapeChanges, 'the cursor-shape hook applies at boot too');
        $this->assertSame(['top'], $this->tabBarPositionChanges, 'the tab-bar-position hook applies at boot too');
    }

    public function testSearchIncludeVendorReachesTheSharedFileIndex(): void
    {
        $project = new TempProject(['a.php' => '', 'vendor/pkg/Lib.php' => '']);
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget());

        $controller->changeSetting('searchIncludeVendor', true);
        $this->assertContains('vendor/pkg/Lib.php', \array_column($this->files->entries(), 'path'), 'the toggle re-scopes go-to-file live');

        $controller->changeSetting('searchIncludeVendor', false);
        $paths = \array_column($this->files->entries(), 'path');
        $this->assertContains('a.php', $paths);
        $this->assertNotContains('vendor/pkg/Lib.php', $paths, 'disabling narrows the live index back');
    }

    public function testTheMarkdownPreviewToggleRoutesFilesOpenedAfterIt(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget());
        $this->previews->claim('markdown', static fn (string $path): bool => \str_ends_with($path, '.md'));
        $this->assertSame('markdown', $this->previews->previewFor('/p/README.md'), 'on by default');

        $controller->changeSetting('markdownPreview', false);
        $this->assertNull($this->previews->previewFor('/p/README.md'), 'off: markdown opens as plain text');

        $controller->changeSetting('markdownPreview', true);
        $this->assertSame('markdown', $this->previews->previewFor('/p/README.md'));
    }

    public function testApplyAllSkipsADescriptorWithNoApplyClosure(): void
    {
        // Every shipping descriptor applies live, but the model type permits a
        // null-apply (restart-only) descriptor; applyAll must skip such an entry
        // instead of invoking null. The descriptor table is readonly and built in
        // the constructor, so exercise the guard on an instance whose table
        // carries one null-apply row. The null-apply row sits BETWEEN two live
        // ones (not trailing) so a continue-vs-break regression is observable —
        // a trailing null row can't distinguish "skip this one" from "stop here".
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
            'liveAfter' => [
                'label' => 'Live after',
                'kind' => 'bool',
                'restart' => false,
                'apply' => static function () use (&$applied): void {
                    $applied[] = 'liveAfter';
                },
            ],
        ]);
        Probe::set($controller, 'settings', ['live' => true, 'restartOnly' => false, 'liveAfter' => true]);

        $controller->applyAll();

        $this->assertSame(['live', 'liveAfter'], $applied, 'the null-apply descriptor is skipped, not aborted on; every live one still applies');
    }

    /**
     * Every shipping descriptor's key is always present in $settings (merged
     * with IdeConfig::DEFAULT_SETTINGS at construction), so toggle()'s
     * `?? false` fallback for a genuinely-absent key is otherwise dead code —
     * exercise it directly on a hand-built table/settings pair, PHPUnit's
     * failOnAllIssues would also turn a bare `$this->settings[$key]` access
     * (the CastBool mutant's rewritten form) into a failure via an undefined
     * array key warning.
     */
    public function testTogglingAKeyAbsentFromSettingsTreatsItAsFalseAndFlipsToTrue(): void
    {
        $status = new StatusBarWidget();
        $controller = $this->controllerForAHandBuiltDescriptorTable($status);
        Probe::set($controller, 'descriptors', [
            'missing' => ['label' => 'Missing', 'kind' => SettingsRowKind::Bool, 'group' => 'general', 'restart' => false, 'apply' => null],
        ]);
        Probe::set($controller, 'settings', []); // 'missing' is absent, not merely falsy

        $controller->toggle('missing');

        $this->assertStringContainsString(' on', $this->flashText($status), 'an absent value reads as false, so toggling it flips to true/"on"');
    }

    public function testUnknownKeyFlashesInsteadOfTogglingAnything(): void
    {
        $project = new TempProject(['composer.json' => '{}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        $settings = Services::resolve($ide, SettingsController::class);
        $this->assertInstanceOf(SettingsController::class, $settings, 'the settings property is a SettingsController');

        $settings->toggle('doesNotExist');

        $toasts = Toasts::all($ide);
        $this->assertStringContainsString('Unknown setting "doesNotExist"', $toasts[0] ?? '');
        $this->assertCount(1, $toasts, 'toggle() must not fall through into changeSetting() and flash a second, redundant warning');

        // The toast arms an expiry timer, and this Ide never run()s — so
        // nothing else will ever cancel it.
        Services::resolve($ide, Notifier::class)->dismissAll();
        $this->assertFalse(\is_file($project->path('.porta.json')), 'nothing was persisted');
    }

    private static function formatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            new WorkspaceEditor(new BufferManager(), $editor, $pool, new EventDispatcher(), new RefactoringUndo(new BufferManager(), new EventDispatcher())),
            $editor,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            new FakeScheduler(),
            TestTui::create(80, 24),
        );
    }

    /**
     * A hand-edited `.porta.json` may hold `1` where the default holds `true`.
     * The row's value is cast to its kind for exactly that reason, and the
     * marker compares against a default produced by the same call — otherwise
     * `1 !== true` and every checkbox on the page reads as modified.
     */
    public function testABooleanAtItsDefaultIsNotMarkedHoweverTheConfigSpelledIt(): void
    {
        $project = new TempProject();
        // `1`, not `true` — what a hand-edited .porta.json may well hold.
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['autoPairs' => 1] + IdeConfig::DEFAULT_SETTINGS);

        $row = $this->rowFor($controller->formRows(), 'autoPairs');

        $this->assertTrue($row->value, 'the value coerced to the row kind');
        $this->assertFalse($row->isModified(), 'and so did the default, so they compare equal');
    }

    public function testTheMarkerFollowsAChangeAndClearsWhenItIsPutBack(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), IdeConfig::DEFAULT_SETTINGS);

        $this->assertFalse($this->rowFor($controller->formRows(), 'autoPairs')->isModified(), 'a fresh project is at its defaults');

        $controller->changeSetting('autoPairs', false);
        $this->assertTrue($this->rowFor($controller->formRows(), 'autoPairs')->isModified(), 'the marker follows the change');

        $controller->changeSetting('autoPairs', true);
        $this->assertFalse($this->rowFor($controller->formRows(), 'autoPairs')->isModified(), 'and clears when it is put back');
    }

    /** @param list<\Porta\Settings\SettingsRow> $rows */
    private function rowFor(array $rows, string $key): SettingsRow
    {
        foreach ($rows as $row) {
            if ($key === $row->key) {
                return $row;
            }
        }

        self::fail(\sprintf('No row keyed "%s"', $key));
    }

    /**
     * Reset REMOVES the key rather than writing the default.
     *
     * Today the two produce the same value, so the shortcut is tempting. But a
     * default that later moves — `lineNumbers` and DEFAULT_ENABLED_PLUGINS have
     * both moved — would leave anyone who "reset" pinned to the old one
     * forever, silently. An absent key tracks the product.
     */
    public function testResetRemovesTheKeyRatherThanWritingTheDefault(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), IdeConfig::DEFAULT_SETTINGS);
        $controller->changeSetting('autoPairs', false);
        $this->assertFalse($this->written($project)['settings']['autoPairs'], 'set first, so the reset has something to undo');

        $controller->resetSetting('autoPairs');

        $this->assertArrayNotHasKey('autoPairs', $this->written($project)['settings'] ?? [], 'the key is gone, not set to its default');
        $this->assertFalse($this->rowFor($controller->formRows(), 'autoPairs')->isModified(), 'and the row is back at stock');
        $this->assertTrue($this->rowFor($controller->formRows(), 'autoPairs')->value, 'showing the default value in memory');
    }

    /** Removing the last setting takes the block with it rather than leaving `{}`. */
    public function testResettingTheLastSettingDropsTheSettingsBlock(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), IdeConfig::DEFAULT_SETTINGS);
        $controller->changeSetting('autoPairs', false);

        $controller->resetSetting('autoPairs');

        $this->assertArrayNotHasKey('settings', $this->written($project));
    }

    /** A reset applies live, exactly as a change does. */
    public function testResetAppliesTheDefaultLive(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $controller = $this->controller($project->dir, $editor, new StatusBarWidget(), IdeConfig::DEFAULT_SETTINGS);
        $controller->changeSetting('theme', 'light');
        $this->assertSame(['light'], $this->themeChanges, 'the change applied');

        $controller->resetSetting('theme');

        $this->assertSame(['light', 'dark'], $this->themeChanges, 'and so did the reset, back to the default');
    }

    public function testResettingAnUnknownSettingFlashesAndTouchesNothing(): void
    {
        $project = new TempProject();
        $status = new StatusBarWidget();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status, IdeConfig::DEFAULT_SETTINGS);

        $controller->resetSetting('ghost');

        $this->assertStringContainsString('Unknown setting', $this->statusRow($status));
        $this->assertFalse(\is_file($project->path('.porta.json')), 'nothing was written');
    }

    /** @return array<string, mixed> */
    private function written(TempProject $project): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);
    }
}
