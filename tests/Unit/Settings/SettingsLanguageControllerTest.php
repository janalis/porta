<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\I18n\Translator;
use Porta\Settings\SettingsLanguageController;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsLanguageControllerTest extends TestCase
{
    use StatusReads;

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, runCommand?: string}> $overrides
     */
    private function controller(string $rootDir, CodeEditorWidget $editor, StatusBarWidget $status, array $overrides = []): SettingsLanguageController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        return new SettingsLanguageController($editor, new Notifier($status, $tui), new Translator('en'), $overrides, new PortaJsonWriter(ConfigFiles::for($rootDir)));
    }

    public function testRowsReflectStoredOverridesAndDefaults(): void
    {
        $tempProject = new TempProject();
        $controller = $this->controller($tempProject->dir, new CodeEditorWidget(), new StatusBarWidget(), ['php' => [
            'indentStyle' => 'tabs',
            'indentSize' => 4,
            'runCommand' => 'frankenphp php-cli',
        ]]);

        $php = $controller->rows('php');
        $this->assertSame(['indentStyle', 'indentSize', 'runCommand'], array_column($php, 'key'));
        $this->assertSame('tabs', $php[0]->value);
        $this->assertSame(['auto', 'spaces', 'tabs'], $php[0]->options);
        $this->assertSame('4', $php[1]->value, 'size rendered as a string for the choice');
        $this->assertFalse($php[0]->restart, 'indent style applies live, no restart needed');
        $this->assertFalse($php[1]->restart, 'indent size applies live, no restart needed');
        $this->assertSame('frankenphp php-cli', $php[2]->value, 'the configured run command shows');
        $this->assertTrue($php[2]->restart, 'the run command applies on restart');

        $css = $controller->rows('css'); // unconfigured → auto/auto
        $this->assertSame('auto', $css[0]->value);
        $this->assertSame('auto', $css[1]->value);
    }

    public function testApplyToEditorPushesTheMap(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "\ttabbed\n");
        $tempProject = new TempProject();
        $controller = $this->controller($tempProject->dir, $editor, new StatusBarWidget(), ['php' => ['indentStyle' => 'spaces', 'indentSize' => 4]]);

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

    /**
     * IdeConfig::load() normalizes a numeric-string indentSize back to int on
     * read, masking whether the RAW persisted value was actually an int or a
     * string — so these check the on-disk JSON directly.
     */
    public function testIndentSizeIsStoredAsAJsonIntOnlyForTheIndentSizeKeyWithANumericNonAutoValue(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), new StatusBarWidget());

        $controller->changeLanguageSetting('php', 'indentSize', '4');
        $raw = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame(4, $raw['languages']['php']['indentSize'], 'a numeric, non-auto indentSize is stored as a JSON int');

        // The int-cast is gated on the key being indentSize specifically, not
        // merely on the value looking numeric.
        $controller->changeLanguageSetting('php', 'indentStyle', '4');
        $raw = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('4', $raw['languages']['php']['indentStyle'], 'a numeric-looking value for a non-indentSize key stays a string');

        // The false branch's own (string) cast: an already-non-string value
        // routed there must still come out as a genuine string.
        $controller->changeLanguageSetting('php', 'indentStyle', true);
        $raw = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('1', $raw['languages']['php']['indentStyle']);
    }

    public function testChangingTheRunCommandPersistsAndFlashesTheRestartNote(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new CodeEditorWidget(), $status = new StatusBarWidget());

        $controller->changeLanguageSetting('php', 'runCommand', 'frankenphp php-cli');
        $this->assertSame('frankenphp php-cli', IdeConfig::load($project->dir)->languages['php']['runCommand'] ?? null);
        $flash = $this->flashText($status);
        $this->assertStringContainsString('PHP run command: frankenphp php-cli', $flash);
        $this->assertStringContainsString('frankenphp php-cli · applies on restart', $flash, 'the separator sits between the command and the note, in that order');

        // Clearing it shows the bundled-php default and the row reflects empty.
        $controller->changeLanguageSetting('php', 'runCommand', '  ');
        $this->assertStringContainsString('PHP run command: bundled php', $this->flashText($status));
        $this->assertSame('', $controller->rows('php')[2]->value);
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
