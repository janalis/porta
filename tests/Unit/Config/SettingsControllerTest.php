<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
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

    /** @var list<bool> */
    private array $editorConfigChanges = [];

    /**
     * @param array<string, int|bool|string> $settings
     */
    private function controller(string $rootDir, CodeEditorWidget $editor, StatusBarWidget $status, array $settings = []): SettingsController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $this->languageChanges = [];
        $this->themeChanges = [];
        $this->editorConfigChanges = [];

        return new SettingsController(
            $settings + IdeConfig::DEFAULT_SETTINGS,
            $editor,
            new Notifier($status, $tui),
            $rootDir,
            new Translator('en'),
            function (string $locale): void {
                $this->languageChanges[] = $locale;
            },
            function (string $theme): void {
                $this->themeChanges[] = $theme;
            },
            function (bool $enabled): void {
                $this->editorConfigChanges[] = $enabled;
            },
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
            ['language', 'inlineDiagnostics', 'relativeLineNumbers', 'autoPairs', 'editorConfig', 'theme'],
            array_column($rows, 'key'),
            'language first; indent settings moved to the Languages section',
        );
        $this->assertSame(['en', 'fr', 'de', 'es', 'it', 'ja', 'he'], $byKey['language']->options);
        $this->assertSame(Translator::LOCALE_NAMES, $byKey['language']->optionLabels, 'the picker shows language names');
        $this->assertFalse($byKey['autoPairs']->value);
        $this->assertSame('bool', $byKey['autoPairs']->kind);
        $this->assertFalse($byKey['inlineDiagnostics']->restart, 'live toggles need no restart');
        $this->assertFalse($byKey['editorConfig']->restart, 'editorConfig applies live too now');
        $this->assertSame('light', $byKey['theme']->value);
        $this->assertSame(['dark', 'light'], $byKey['theme']->options);
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

    public function testChangingEditorConfigFiresTheHookAndPersists(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeSetting('editorConfig', false);

        $this->assertSame([false], $this->editorConfigChanges, 'the .editorconfig hook fired live');
        $this->assertFalse(IdeConfig::load($project->dir)->settings['editorConfig'], 'persisted');
        $this->assertStringNotContainsString('restart', $this->flashText($status), '.editorconfig applies live');
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
        $controller->changeSetting('editorConfig', false);

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
        $this->assertSame([true], $this->editorConfigChanges, 'the .editorconfig hook applies at boot too');
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
}
