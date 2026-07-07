<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsLanguageController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsLanguageControllerTest extends TestCase
{
    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string}> $overrides
     */
    private function controller(string $rootDir, CodeEditorWidget $editor, StatusBarWidget $status, array $overrides = []): SettingsLanguageController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        return new SettingsLanguageController($editor, $status, $tui, $rootDir, new Translator('en'), $overrides);
    }

    private function flashText(StatusBarWidget $status): string
    {
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);

        return (string) ($flash['text'] ?? '');
    }

    public function testRowsReflectStoredOverridesAndDefaults(): void
    {
        $controller = $this->controller(new TempProject()->dir, new CodeEditorWidget(), new StatusBarWidget(), ['php' => ['indentStyle' => 'tabs', 'indentSize' => 4]]);

        $php = $controller->rows('php');
        $this->assertSame(['indentStyle', 'indentSize'], array_column($php, 'key'));
        $this->assertSame('tabs', $php[0]['value']);
        $this->assertSame(['auto', 'spaces', 'tabs'], $php[0]['options']);
        $this->assertSame('4', $php[1]['value'], 'size rendered as a string for the choice');

        $css = $controller->rows('css'); // unconfigured → auto/auto
        $this->assertSame('auto', $css[0]['value']);
        $this->assertSame('auto', $css[1]['value']);
    }

    public function testApplyToEditorPushesTheMap(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "\ttabbed\n");
        $controller = $this->controller(new TempProject()->dir, $editor, new StatusBarWidget(), ['php' => ['indentStyle' => 'spaces', 'indentSize' => 4]]);

        $controller->applyToEditor();
        $this->assertFalse($editor->getDocument()->usesTabs());
        $this->assertSame(4, $editor->getDocument()->indentSize());
    }

    public function testChangingStyleAndSizePersistsAndAppliesLive(): void
    {
        $project = new TempProject();
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "code\n");
        $controller = $this->controller($project->dir, $editor, $status = new StatusBarWidget());

        $controller->changeLanguageSetting('php', 'indentStyle', 'tabs');
        $this->assertTrue($editor->getDocument()->usesTabs(), 'applied to the open .php document');
        $this->assertSame('tabs', IdeConfig::load($project->dir)->languages['php']['indentStyle']);
        $this->assertStringContainsString('PHP indent style: tabs', $this->flashText($status));

        $controller->changeLanguageSetting('php', 'indentSize', '4');
        $this->assertSame(4, $editor->getDocument()->indentSize());
        $this->assertSame(4, IdeConfig::load($project->dir)->languages['php']['indentSize'], 'numeric size stored as int');
        $this->assertStringContainsString('PHP indent size: 4', $this->flashText($status));
    }

    public function testAutoSizeIsStoredAsAString(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget(), ['php' => ['indentSize' => 4]]);

        $controller->changeLanguageSetting('php', 'indentSize', 'auto');
        $this->assertSame('auto', IdeConfig::load($project->dir)->languages['php']['indentSize']);
    }

    public function testUnknownLanguageOrKeyFlashes(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeLanguageSetting('klingon', 'indentStyle', 'tabs');
        $this->assertStringContainsString('Unknown language setting "klingon.indentStyle"', $this->flashText($status));
        $this->assertFalse(is_file($project->path('.porta.json')), 'nothing persisted');

        $controller->changeLanguageSetting('php', 'bogus', 'x');
        $this->assertStringContainsString('Unknown language setting "php.bogus"', $this->flashText($status));
    }

    public function testAFailedPersistIsFlagged(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeLanguageSetting('php', 'indentStyle', 'tabs');
        $this->assertStringContainsString('(not saved)', $this->flashText($status));
    }
}
