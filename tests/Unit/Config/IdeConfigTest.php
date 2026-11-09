<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\ConfigWarning;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\InlayHintMode;
use Porta\Editor\InlayParameterHints;
use Porta\Editor\LineNumberMode;
use Porta\I18n\Translator;
use Porta\Plugin\Store\PluginUpdates;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Tests\Support\TempProject;
use Porta\Ui\CursorStyle;
use Porta\Ui\Graphics\PixelRatio;
use Porta\Ui\Theme\Theme;
use Porta\Widget\Support\TabBarPosition;
use Symfony\Component\Tui\Style\CursorShape;

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
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'settings' => [
                    'autoPairs' => 0,
                    'lineNumbers' => 'relative',
                    'theme' => 'light',
                    'tabBarPosition' => 'left',
                    'bogusSetting' => 'x',
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertFalse($config->settings['autoPairs'], '0 coerced to bool');
        $this->assertSame('relative', $config->settings['lineNumbers']);
        $this->assertSame('light', $config->settings['theme']);
        $this->assertSame('left', $config->settings['tabBarPosition']);
        $this->assertTrue($config->settings['stickyScroll'], 'default preserved when unset');
        $this->assertContainsEquals(new ConfigWarning('Unknown setting "%key%" in %file%', ['%key%' => 'bogusSetting', '%file%' => '.porta.json']), $config->warnings);
    }

    public function testLegacySettingKeysAreSkippedWithoutWarning(): void
    {
        // "editorConfig" was the removed "Respect .editorconfig" toggle: stale
        // values in older configs load silently, unlike genuinely-unknown keys.
        // The eight `git*` keys joined it when git became a plugin — they are
        // `plugins.git.*` now, and that plugin adopts the old value once, so a
        // warning here would be wrong twice over.
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'settings' => ['editorConfig' => false, 'gitShowBranch' => false, 'gitPath' => '/usr/bin/git', 'bogusSetting' => 'x'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertArrayNotHasKey('editorConfig', $config->settings, 'the legacy key never reaches the settings');
        $this->assertArrayNotHasKey('gitShowBranch', $config->settings, 'nor does a key that moved to a plugin');
        $this->assertEquals(
            [new ConfigWarning('Unknown setting "%key%" in %file%', ['%key%' => 'bogusSetting', '%file%' => '.porta.json'])],
            $config->warnings,
            'no warning for a superseded key; unknown keys still warn',
        );
    }

    public function testLanguageTemplatesParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => [
                    'php' => [
                        'templates' => [
                            'dd' => 'dd($1);$0',
                            // The rejects sit BETWEEN the keepers on purpose: with
                            // them last, a loop that stopped at the first bad entry
                            // instead of skipping it produced the same map, and the
                            // difference — every template after the typo silently
                            // gone — was invisible.
                            'bad' => ['not', 'a', 'string'],
                            // An empty body is KEPT — it is how a built-in is switched off.
                            'fore' => '',
                            'alsobad' => 42,
                            'sf' => 'sprintf($1)$0',
                        ],
                    ],
                    'javascript' => ['templates' => 'not-an-object'],
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(
            ['dd' => 'dd($1);$0', 'fore' => '', 'sf' => 'sprintf($1)$0'],
            $config->languages['php']['templates'],
            'every valid entry survives, including the ones behind a rejected one',
        );
        $this->assertArrayNotHasKey('javascript', $config->languages, 'a non-object templates block leaves nothing behind');
    }

    public function testLanguagesParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => [
                    'php' => ['indentStyle' => 'tabs', 'indentSize' => 4, 'runCommand' => 'frankenphp php-cli'],
                    'javascript' => ['indentSize' => '2', 'indentStyle' => 'bogus'],
                    'css' => ['indentStyle' => 'spaces', 'runCommand' => ['php', '-dmemory_limit=1G']],
                    'klingon' => ['indentStyle' => 'tabs'],
                    'python' => 'not-an-object',
                    'yaml' => ['indentSize' => 0],
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['indentStyle' => 'tabs', 'indentSize' => 4, 'runCommand' => 'frankenphp php-cli'], $config->languages['php']);
        $this->assertSame(['indentSize' => 2], $config->languages['javascript'], 'string size coerced, bogus style dropped');
        $this->assertSame(['indentStyle' => 'spaces', 'runCommand' => 'php -dmemory_limit=1G'], $config->languages['css'], 'argv-form run command normalised to a string');
        $this->assertArrayNotHasKey('klingon', $config->languages, 'unknown language dropped');
        $this->assertArrayNotHasKey('python', $config->languages, 'non-object dropped');
        $this->assertArrayNotHasKey('yaml', $config->languages, 'invalid size (< 1) dropped');
        $this->assertContainsEquals(new ConfigWarning('Unknown language "%lang%" in %file%', ['%lang%' => 'klingon', '%file%' => '.porta.json']), $config->warnings);
        $this->assertContainsEquals(new ConfigWarning('Language "%lang%" must map to an object of indent settings', ['%lang%' => 'python']), $config->warnings);
    }

    public function testAutoIndentSizeIsPreserved(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['languages' => ['php' => ['indentStyle' => 'spaces', 'indentSize' => 'auto']]], \JSON_THROW_ON_ERROR)]);
        $this->assertSame(['indentStyle' => 'spaces', 'indentSize' => 'auto'], IdeConfig::load($project->dir)->languages['php']);
    }

    public function testPersistLanguageSettingMergesAndPreserves(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'lsp' => 'phpactor',
                'languages' => ['php' => ['indentStyle' => 'tabs']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageSetting(ConfigScope::Project, 'php', 'indentSize', 4));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageSetting(ConfigScope::Project, 'css', 'indentStyle', 'spaces'));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['indentStyle' => 'tabs', 'indentSize' => 4], $written['languages']['php'], 'merged into the existing entry');
        $this->assertSame(['indentStyle' => 'spaces'], $written['languages']['css'], 'new language added');
        $this->assertSame('phpactor', $written['lsp'], 'unrelated key preserved');

        $this->assertSame(4, IdeConfig::load($project->dir)->languages['php']['indentSize']);
    }

    /**
     * Templates have no settings row — they are hand-written in `.porta.json` —
     * so a settings-screen write that touched the same language must not carry
     * them off with it.
     */
    public function testPersistLanguageSettingLeavesAHandWrittenTemplatesBlockAlone(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => ['php' => ['templates' => ['dd' => 'dd($1);$0']]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageSetting(ConfigScope::Project, 'php', 'indentSize', 2));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['dd' => 'dd($1);$0'], $written['languages']['php']['templates'] ?? null);
        $this->assertSame(['dd' => 'dd($1);$0'], IdeConfig::load($project->dir)->languages['php']['templates'] ?? null);
    }

    public function testPersistLanguageSettingReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json'));
        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageSetting(ConfigScope::Project, 'php', 'indentStyle', 'tabs'));
    }

    public function testRelativeLineNumbersMigratesToTheLineNumbersMode(): void
    {
        $relative = new TempProject(['.porta.json' => \json_encode(['settings' => ['relativeLineNumbers' => true]], \JSON_THROW_ON_ERROR)]);
        $config = IdeConfig::load($relative->dir);
        $this->assertSame('relative', $config->settings['lineNumbers'], 'legacy true → relative');
        $this->assertSame([], $config->warnings, 'the legacy key is skipped, not warned');

        $absolute = new TempProject(['.porta.json' => \json_encode(['settings' => ['relativeLineNumbers' => false]], \JSON_THROW_ON_ERROR)]);
        $this->assertSame('absolute', IdeConfig::load($absolute->dir)->settings['lineNumbers'], 'legacy false → absolute');

        // An explicit lineNumbers wins over the legacy key.
        $both = new TempProject(['.porta.json' => \json_encode(['settings' => ['relativeLineNumbers' => true, 'lineNumbers' => 'off']], \JSON_THROW_ON_ERROR)]);
        $this->assertSame('off', IdeConfig::load($both->dir)->settings['lineNumbers'], 'explicit lineNumbers wins');
    }

    public function testInlineDiagnosticsMigratesToTheDiagnosticMessagesMode(): void
    {
        $on = new TempProject(['.porta.json' => \json_encode(['settings' => ['inlineDiagnostics' => true]], \JSON_THROW_ON_ERROR)]);
        $config = IdeConfig::load($on->dir);
        $this->assertSame('inline', $config->settings['diagnosticMessages'], 'legacy true → the message after the code');
        $this->assertArrayNotHasKey('inlineDiagnostics', $config->settings, 'the legacy key itself is not a setting any more');
        $this->assertSame([], $config->warnings, 'the legacy key is skipped, not warned');

        $off = new TempProject(['.porta.json' => \json_encode(['settings' => ['inlineDiagnostics' => false]], \JSON_THROW_ON_ERROR)]);
        $this->assertSame('none', IdeConfig::load($off->dir)->settings['diagnosticMessages'], 'legacy false → no message at all');

        $both = new TempProject(['.porta.json' => \json_encode(['settings' => ['inlineDiagnostics' => false, 'diagnosticMessages' => 'menu']], \JSON_THROW_ON_ERROR)]);
        $this->assertSame('menu', IdeConfig::load($both->dir)->settings['diagnosticMessages'], 'an explicit diagnosticMessages wins');
    }

    public function testWritingDiagnosticMessagesRetiresInlineDiagnostics(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['settings' => ['inlineDiagnostics' => false]], \JSON_THROW_ON_ERROR)]);
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'theme', 'light'));
        $this->assertFalse($this->writtenSettings($project)['inlineDiagnostics'], 'an unrelated write keeps the legacy value');

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'diagnosticMessages', 'menu'));
        $this->assertArrayNotHasKey('inlineDiagnostics', $this->writtenSettings($project), 'writing the successor retires it');
    }

    /** @return array<string, mixed> */
    private function writtenSettings(TempProject $project): array
    {
        /** @var array{settings: array<string, mixed>} $written */
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);

        return $written['settings'];
    }

    public function testPersistSettingMergesAndPreservesUnknownKeys(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'lsp' => 'phpactor',
                'keymap' => ['save' => ['f10']],
                'settings' => ['tabWidth' => 8],
                'customUnknownKey' => ['keep' => 'me'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'autoPairs', false));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertFalse($written['settings']['autoPairs'], 'new setting written');
        $this->assertSame(8, $written['settings']['tabWidth'], 'existing setting preserved');
        $this->assertSame('phpactor', $written['lsp'], 'lsp preserved');
        $this->assertSame(['keep' => 'me'], $written['customUnknownKey'], 'unknown key preserved');

        // And a fresh load reflects the change.
        $this->assertFalse(IdeConfig::load($project->dir)->settings['autoPairs']);
    }

    public function testPersistSettingDropsMigratedLegacyKeys(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'settings' => ['relativeLineNumbers' => true, 'editorConfig' => true, 'tabWidth' => 4],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'lineNumbers', 'relative'));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
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
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'settings' => ['gitSignCommits' => true, 'gitPath' => '/opt/git', 'relativeLineNumbers' => true],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'theme', 'light'));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('light', $written['settings']['theme']);
        $this->assertTrue($written['settings']['gitSignCommits'], 'a plugin-adopted key survives an unrelated write');
        $this->assertSame('/opt/git', $written['settings']['gitPath']);
        $this->assertTrue($written['settings']['relativeLineNumbers'], 'a superseded key survives until its successor is written');
    }

    public function testPersistSettingCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'autoPairs', false));

        $this->assertFalse(IdeConfig::load($project->dir)->settings['autoPairs']);
    }

    /**
     * Overwriting a corrupt config is deliberate: refusing would strand the
     * user, since the Settings view offers no way to repair their JSON.
     *
     * The file itself is set aside as `.porta.json.invalid` first, so the typo
     * costs a rename rather than everything configured — {@see
     * \Porta\Tests\Unit\Config\PortaJsonWriterTest::testAMalformedConfigIsMovedAsideRatherThanDestroyed()}.
     */
    public function testPersistSettingIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'autoPairs', false));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertFalse($written['settings']['autoPairs']);
    }

    public function testPersistSettingReturnsFalseWhenWriteFails(): void
    {
        // A directory where the config file should be makes the atomic write fail.
        $project = new TempProject();
        \mkdir($project->path('.porta.json'));

        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'autoPairs', false));
    }

    public function testPersistKeymapMergesAndPreservesOtherEntries(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'lsp' => 'phpactor',
                'keymap' => ['save' => ['f10']],
                'settings' => ['tabWidth' => 8],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistKeymap(ConfigScope::Project, 'duplicate_line', ['ctrl+shift+d']));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['ctrl+shift+d'], $written['keymap']['duplicate_line'], 'new binding written');
        $this->assertSame(['f10'], $written['keymap']['save'], 'existing binding preserved');
        $this->assertSame('phpactor', $written['lsp'], 'top-level key preserved');
        $this->assertSame(8, $written['settings']['tabWidth'], 'settings block preserved');

        // A fresh load applies the override.
        $this->assertSame(['ctrl+shift+d'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
    }

    public function testPersistKeymapOverwritesAnExistingActionAndReindexes(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['save' => ['f10']]], \JSON_THROW_ON_ERROR)]);

        // A non-zero-based list is stored contiguously.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistKeymap(ConfigScope::Project, 'save', [1 => 'ctrl+s']));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['ctrl+s'], $written['keymap']['save']);
    }

    public function testPersistKeymapCreatesFileWhenAbsent(): void
    {
        $project = new TempProject();
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistKeymap(ConfigScope::Project, 'duplicate_line', ['ctrl+shift+q']));

        $this->assertSame(['ctrl+shift+q'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
    }

    /**
     * Overwriting a corrupt config is deliberate: refusing would strand the
     * user, since the Settings view offers no way to repair their JSON.
     *
     * The file itself is set aside as `.porta.json.invalid` first, so the typo
     * costs a rename rather than everything configured — {@see
     * \Porta\Tests\Unit\Config\PortaJsonWriterTest::testAMalformedConfigIsMovedAsideRatherThanDestroyed()}.
     */
    public function testPersistKeymapIgnoresCorruptExistingFile(): void
    {
        $project = new TempProject(['.porta.json' => '{not json']);
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistKeymap(ConfigScope::Project, 'save', ['f10']));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['f10'], $written['keymap']['save']);
    }

    public function testPersistKeymapReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json'));

        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistKeymap(ConfigScope::Project, 'save', ['f10']));
    }

    public function testKeymapParsingAndValidation(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'keymap' => [
                    'duplicate_line' => ['ctrl+shift+d'],
                    'save' => 'f10',
                    'bogus_action' => ['x'],
                    'copy' => 42,
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['ctrl+shift+d'], $config->keymap['duplicate_line']);
        $this->assertSame(['f10'], $config->keymap['save'], 'string normalized to list');
        $this->assertArrayNotHasKey('copy', $config->keymap, 'non-list value rejected');
        $this->assertEquals([new ConfigWarning('Keymap action "%action%" in %file% must map to a key or list of keys', ['%action%' => 'copy', '%file%' => '.porta.json'])], $config->warnings);

        // Whether an id EXISTS is not asked here: this runs before the
        // container, so the catalog is still missing every plugin pane's
        // actions. KeybindingsFactory judges membership once plugins have
        // loaded (see KeybindingsFactoryTest).
        $this->assertSame(['x'], $config->keymap['bogus_action'], 'an unrecognized id is kept for the factory to judge');
    }

    public function testLspServersMapLoaded(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'lsp' => ['php' => 'phpactor language-server', 'typescript' => ['node', '/x/tsserver.js', '--stdio']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);
        $this->assertSame('phpactor language-server', $config->lspServers['php']);
        $this->assertSame('node /x/tsserver.js --stdio', $config->lspServers['typescript'], 'argv joined to a command string');
        $this->assertSame([], $config->warnings);
    }

    public function testLspSingleCommandFormIsNotShownPerLanguage(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'phpactor language-server'], \JSON_THROW_ON_ERROR)]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->lspServers, 'a catch-all command is not a per-language map');
        $this->assertSame([], $config->warnings, 'the historical single-command form is valid, not a warning');
    }

    public function testPersistLspServerMergesRemovesAndPrunes(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['php' => 'phpactor language-server']], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'typescript', 'typescript-language-server --stdio'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('phpactor language-server', $written['lsp']['php'], 'existing entry preserved');
        $this->assertSame('typescript-language-server --stdio', $written['lsp']['typescript']);

        // Clearing a language removes its key (reverts to auto-detect).
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'php', '  '));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('php', $written['lsp']);

        // Clearing the last entry drops the whole lsp block.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'typescript', ''));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('lsp', $written);
    }

    public function testPersistLspServerConvertsACatchAllStringToAMap(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'phpactor language-server'], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'python', 'pylsp'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('phpactor language-server', $written['lsp']['*'], 'the catch-all is kept as *');
        $this->assertSame('pylsp', $written['lsp']['python']);
    }

    public function testPersistLspServerConvertsACatchAllArgvListToAMap(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['node', '/x/ls.js', '--stdio']], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'php', 'phpactor'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('node /x/ls.js --stdio', $written['lsp']['*'], 'the argv catch-all is joined and kept as *');
        $this->assertSame('phpactor', $written['lsp']['php']);
    }

    public function testPersistLspServerReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json'));
        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLspServer(ConfigScope::Project, 'php', 'phpactor'));
    }

    public function testLanguageDebugBlockParsesAndDropsInvalidParts(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => [
                    'php' => ['debug' => ['adapter' => ['node', '/x/phpDebug.js'], 'launch' => ['runtimeExecutable' => 'php']]],
                    'python' => ['debug' => ['adapter' => 'python -m debugpy.adapter', 'launch' => ['a', 'list']]],
                    'javascript' => ['debug' => 'not an object'],
                    'typescript' => ['debug' => ['adapter' => 42]],
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['adapter' => 'node /x/phpDebug.js', 'launch' => ['runtimeExecutable' => 'php']], $config->languages['php']['debug'] ?? null, 'argv adapter normalized to a string');
        $this->assertSame(['adapter' => 'python -m debugpy.adapter'], $config->languages['python']['debug'] ?? null, 'a list launch value is dropped');
        $this->assertArrayNotHasKey('javascript', $config->languages, 'a non-object debug block contributes nothing');
        $this->assertArrayNotHasKey('typescript', $config->languages, 'a non-string adapter is dropped');
        $this->assertSame([], $config->warnings);
    }

    public function testPersistLanguageDebugMergesRemovesAndPrunes(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['languages' => ['php' => ['indentStyle' => 'spaces']]], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageDebug(ConfigScope::Project, 'php', 'adapter', 'node /x/phpDebug.js'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageDebug(ConfigScope::Project, 'php', 'launch', ['env' => ['X' => '1']]));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle'], 'sibling keys preserved');
        $this->assertSame('node /x/phpDebug.js', $written['languages']['php']['debug']['adapter']);
        $this->assertSame(['env' => ['X' => '1']], $written['languages']['php']['debug']['launch']);

        // Blank removes a key; removing the last key drops the debug block but keeps the language.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageDebug(ConfigScope::Project, 'php', 'launch', null));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageDebug(ConfigScope::Project, 'php', 'adapter', ''));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('debug', $written['languages']['php']);
        $this->assertSame('spaces', $written['languages']['php']['indentStyle']);
    }

    public function testPersistLanguageDebugReturnsFalseWhenWriteFails(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json'));
        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistLanguageDebug(ConfigScope::Project, 'php', 'adapter', 'x'));
    }

    public function testPluginsBlockParsesScalarsAndWarnsOnNonObjects(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'plugins' => [
                    'phpstan' => ['binary' => '/opt/phpstan', 'strict' => true, 'level' => 7, 'junk' => ['nested']],
                    'broken' => 'not-an-object',
                ],
                'disabledPlugins' => ['psalm', 42, ['nested']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['binary' => '/opt/phpstan', 'strict' => true, 'level' => 7], $config->plugins['phpstan'], 'scalars kept raw, non-scalars dropped');
        $this->assertArrayNotHasKey('broken', $config->plugins);
        $this->assertContainsEquals(new ConfigWarning('Plugin settings for "%plugin%" must be an object', ['%plugin%' => 'broken']), $config->warnings);
        $this->assertSame(['psalm', '42'], $config->disabledPlugins, 'scalar names kept, stringified');
    }

    public function testPersistPluginSettingAndEnabledMergePreserving(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['settings' => ['theme' => 'light']], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginSetting(ConfigScope::Project, 'phpstan', 'binary', '/opt/x'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginSetting(ConfigScope::Project, 'phpstan', 'strict', true));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginDisabled(ConfigScope::Project, 'psalm'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginDisabled(ConfigScope::Project, 'mago'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginEnabled(ConfigScope::Project, 'psalm'));

        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['binary' => '/opt/x', 'strict' => true], $written['plugins']['phpstan']);
        $this->assertSame('light', $written['settings']['theme'], 'unrelated keys preserved');
        $this->assertSame(['mago'], $written['disabledPlugins'], 're-enabling removes the entry');

        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginEnabled(ConfigScope::Project, 'mago'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('disabledPlugins', $written, 'an emptied list is dropped');
    }

    public function testEveryWellShapedKeymapEntrySurvivesLoading(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'keymap' => [
                    'demo:hello' => 'f8',
                    'still_unknown' => ['f9'],
                    'demo:bad_value' => 42,
                ],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $config = IdeConfig::load($project->dir);

        $this->assertSame(['f8'], $config->keymap['demo:hello'], 'a plugin command id binds if its plugin loads');
        $this->assertSame(['f9'], $config->keymap['still_unknown'], 'membership is the factory\'s question, not this class\'s');
        $this->assertArrayNotHasKey('demo:bad_value', $config->keymap, 'the value-shape check still applies');
        $this->assertContainsEquals(
            new ConfigWarning('Keymap action "%action%" in %file% must map to a key or list of keys', ['%action%' => 'demo:bad_value', '%file%' => '.porta.json']),
            $config->warnings,
        );
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
        $this->assertContainsEquals(new ConfigWarning('"highlight" in %file% must be an object', ['%file%' => '.porta.json']), $config->warnings);
    }

    /** A non-string or empty id is dropped with a warning, not silently kept. */
    public function testInvalidHighlighterIdsAreDroppedWithAWarning(): void
    {
        $project = new TempProject(['.porta.json' => '{"highlight":{"default":42,"languages":{"rust":7,"go":"","php":"chroma"}}}']);
        $config = IdeConfig::load($project->dir);

        $this->assertSame('', $config->highlight['default'], 'a non-string default is no preference');
        $this->assertSame(['php' => 'chroma'], $config->highlight['languages']);
        $this->assertCount(2, \array_filter($config->warnings, static fn (ConfigWarning $w): bool => \str_contains($w->key, 'must be a non-empty string')));
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
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'image-viewer'));
        $this->assertSame(['graph-viewer', 'todo', 'local-history', 'diff', 'git', 'ssh', 'ftp'], IdeConfig::load($project->dir)->enabledPlugins);

        // Disabling the last one must persist an explicit [] — an unset key would flip the default back on.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'graph-viewer'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'todo'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'local-history'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'diff'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'git'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'ssh'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledDisabled(ConfigScope::Project, 'ftp'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame([], $written['enabledPlugins'] ?? null, 'the empty list is explicit in the file');
        $this->assertSame([], IdeConfig::load($project->dir)->enabledPlugins);

        // Re-enabling appends to the explicit list.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistBundledEnabled(ConfigScope::Project, 'image-viewer'));
        $this->assertSame(['image-viewer'], IdeConfig::load($project->dir)->enabledPlugins);

        // Project/global disable lists keep their unset-when-empty behavior.
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginDisabled(ConfigScope::Project, 'demo'));
        $this->assertTrue(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistPluginEnabled(ConfigScope::Project, 'demo'));
        $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('disabledPlugins', $written, 'an emptied disabledPlugins key is dropped');
    }

    public function testPersistSettingReturnsFalseOnAnUnencodableValue(): void
    {
        // Invalid UTF-8 makes json_encode(\JSON_THROW_ON_ERROR) throw inside
        // write(); the widened catch degrades it to the IO-error false.
        $project = new TempProject();

        $this->assertFalse(new PortaJsonWriter(ConfigFiles::for($project->dir))->persistSetting(ConfigScope::Project, 'theme', "\xB1"));
        $this->assertFalse(\is_file($project->path('.porta.json')), 'nothing half-written');
    }

    public function testInvalidJsonWarningCarriesTheDecodersReason(): void
    {
        // PHP's decoder reports no position, but its reason ("Syntax error",
        // "Control character error", …) still beats a bare "not valid JSON".
        $project = new TempProject(['.porta.json' => '{not json']);

        [$warning] = IdeConfig::load($project->dir)->warnings;

        $this->assertSame('%file% is not valid JSON (%error%)', $warning->key);
        $this->assertStringContainsString('Syntax error', $warning->params['%error%']);
    }

    public function testAChoiceSettingRejectsAValueOutsideItsDomain(): void
    {
        // "bogus" used to flow uncorrected into $PORTA_THEME and resolve to
        // whatever fallback each consumer happened to apply, silently.
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'settings' => ['theme' => 'bogus', 'cursorShape' => 'wedge', 'lineNumbers' => 'roman'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame('dark', $config->settings['theme'], 'the default stands in for the rejected value');
        $this->assertSame('bar', $config->settings['cursorShape']);
        $this->assertSame('absolute', $config->settings['lineNumbers']);
        $this->assertContainsEquals(
            new ConfigWarning('Setting "%key%" in %file% must be one of %choices%, got "%value%"', [
                '%key%' => 'theme',
                '%choices%' => 'dark|light|high-contrast',
                '%value%' => 'bogus',
                '%file%' => '.porta.json',
            ]),
            $config->warnings,
        );
        $this->assertContainsEquals(
            new ConfigWarning('Setting "%key%" in %file% must be one of %choices%, got "%value%"', [
                '%key%' => 'cursorShape',
                '%choices%' => 'bar|block|underline',
                '%value%' => 'wedge',
                '%file%' => '.porta.json',
            ]),
            $config->warnings,
        );
        $this->assertContainsEquals(
            new ConfigWarning('Setting "%key%" in %file% must be one of %choices%, got "%value%"', [
                '%key%' => 'lineNumbers',
                '%choices%' => 'off|absolute|relative',
                '%value%' => 'roman',
                '%file%' => '.porta.json',
            ]),
            $config->warnings,
        );
    }

    public function testEveryChoiceDomainMatchesItsAuthority(): void
    {
        // CHOICE_SETTINGS mirrors vocabularies whose owners this parser cannot
        // import (plugins and the container do not exist at parse time); these
        // pins turn drift into a test failure instead of a rejected valid value.
        $this->assertSame(Theme::ids(), IdeConfig::CHOICE_SETTINGS['theme']);
        $this->assertSame(PixelRatio::CHOICES, IdeConfig::CHOICE_SETTINGS['pixelRatio']);
        $this->assertSame(\array_keys(ToolUpdates::CADENCES), IdeConfig::CHOICE_SETTINGS['toolUpdateCheck']);
        $this->assertSame(\array_keys(PluginUpdates::CADENCES), IdeConfig::CHOICE_SETTINGS['pluginUpdateCheck']);
        $this->assertSame(Translator::CHOICES, IdeConfig::CHOICE_SETTINGS['language']);
        $this->assertSame(
            \array_map(static fn (LineNumberMode $mode): string => $mode->value, LineNumberMode::cases()),
            IdeConfig::CHOICE_SETTINGS['lineNumbers'],
        );
        $this->assertSame(
            \array_map(static fn (DiagnosticMessageMode $mode): string => $mode->value, DiagnosticMessageMode::cases()),
            IdeConfig::CHOICE_SETTINGS['diagnosticMessages'],
        );
        $this->assertSame(
            \array_map(static fn (InlayHintMode $mode): string => $mode->value, InlayHintMode::cases()),
            IdeConfig::CHOICE_SETTINGS['inlayHints'],
        );
        $this->assertSame(
            \array_map(static fn (InlayParameterHints $which): string => $which->value, InlayParameterHints::cases()),
            IdeConfig::CHOICE_SETTINGS['inlayParameterHints'],
        );
        $this->assertSame(
            \array_map(static fn (TabBarPosition $position): string => $position->value, TabBarPosition::cases()),
            IdeConfig::CHOICE_SETTINGS['tabBarPosition'],
        );
        // graphRender's pin retired with the setting: it is the graph-viewer
        // plugin's `renderMode` now, registered one file away from its enum
        // (and PluginSettings' coerce() clamps any drift to the default).
        // cursorShape has no enum of setting ids; its authority is the mapper.
        // The three ids must reach three DISTINCT shapes (a dropped or repeated
        // id collapses the count), and anything else falls back to Bar.
        $shapes = \array_map(CursorStyle::shapeFromSetting(...), IdeConfig::CHOICE_SETTINGS['cursorShape']);
        $this->assertCount(3, \array_unique(\array_map(static fn (CursorShape $s): int => $s->value, $shapes)));
        $this->assertSame(CursorShape::Bar, CursorStyle::shapeFromSetting('anything-else'));
    }

    public function testABadKeymapActionDoesNotAbortTheRemainingActions(): void
    {
        // "must map to a key or list" for `bad` must skip only that one entry,
        // not stop parsing the rest of the keymap.
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'keymap' => ['bad' => 5, 'save' => ['ctrl+s']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['save' => ['ctrl+s']], $config->keymap);
        $this->assertCount(1, $config->warnings);
    }

    public function testKeymapKeysAreReindexedAndStringified(): void
    {
        // A JSON array with a filtered/sparse shape is not possible from
        // json_decode, but the values themselves are not always strings.
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'keymap' => ['save' => ['ctrl+s', 1]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['ctrl+s', '1'], $config->keymap['save'], 'every key is stringified');
        $this->assertSame([0, 1], \array_keys($config->keymap['save']), 'reindexed from 0');
    }

    public function testCmdIsTheSuperModifier(): void
    {
        // What a Mac user writes; the key parser only knows `super`.
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'keymap' => ['save' => ['cmd+s', 'shift+CMD+s', 'cmd', 'ctrl+cmdx']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $this->assertSame(['super+s', 'shift+super+s', 'cmd', 'ctrl+cmdx'], IdeConfig::load($project->dir)->keymap['save'], 'only a cmd MODIFIER is renamed');
    }

    public function testMalformedHighlightBlockWarnsAndFallsBackWithoutCrashing(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['highlight' => 'not-an-object'], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['default' => '', 'languages' => []], $config->highlight);
        $this->assertContainsEquals(new ConfigWarning('"highlight" in %file% must be an object', ['%file%' => '.porta.json']), $config->warnings);
    }

    public function testANumericHighlightLanguageKeyIsWarnedAsAString(): void
    {
        // json_decode turns a purely-numeric object key into an int array key;
        // the warning's %language% param must still carry it as a string.
        $project = new TempProject(['.porta.json' => \json_encode(['highlight' => ['languages' => ['5' => '']]], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $warning = $config->warnings[0];
        $this->assertSame('5', $warning->params['%language%']);
    }

    public function testAnInvalidHighlighterLanguageWarningNamesTheLanguage(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['highlight' => ['languages' => ['rust' => '']]], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertContainsEquals(
            new ConfigWarning('Highlighter for "%language%" in %file% must be a non-empty string', ['%language%' => 'rust', '%file%' => '.porta.json']),
            $config->warnings,
        );
    }

    public function testANumericPluginKeyIsWarnedAsAString(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['plugins' => ['5' => 'not-an-object']], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $warning = $config->warnings[0];
        $this->assertSame('5', $warning->params['%plugin%']);
    }

    public function testABadPluginBlockDoesNotAbortTheRemainingPlugins(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'plugins' => ['bad' => 'not-an-object', 'good' => ['setting' => 'value']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['good' => ['setting' => 'value']], $config->plugins);
    }

    public function testANonScalarPluginSettingValueDoesNotAbortTheRemainingKeys(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'plugins' => ['git' => ['bad' => ['nested' => 'array'], 'good' => 'value']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['good' => 'value'], $config->plugins['git']);
    }

    public function testAFloatPluginSettingValueIsStringified(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['plugins' => ['git' => ['ratio' => 3.14]]], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame('3.14', $config->plugins['git']['ratio']);
    }

    public function testMultiplePluginsAreAllRetained(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'plugins' => ['git' => ['a' => '1'], 'graph-viewer' => ['b' => '2']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['git' => ['a' => '1'], 'graph-viewer' => ['b' => '2']], $config->plugins);
    }

    public function testALspListFormFallsBackToNoPerLanguageServers(): void
    {
        // A single-command form (string or argv list) has no per-language
        // keys to read — it must not be misparsed as one.
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['intelephense', '--stdio']], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->lspServers);
    }

    public function testStringListDropsNonScalarsAndReindexes(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'disabledPlugins' => ['a', ['nested'], 'b'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(['a', 'b'], $config->disabledPlugins);
    }

    public function testCommandStringStringifiesEveryArgvElement(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => ['php' => ['runCommand' => ['php', '-v', 8]]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame('php -v 8', $config->languages['php']['runCommand']);
    }

    public function testANumericLanguageKeyIsRejectedAsUnknownRatherThanCrashing(): void
    {
        // LanguageCatalog::isKnown() is strictly typed `string`; a purely
        // numeric key (auto-converted to an int by json_decode) must be
        // stringified before reaching it, or the whole parse would throw.
        $project = new TempProject(['.porta.json' => \json_encode(['languages' => ['5' => ['indentStyle' => 'spaces']]], \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame([], $config->languages);
        $this->assertContainsEquals(new ConfigWarning('Unknown language "%lang%" in %file%', ['%lang%' => '5', '%file%' => '.porta.json']), $config->warnings);
    }

    public function testABadLanguageEntryDoesNotAbortTheRemainingLanguages(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => ['php' => 'not-an-object', 'python' => ['indentStyle' => 'spaces']],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertArrayNotHasKey('php', $config->languages);
        $this->assertSame(['indentStyle' => 'spaces'], $config->languages['python']);
    }

    public function testIndentSizeOfExactlyOneIsAccepted(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => ['php' => ['indentSize' => 1]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame(1, $config->languages['php']['indentSize']);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function indentSizeStringProvider(): iterable
    {
        yield 'plain digits' => ['2', 2];
        yield 'the floor itself' => ['0', 1];
        yield 'exactly one' => ['1', 1];
        yield 'leading non-digit' => ['x2', null];
        yield 'trailing non-digit' => ['2x', null];
    }

    #[DataProvider('indentSizeStringProvider')]
    public function testIndentSizeFromAStringValue(string $raw, ?int $expected): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(
            [
                'languages' => ['php' => ['indentSize' => $raw]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);

        $config = IdeConfig::load($project->dir);

        $this->assertSame($expected, $config->languages['php']['indentSize'] ?? null);
    }
}
