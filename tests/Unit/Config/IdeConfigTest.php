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
                'lineNumbers' => 'relative',
                'theme' => 'light',
                'tabBarPosition' => 'left',
                'gitShowBranch' => false,
                'gitRefreshInterval' => '5', // string coerced to int
                'gitPath' => '/usr/bin/git',
                'bogusSetting' => 'x',
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertFalse($config->settings['inlineDiagnostics'], '0 coerced to bool');
        $this->assertSame('relative', $config->settings['lineNumbers']);
        $this->assertSame('light', $config->settings['theme']);
        $this->assertSame('left', $config->settings['tabBarPosition']);
        $this->assertFalse($config->settings['gitShowBranch']);
        $this->assertSame(5, $config->settings['gitRefreshInterval'], 'string coerced to int');
        $this->assertSame('/usr/bin/git', $config->settings['gitPath']);
        $this->assertTrue($config->settings['gitGutterMarks'], 'git default preserved when unset');
        $this->assertTrue($config->settings['autoPairs'], 'default preserved when unset');
        $this->assertContains('Unknown setting "bogusSetting" in .porta.json', $config->warnings);
    }

    public function testLegacySettingKeysAreSkippedWithoutWarning(): void
    {
        // "editorConfig" was the removed "Respect .editorconfig" toggle: stale
        // values in older configs load silently, unlike genuinely-unknown keys.
        $project = new TempProject(['.porta.json' => json_encode([
            'settings' => ['editorConfig' => false, 'bogusSetting' => 'x'],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertArrayNotHasKey('editorConfig', $config->settings, 'the legacy key never reaches the settings');
        $this->assertSame(['Unknown setting "bogusSetting" in .porta.json'], $config->warnings, 'no warning for the legacy key; unknown keys still warn');
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

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
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

    public function testRelativeLineNumbersMigratesToTheLineNumbersMode(): void
    {
        $relative = new TempProject(['.porta.json' => json_encode(['settings' => ['relativeLineNumbers' => true]])]);
        $config = IdeConfig::load($relative->dir);
        $this->assertSame('relative', $config->settings['lineNumbers'], 'legacy true → relative');
        $this->assertSame([], $config->warnings, 'the legacy key is skipped, not warned');

        $absolute = new TempProject(['.porta.json' => json_encode(['settings' => ['relativeLineNumbers' => false]])]);
        $this->assertSame('absolute', IdeConfig::load($absolute->dir)->settings['lineNumbers'], 'legacy false → absolute');

        // An explicit lineNumbers wins over the legacy key.
        $both = new TempProject(['.porta.json' => json_encode(['settings' => ['relativeLineNumbers' => true, 'lineNumbers' => 'off']])]);
        $this->assertSame('off', IdeConfig::load($both->dir)->settings['lineNumbers'], 'explicit lineNumbers wins');
    }

    public function testPersistSettingMergesAndPreservesUnknownKeys(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor',
            'keymap' => ['save' => ['f10']],
            'settings' => ['tabWidth' => 8],
            'customUnknownKey' => ['keep' => 'me'],
        ])]);

        $this->assertTrue(IdeConfig::persistSetting($project->dir, 'inlineDiagnostics', false));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertFalse($written['settings']['inlineDiagnostics'], 'new setting written');
        $this->assertSame(8, $written['settings']['tabWidth'], 'existing setting preserved');
        $this->assertSame('phpactor', $written['lsp'], 'lsp preserved');
        $this->assertSame(['keep' => 'me'], $written['customUnknownKey'], 'unknown key preserved');

        // And a fresh load reflects the change.
        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics']);
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

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
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

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
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

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
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

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
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

    public function testLspServersMapLoaded(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => ['php' => 'phpactor language-server', 'typescript' => ['node', '/x/tsserver.js', '--stdio']],
        ])]);

        $config = IdeConfig::load($project->dir);
        $this->assertSame('phpactor language-server', $config->lspServers['php']);
        $this->assertSame('node /x/tsserver.js --stdio', $config->lspServers['typescript'], 'argv joined to a command string');
        $this->assertSame([], $config->warnings);
    }

    public function testLspSingleCommandFormIsNotShownPerLanguage(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->lspServers, 'a catch-all command is not a per-language map');
        $this->assertSame([], $config->warnings, 'the historical single-command form is valid, not a warning');
    }

    public function testPersistLspServerMergesRemovesAndPrunes(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['php' => 'phpactor language-server']])]);

        $this->assertTrue(IdeConfig::persistLspServer($project->dir, 'typescript', 'typescript-language-server --stdio'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('phpactor language-server', $written['lsp']['php'], 'existing entry preserved');
        $this->assertSame('typescript-language-server --stdio', $written['lsp']['typescript']);

        // Clearing a language removes its key (reverts to auto-detect).
        $this->assertTrue(IdeConfig::persistLspServer($project->dir, 'php', '  '));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('php', $written['lsp']);

        // Clearing the last entry drops the whole lsp block.
        $this->assertTrue(IdeConfig::persistLspServer($project->dir, 'typescript', ''));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('lsp', $written);
    }

    public function testPersistLspServerConvertsACatchAllStringToAMap(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);

        $this->assertTrue(IdeConfig::persistLspServer($project->dir, 'python', 'pylsp'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('phpactor language-server', $written['lsp']['*'], 'the catch-all is kept as *');
        $this->assertSame('pylsp', $written['lsp']['python']);
    }

    public function testPersistLspServerConvertsACatchAllArgvListToAMap(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['node', '/x/ls.js', '--stdio']])]);

        $this->assertTrue(IdeConfig::persistLspServer($project->dir, 'php', 'phpactor'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('node /x/ls.js --stdio', $written['lsp']['*'], 'the argv catch-all is joined and kept as *');
        $this->assertSame('phpactor', $written['lsp']['php']);
    }

    public function testPersistLspServerReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $this->assertFalse(IdeConfig::persistLspServer($project->dir, 'php', 'phpactor'));
    }

    public function testLanguageDebugBlockParsesAndDropsInvalidParts(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'languages' => [
                'php' => ['debug' => ['adapter' => ['node', '/x/phpDebug.js'], 'launch' => ['runtimeExecutable' => 'php']]],
                'python' => ['debug' => ['adapter' => 'python -m debugpy.adapter', 'launch' => ['a', 'list']]],
                'javascript' => ['debug' => 'not an object'],
                'typescript' => ['debug' => ['adapter' => 42]],
            ],
        ])]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['adapter' => 'node /x/phpDebug.js', 'launch' => ['runtimeExecutable' => 'php']], $config->languages['php']['debug'] ?? null, 'argv adapter normalized to a string');
        $this->assertSame(['adapter' => 'python -m debugpy.adapter'], $config->languages['python']['debug'] ?? null, 'a list launch value is dropped');
        $this->assertArrayNotHasKey('javascript', $config->languages, 'a non-object debug block contributes nothing');
        $this->assertArrayNotHasKey('typescript', $config->languages, 'a non-string adapter is dropped');
        $this->assertSame([], $config->warnings);
    }

    public function testPersistLanguageDebugMergesRemovesAndPrunes(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['languages' => ['php' => ['indentStyle' => 'spaces']]])]);

        $this->assertTrue(IdeConfig::persistLanguageDebug($project->dir, 'php', 'adapter', 'node /x/phpDebug.js'));
        $this->assertTrue(IdeConfig::persistLanguageDebug($project->dir, 'php', 'launch', ['env' => ['X' => '1']]));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle'], 'sibling keys preserved');
        $this->assertSame('node /x/phpDebug.js', $written['languages']['php']['debug']['adapter']);
        $this->assertSame(['env' => ['X' => '1']], $written['languages']['php']['debug']['launch']);

        // Blank removes a key; removing the last key drops the debug block but keeps the language.
        $this->assertTrue(IdeConfig::persistLanguageDebug($project->dir, 'php', 'launch', null));
        $this->assertTrue(IdeConfig::persistLanguageDebug($project->dir, 'php', 'adapter', ''));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('debug', $written['languages']['php']);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle']);
    }

    public function testPersistLanguageDebugReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $this->assertFalse(IdeConfig::persistLanguageDebug($project->dir, 'php', 'adapter', 'x'));
    }

    public function testPluginsBlockParsesScalarsAndWarnsOnNonObjects(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'plugins' => [
                'phpstan' => ['binary' => '/opt/phpstan', 'strict' => true, 'level' => 7, 'junk' => ['nested']],
                'broken' => 'not-an-object',
            ],
            'disabledPlugins' => ['psalm', 42, ['nested']],
        ])]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['binary' => '/opt/phpstan', 'strict' => true, 'level' => 7], $config->plugins['phpstan'], 'scalars kept raw, non-scalars dropped');
        $this->assertArrayNotHasKey('broken', $config->plugins);
        $this->assertContains('Plugin settings for "broken" must be an object', $config->warnings);
        $this->assertSame(['psalm', '42'], $config->disabledPlugins, 'scalar names kept, stringified');
    }

    public function testPersistPluginSettingAndEnabledMergePreserving(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['settings' => ['theme' => 'light']])]);

        $this->assertTrue(IdeConfig::persistPluginSetting($project->dir, 'phpstan', 'binary', '/opt/x'));
        $this->assertTrue(IdeConfig::persistPluginSetting($project->dir, 'phpstan', 'strict', true));
        $this->assertTrue(IdeConfig::persistPluginDisabled($project->dir, 'psalm'));
        $this->assertTrue(IdeConfig::persistPluginDisabled($project->dir, 'mago'));
        $this->assertTrue(IdeConfig::persistPluginEnabled($project->dir, 'psalm'));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame(['binary' => '/opt/x', 'strict' => true], $written['plugins']['phpstan']);
        $this->assertSame('light', $written['settings']['theme'], 'unrelated keys preserved');
        $this->assertSame(['mago'], $written['disabledPlugins'], 're-enabling removes the entry');

        $this->assertTrue(IdeConfig::persistPluginEnabled($project->dir, 'mago'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('disabledPlugins', $written, 'an emptied list is dropped');
    }

    public function testNamespacedPluginKeymapIdsBypassTheCatalogCheck(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'keymap' => [
                'demo:hello' => 'f8',
                'still_unknown' => ['f9'],
                'demo:bad_value' => 42,
            ],
        ])]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['f8'], $config->keymap['demo:hello'], 'plugin ids bind without catalog validation');
        $this->assertArrayNotHasKey('still_unknown', $config->keymap);
        $this->assertContains('Unknown keymap action "still_unknown" in .porta.json', $config->warnings);
        $this->assertArrayNotHasKey('demo:bad_value', $config->keymap, 'the value-shape check still applies');
        $this->assertContains('Keymap action "demo:bad_value" must map to a key or list of keys', $config->warnings);
    }
}
