<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Tests\Support\TempProject;

final class IdeConfigTest extends TestCase
{
    public function testMissingFileYieldsEmptyConfig(): void
    {
        $project = new TempProject();
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->keymap);
        $this->assertSame([], $config->warnings);
    }

    public function testInvalidJsonProducesWarning(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->keymap);
        $this->assertCount(1, $config->warnings);
    }

    public function testValidButNonObjectJsonProducesWarning(): void
    {
        // Valid JSON that is not an object (e.g. a bare string) is rejected.
        $project = new TempProject(['.porta.json' => '"just a string"']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->keymap);
        $this->assertCount(1, $config->warnings);
    }

    public function testDefaultSettingsWhenNoFile(): void
    {
        $project = new TempProject();
        $config = IdeConfig::load($project->dir);

        $this->assertSame(IdeConfig::DEFAULT_SETTINGS, $config->settings);
    }

    public function testSettingsParsingAndCoercion(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'settings' => [
                'inlineDiagnostics' => 0,
                'relativeLineNumbers' => true,
                'theme' => 'light',
                'bogusSetting' => 'x',
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertFalse($config->settings['inlineDiagnostics'], '0 coerced to bool');
        $this->assertTrue($config->settings['relativeLineNumbers']);
        $this->assertSame('light', $config->settings['theme']);
        $this->assertTrue($config->settings['autoPairs'], 'default preserved when unset');
        $this->assertContains('Unknown setting "bogusSetting" in .porta.json', $config->warnings);
    }

    public function testLanguagesParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'languages' => [
                'php' => ['indentStyle' => 'tabs', 'indentSize' => 4],
                'javascript' => ['indentSize' => '2', 'indentStyle' => 'bogus'],
                'css' => ['indentStyle' => 'spaces'],
                'klingon' => ['indentStyle' => 'tabs'],
                'python' => 'not-an-object',
                'yaml' => ['indentSize' => 0],
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['indentStyle' => 'tabs', 'indentSize' => 4], $config->languages['php']);
        $this->assertSame(['indentSize' => 2], $config->languages['javascript'], 'string size coerced, bogus style dropped');
        $this->assertSame(['indentStyle' => 'spaces'], $config->languages['css']);
        $this->assertArrayNotHasKey('klingon', $config->languages, 'unknown language dropped');
        $this->assertArrayNotHasKey('python', $config->languages, 'non-object dropped');
        $this->assertArrayNotHasKey('yaml', $config->languages, 'invalid size (< 1) dropped');
        $this->assertContains('Unknown language "klingon" in .porta.json', $config->warnings);
        $this->assertContains('Language "python" must map to an object of indent settings', $config->warnings);
    }

    public function testAutoIndentSizeIsPreserved(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['languages' => ['php' => ['indentStyle' => 'spaces', 'indentSize' => 'auto']]])]);
        $this->assertSame(['indentStyle' => 'spaces', 'indentSize' => 'auto'], IdeConfig::load($project->dir)->languages['php']);
    }

    public function testPersistLanguageSettingMergesAndPreserves(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor',
            'languages' => ['php' => ['indentStyle' => 'tabs']],
        ])]);

        $this->assertTrue(IdeConfig::persistLanguageSetting($project->dir, 'php', 'indentSize', 4));
        $this->assertTrue(IdeConfig::persistLanguageSetting($project->dir, 'css', 'indentStyle', 'spaces'));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertSame(['indentStyle' => 'tabs', 'indentSize' => 4], $written['languages']['php'], 'merged into the existing entry');
        $this->assertSame(['indentStyle' => 'spaces'], $written['languages']['css'], 'new language added');
        $this->assertSame('phpactor', $written['lsp'], 'unrelated key preserved');

        $this->assertSame(4, IdeConfig::load($project->dir)->languages['php']['indentSize']);
    }

    public function testPersistLanguageSettingReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $this->assertFalse(IdeConfig::persistLanguageSetting($project->dir, 'php', 'indentStyle', 'tabs'));
    }

    public function testPersistSettingMergesAndPreservesUnknownKeys(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor',
            'keymap' => ['save' => ['f10']],
            'settings' => ['tabWidth' => 8],
            'customUnknownKey' => ['keep' => 'me'],
        ])]);

        $this->assertTrue(IdeConfig::persistSetting($project->dir, 'relativeLineNumbers', true));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertTrue($written['settings']['relativeLineNumbers'], 'new setting written');
        $this->assertSame(8, $written['settings']['tabWidth'], 'existing setting preserved');
        $this->assertSame('phpactor', $written['lsp'], 'lsp preserved');
        $this->assertSame(['keep' => 'me'], $written['customUnknownKey'], 'unknown key preserved');

        // And a fresh load reflects the change.
        $this->assertTrue(IdeConfig::load($project->dir)->settings['relativeLineNumbers']);
    }

    public function testPersistSettingCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(IdeConfig::persistSetting($project->dir, 'inlineDiagnostics', false));

        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics']);
    }

    public function testPersistSettingIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(IdeConfig::persistSetting($project->dir, 'inlineDiagnostics', false));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertFalse($written['settings']['inlineDiagnostics']);
    }

    public function testPersistSettingReturnsFalseWhenWriteFails(): void
    {
        // A directory where the config file should be makes the atomic write fail.
        $project = new TempProject();
        mkdir($project->path('.porta.json'));

        $this->assertFalse(IdeConfig::persistSetting($project->dir, 'inlineDiagnostics', false));
    }

    public function testPersistKeymapMergesAndPreservesOtherEntries(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor',
            'keymap' => ['save' => ['f10']],
            'settings' => ['tabWidth' => 8],
        ])]);

        $this->assertTrue(IdeConfig::persistKeymap($project->dir, 'duplicate_line', ['ctrl+shift+d']));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertSame(['ctrl+shift+d'], $written['keymap']['duplicate_line'], 'new binding written');
        $this->assertSame(['f10'], $written['keymap']['save'], 'existing binding preserved');
        $this->assertSame('phpactor', $written['lsp'], 'top-level key preserved');
        $this->assertSame(8, $written['settings']['tabWidth'], 'settings block preserved');

        // A fresh load applies the override.
        $this->assertSame(['ctrl+shift+d'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
    }

    public function testPersistKeymapOverwritesAnExistingActionAndReindexes(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['keymap' => ['save' => ['f10']]])]);

        // A non-zero-based list is stored contiguously.
        $this->assertTrue(IdeConfig::persistKeymap($project->dir, 'save', [1 => 'ctrl+s']));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertSame(['ctrl+s'], $written['keymap']['save']);
    }

    public function testPersistKeymapCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(IdeConfig::persistKeymap($project->dir, 'duplicate_line', ['ctrl+shift+q']));

        $this->assertSame(['ctrl+shift+q'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
    }

    public function testPersistKeymapIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(IdeConfig::persistKeymap($project->dir, 'save', ['f10']));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), true);
        $this->assertSame(['f10'], $written['keymap']['save']);
    }

    public function testPersistKeymapReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));

        $this->assertFalse(IdeConfig::persistKeymap($project->dir, 'save', ['f10']));
    }

    public function testKeymapParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor language-server',
            'keymap' => [
                'duplicate_line' => ['ctrl+shift+d'],
                'save' => 'f10',
                'bogus_action' => ['x'],
                'copy' => 42,
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['ctrl+shift+d'], $config->keymap['duplicate_line']);
        $this->assertSame(['f10'], $config->keymap['save'], 'string normalized to list');
        $this->assertArrayNotHasKey('bogus_action', $config->keymap);
        $this->assertArrayNotHasKey('copy', $config->keymap, 'non-list value rejected');
        $this->assertCount(2, $config->warnings);
        $this->assertStringContainsString('bogus_action', $config->warnings[0]);
    }
}
