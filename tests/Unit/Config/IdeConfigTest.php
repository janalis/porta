<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
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
                'bogusSetting' => 'x',
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertFalse($config->settings['inlineDiagnostics'], '0 coerced to bool');
        $this->assertSame('relative', $config->settings['lineNumbers']);
        $this->assertSame('light', $config->settings['theme']);
        $this->assertSame('left', $config->settings['tabBarPosition']);
        $this->assertTrue($config->settings['autoPairs'], 'default preserved when unset');
        $this->assertContains('Unknown setting "bogusSetting" in .porta.json', $config->warnings);
    }

    public function testLegacySettingKeysAreSkippedWithoutWarning(): void
    {
        // "editorConfig" was the removed "Respect .editorconfig" toggle: stale
        // values in older configs load silently, unlike genuinely-unknown keys.
        // The eight `git*` keys joined it when git became a plugin — they are
        // `plugins.git.*` now, and that plugin adopts the old value once, so a
        // warning here would be wrong twice over.
        $project = new TempProject(['.porta.json' => json_encode([
            'settings' => ['editorConfig' => false, 'gitShowBranch' => false, 'gitPath' => '/usr/bin/git', 'bogusSetting' => 'x'],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertArrayNotHasKey('editorConfig', $config->settings, 'the legacy key never reaches the settings');
        $this->assertArrayNotHasKey('gitShowBranch', $config->settings, 'nor does a key that moved to a plugin');
        $this->assertSame(['Unknown setting "bogusSetting" in .porta.json'], $config->warnings, 'no warning for a superseded key; unknown keys still warn');
    }

    public function testLanguagesParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'languages' => [
                'php' => ['indentStyle' => 'tabs', 'indentSize' => 4, 'runCommand' => 'frankenphp php-cli'],
                'javascript' => ['indentSize' => '2', 'indentStyle' => 'bogus'],
                'css' => ['indentStyle' => 'spaces', 'runCommand' => ['php', '-dmemory_limit=1G']],
                'klingon' => ['indentStyle' => 'tabs'],
                'python' => 'not-an-object',
                'yaml' => ['indentSize' => 0],
            ],
        ])]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['indentStyle' => 'tabs', 'indentSize' => 4, 'runCommand' => 'frankenphp php-cli'], $config->languages['php']);
        $this->assertSame(['indentSize' => 2], $config->languages['javascript'], 'string size coerced, bogus style dropped');
        $this->assertSame(['indentStyle' => 'spaces', 'runCommand' => 'php -dmemory_limit=1G'], $config->languages['css'], 'argv-form run command normalised to a string');
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

        $this->assertTrue(new PortaJsonWriter()->persistLanguageSetting($project->dir, 'php', 'indentSize', 4));
        $this->assertTrue(new PortaJsonWriter()->persistLanguageSetting($project->dir, 'css', 'indentStyle', 'spaces'));

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
        $this->assertFalse(new PortaJsonWriter()->persistLanguageSetting($project->dir, 'php', 'indentStyle', 'tabs'));
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

        $this->assertTrue(new PortaJsonWriter()->persistSetting($project->dir, 'inlineDiagnostics', false));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertFalse($written['settings']['inlineDiagnostics'], 'new setting written');
        $this->assertSame(8, $written['settings']['tabWidth'], 'existing setting preserved');
        $this->assertSame('phpactor', $written['lsp'], 'lsp preserved');
        $this->assertSame(['keep' => 'me'], $written['customUnknownKey'], 'unknown key preserved');

        // And a fresh load reflects the change.
        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics']);
    }

    public function testPersistSettingDropsMigratedLegacyKeys(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'settings' => ['relativeLineNumbers' => true, 'editorConfig' => true, 'tabWidth' => 4],
        ])]);

        $this->assertTrue(new PortaJsonWriter()->persistSetting($project->dir, 'lineNumbers', 'relative'));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('relative', $written['settings']['lineNumbers'], 'the new key was written');
        $this->assertSame(4, $written['settings']['tabWidth'], 'a live setting is preserved');
        $this->assertArrayNotHasKey('relativeLineNumbers', $written['settings'], 'writing the successor retires the superseded key');
        $this->assertArrayNotHasKey('editorConfig', $written['settings'], 'the value-less obsolete key is stripped on any write');
    }

    public function testPersistSettingPreservesLegacyValuesNobodyHasAdoptedYet(): void
    {
        // The old behavior stripped EVERY IdeConfig::LEGACY_SETTINGS key on
        // ANY setting write. That destroyed the pre-move git* values the git
        // plugin's one-time adoption had not read yet (plugin disabled, or the
        // project simply not reopened under it), and dropped
        // relativeLineNumbers before lineNumbers ever recorded the migrated
        // preference — permanent data loss from toggling an unrelated setting.
        $project = new TempProject(['.porta.json' => json_encode([
            'settings' => ['gitSignCommits' => true, 'gitPath' => '/opt/git', 'relativeLineNumbers' => true],
        ])]);

        $this->assertTrue(new PortaJsonWriter()->persistSetting($project->dir, 'theme', 'light'));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('light', $written['settings']['theme']);
        $this->assertTrue($written['settings']['gitSignCommits'], 'a plugin-adopted key survives an unrelated write');
        $this->assertSame('/opt/git', $written['settings']['gitPath']);
        $this->assertTrue($written['settings']['relativeLineNumbers'], 'a superseded key survives until its successor is written');
    }

    public function testPersistSettingCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(new PortaJsonWriter()->persistSetting($project->dir, 'inlineDiagnostics', false));

        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics']);
    }

    public function testPersistSettingIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(new PortaJsonWriter()->persistSetting($project->dir, 'inlineDiagnostics', false));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertFalse($written['settings']['inlineDiagnostics']);
    }

    public function testPersistSettingReturnsFalseWhenWriteFails(): void
    {
        // A directory where the config file should be makes the atomic write fail.
        $project = new TempProject();
        mkdir($project->path('.porta.json'));

        $this->assertFalse(new PortaJsonWriter()->persistSetting($project->dir, 'inlineDiagnostics', false));
    }

    public function testPersistKeymapMergesAndPreservesOtherEntries(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => 'phpactor',
            'keymap' => ['save' => ['f10']],
            'settings' => ['tabWidth' => 8],
        ])]);

        $this->assertTrue(new PortaJsonWriter()->persistKeymap($project->dir, 'duplicate_line', ['ctrl+shift+d']));

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
        $this->assertTrue(new PortaJsonWriter()->persistKeymap($project->dir, 'save', [1 => 'ctrl+s']));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame(['ctrl+s'], $written['keymap']['save']);
    }

    public function testPersistKeymapCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(new PortaJsonWriter()->persistKeymap($project->dir, 'duplicate_line', ['ctrl+shift+q']));

        $this->assertSame(['ctrl+shift+q'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
    }

    public function testPersistKeymapIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(new PortaJsonWriter()->persistKeymap($project->dir, 'save', ['f10']));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame(['f10'], $written['keymap']['save']);
    }

    public function testPersistKeymapReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));

        $this->assertFalse(new PortaJsonWriter()->persistKeymap($project->dir, 'save', ['f10']));
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
        $this->assertArrayNotHasKey('copy', $config->keymap, 'non-list value rejected');
        $this->assertCount(1, $config->warnings);
        $this->assertStringContainsString('copy', $config->warnings[0]);

        // Whether an id EXISTS is not asked here: this runs before the
        // container, so the catalog is still missing every plugin pane's
        // actions. KeybindingsFactory judges membership once plugins have
        // loaded (see KeybindingsFactoryTest).
        $this->assertSame(['x'], $config->keymap['bogus_action'], 'an unrecognized id is kept for the factory to judge');
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

        $this->assertTrue(new PortaJsonWriter()->persistLspServer($project->dir, 'typescript', 'typescript-language-server --stdio'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('phpactor language-server', $written['lsp']['php'], 'existing entry preserved');
        $this->assertSame('typescript-language-server --stdio', $written['lsp']['typescript']);

        // Clearing a language removes its key (reverts to auto-detect).
        $this->assertTrue(new PortaJsonWriter()->persistLspServer($project->dir, 'php', '  '));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('php', $written['lsp']);

        // Clearing the last entry drops the whole lsp block.
        $this->assertTrue(new PortaJsonWriter()->persistLspServer($project->dir, 'typescript', ''));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('lsp', $written);
    }

    public function testPersistLspServerConvertsACatchAllStringToAMap(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);

        $this->assertTrue(new PortaJsonWriter()->persistLspServer($project->dir, 'python', 'pylsp'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('phpactor language-server', $written['lsp']['*'], 'the catch-all is kept as *');
        $this->assertSame('pylsp', $written['lsp']['python']);
    }

    public function testPersistLspServerConvertsACatchAllArgvListToAMap(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['node', '/x/ls.js', '--stdio']])]);

        $this->assertTrue(new PortaJsonWriter()->persistLspServer($project->dir, 'php', 'phpactor'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('node /x/ls.js --stdio', $written['lsp']['*'], 'the argv catch-all is joined and kept as *');
        $this->assertSame('phpactor', $written['lsp']['php']);
    }

    public function testPersistLspServerReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $this->assertFalse(new PortaJsonWriter()->persistLspServer($project->dir, 'php', 'phpactor'));
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

        $this->assertTrue(new PortaJsonWriter()->persistLanguageDebug($project->dir, 'php', 'adapter', 'node /x/phpDebug.js'));
        $this->assertTrue(new PortaJsonWriter()->persistLanguageDebug($project->dir, 'php', 'launch', ['env' => ['X' => '1']]));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle'], 'sibling keys preserved');
        $this->assertSame('node /x/phpDebug.js', $written['languages']['php']['debug']['adapter']);
        $this->assertSame(['env' => ['X' => '1']], $written['languages']['php']['debug']['launch']);

        // Blank removes a key; removing the last key drops the debug block but keeps the language.
        $this->assertTrue(new PortaJsonWriter()->persistLanguageDebug($project->dir, 'php', 'launch', null));
        $this->assertTrue(new PortaJsonWriter()->persistLanguageDebug($project->dir, 'php', 'adapter', ''));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('debug', $written['languages']['php']);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle']);
    }

    public function testPersistLanguageDebugReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $this->assertFalse(new PortaJsonWriter()->persistLanguageDebug($project->dir, 'php', 'adapter', 'x'));
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

        $this->assertTrue(new PortaJsonWriter()->persistPluginSetting($project->dir, 'phpstan', 'binary', '/opt/x'));
        $this->assertTrue(new PortaJsonWriter()->persistPluginSetting($project->dir, 'phpstan', 'strict', true));
        $this->assertTrue(new PortaJsonWriter()->persistPluginDisabled($project->dir, 'psalm'));
        $this->assertTrue(new PortaJsonWriter()->persistPluginDisabled($project->dir, 'mago'));
        $this->assertTrue(new PortaJsonWriter()->persistPluginEnabled($project->dir, 'psalm'));

        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame(['binary' => '/opt/x', 'strict' => true], $written['plugins']['phpstan']);
        $this->assertSame('light', $written['settings']['theme'], 'unrelated keys preserved');
        $this->assertSame(['mago'], $written['disabledPlugins'], 're-enabling removes the entry');

        $this->assertTrue(new PortaJsonWriter()->persistPluginEnabled($project->dir, 'mago'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('disabledPlugins', $written, 'an emptied list is dropped');
    }

    public function testEveryWellShapedKeymapEntrySurvivesLoading(): void
    {
        $project = new TempProject(['.porta.json' => json_encode([
            'keymap' => [
                'demo:hello' => 'f8',
                'still_unknown' => ['f9'],
                'demo:bad_value' => 42,
            ],
        ])]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['f8'], $config->keymap['demo:hello'], 'a plugin command id binds if its plugin loads');
        $this->assertSame(['f9'], $config->keymap['still_unknown'], 'membership is the factory\'s question, not this class\'s');
        $this->assertArrayNotHasKey('demo:bad_value', $config->keymap, 'the value-shape check still applies');
        $this->assertContains('Keymap action "demo:bad_value" must map to a key or list of keys', $config->warnings);
    }

    public function testHighlightBlockParsesDefaultAndPerLanguageOverrides(): void
    {
        $project = new TempProject(['.porta.json' => '{"highlight":{"default":"tempest","languages":{"rust":"chroma","jsonc":"chroma"}}}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame('tempest', $config->highlight['default']);
        $this->assertSame(['rust' => 'chroma', 'jsonc' => 'chroma'], $config->highlight['languages']);
        $this->assertSame([], $config->warnings);
    }

    public function testHighlightBlockDefaultsToEmptyWhenAbsent(): void
    {
        $project = new TempProject(['.porta.json' => '{}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['default' => '', 'languages' => []], $config->highlight);
    }

    public function testNonObjectHighlightBlockWarns(): void
    {
        $project = new TempProject(['.porta.json' => '{"highlight":"tempest"}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['default' => '', 'languages' => []], $config->highlight);
        $this->assertContains('"highlight" in .porta.json must be an object', $config->warnings);
    }

    /** A non-string or empty id is dropped with a warning, not silently kept. */
    public function testInvalidHighlighterIdsAreDroppedWithAWarning(): void
    {
        $project = new TempProject(['.porta.json' => '{"highlight":{"default":42,"languages":{"rust":7,"go":"","php":"chroma"}}}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame('', $config->highlight['default'], 'a non-string default is no preference');
        $this->assertSame(['php' => 'chroma'], $config->highlight['languages']);
        $this->assertCount(2, array_filter($config->warnings, static fn (string $w): bool => str_contains($w, 'must be a non-empty string')));
    }

    public function testNonObjectHighlightLanguagesIsIgnored(): void
    {
        $project = new TempProject(['.porta.json' => '{"highlight":{"languages":"nope"}}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->highlight['languages']);
    }

    public function testEnabledPluginsDefaultAppliesOnlyWhenTheKeyIsAbsent(): void
    {
        $none = new TempProject();
        $this->assertSame(IdeConfig::DEFAULT_ENABLED_PLUGINS, IdeConfig::load($none->dir)->enabledPlugins, 'no .porta.json: the viewer plugins ship on');

        $absent = new TempProject(['.porta.json' => '{"settings":{"theme":"dark"}}']);
        $this->assertSame(IdeConfig::DEFAULT_ENABLED_PLUGINS, IdeConfig::load($absent->dir)->enabledPlugins, 'no enabledPlugins key: the default applies');

        $explicit = new TempProject(['.porta.json' => '{"enabledPlugins":["tempest"]}']);
        $this->assertSame(['tempest'], IdeConfig::load($explicit->dir)->enabledPlugins, 'an explicit list replaces the default entirely');

        $empty = new TempProject(['.porta.json' => '{"enabledPlugins":[]}']);
        $this->assertSame([], IdeConfig::load($empty->dir)->enabledPlugins, 'an explicit empty list means none — not the default');
    }

    public function testBundledPersistenceMaterializesTheDefaultList(): void
    {
        // Disabling from the default state writes "the default minus that name".
        $project = new TempProject(['.porta.json' => '{}']);
        $this->assertTrue(new PortaJsonWriter()->persistBundledDisabled($project->dir, 'image-viewer'));
        $this->assertSame(['graph-viewer', 'todo', 'local-history', 'git'], IdeConfig::load($project->dir)->enabledPlugins);

        // Disabling the last one must persist an explicit [] — an unset key would flip the default back on.
        $this->assertTrue(new PortaJsonWriter()->persistBundledDisabled($project->dir, 'graph-viewer'));
        $this->assertTrue(new PortaJsonWriter()->persistBundledDisabled($project->dir, 'todo'));
        $this->assertTrue(new PortaJsonWriter()->persistBundledDisabled($project->dir, 'local-history'));
        $this->assertTrue(new PortaJsonWriter()->persistBundledDisabled($project->dir, 'git'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame([], $written['enabledPlugins'] ?? null, 'the empty list is explicit in the file');
        $this->assertSame([], IdeConfig::load($project->dir)->enabledPlugins);

        // Re-enabling appends to the explicit list.
        $this->assertTrue(new PortaJsonWriter()->persistBundledEnabled($project->dir, 'image-viewer'));
        $this->assertSame(['image-viewer'], IdeConfig::load($project->dir)->enabledPlugins);

        // Project/global disable lists keep their unset-when-empty behavior.
        $this->assertTrue(new PortaJsonWriter()->persistPluginDisabled($project->dir, 'demo'));
        $this->assertTrue(new PortaJsonWriter()->persistPluginEnabled($project->dir, 'demo'));
        $written = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('disabledPlugins', $written, 'an emptied disabledPlugins key is dropped');
    }

    public function testPersistSettingReturnsFalseOnAnUnencodableValue(): void
    {
        // Invalid UTF-8 makes json_encode(JSON_THROW_ON_ERROR) throw inside
        // write(); the widened catch degrades it to the IO-error false.
        $project = new TempProject();

        $this->assertFalse(new PortaJsonWriter()->persistSetting($project->dir, 'theme', "\xB1"));
        $this->assertFalse(is_file($project->path('.porta.json')), 'nothing half-written');
    }
}
