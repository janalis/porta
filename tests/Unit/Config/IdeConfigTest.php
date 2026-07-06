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
                'tabWidth' => '2',
                'inlineDiagnostics' => 0,
                'relativeLineNumbers' => true,
                'theme' => 'light',
                'bogusSetting' => 'x',
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(2, $config->settings['tabWidth'], 'string coerced to int');
        $this->assertFalse($config->settings['inlineDiagnostics'], '0 coerced to bool');
        $this->assertTrue($config->settings['relativeLineNumbers']);
        $this->assertSame('light', $config->settings['theme']);
        $this->assertTrue($config->settings['autoPairs'], 'default preserved when unset');
        $this->assertContains('Unknown setting "bogusSetting" in .porta.json', $config->warnings);
    }

    public function testTabWidthClampedToAtLeastOne(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['settings' => ['tabWidth' => 0]])]);
        $this->assertSame(1, IdeConfig::load($project->dir)->settings['tabWidth']);
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
