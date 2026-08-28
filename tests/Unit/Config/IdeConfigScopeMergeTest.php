<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigWarning;
use Porta\Config\IdeConfig;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/**
 * The two config layers folded together: `<home>/.porta/config.json` under
 * `<root>/.porta.json`.
 *
 * Each block merges at a different granularity, and every one of those choices
 * is a decision that can be got wrong quietly — so each has a test naming what
 * would break.
 */
final class IdeConfigScopeMergeTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $home;

    /** Held so the destructor does not remove the dir mid-test. */
    private TempProject $project;

    /**
     * @param array<string, mixed> $global
     * @param array<string, mixed> $project
     */
    private function load(array $global, array $project): IdeConfig
    {
        $this->home = new TempProject(['.porta/config.json' => json_encode($global, \JSON_THROW_ON_ERROR)]);
        $this->project = new TempProject(['.porta.json' => json_encode($project, \JSON_THROW_ON_ERROR)]);
        putenv('PORTA_HOME='.$this->home->dir);

        return IdeConfig::load($this->project->dir);
    }

    /** Per key: your theme globally, your tab width per project. */
    public function testSettingsMergePerKeyWithTheProjectOnTop(): void
    {
        $config = $this->load(
            ['settings' => ['theme' => 'light', 'stickyScroll' => true]],
            ['settings' => ['stickyScroll' => false]],
        );

        $this->assertSame('light', $config->settings['theme'], 'the global key survives');
        $this->assertFalse($config->settings['stickyScroll'], 'the project key wins');
        $this->assertSame(IdeConfig::DEFAULT_SETTINGS['lineNumbers'], $config->settings['lineNumbers'], 'and untouched keys keep their default');
    }

    /**
     * Per ACTION, never per chord list. Merging two lists of keys for one
     * action produces a binding nobody asked for.
     */
    public function testKeymapMergesPerActionNotPerChordList(): void
    {
        $config = $this->load(
            ['keymap' => ['save' => ['ctrl+s'], 'quit' => ['ctrl+q']]],
            ['keymap' => ['save' => ['ctrl+w']]],
        );

        $this->assertSame(['ctrl+w'], $config->keymap['save'], 'the project replaces the chord list whole');
        $this->assertSame(['ctrl+q'], $config->keymap['quit'], 'and an action it did not mention is untouched');
    }

    /**
     * Both layers are saying "not this one". An override that silently
     * re-enables a plugin the user turned off globally is the opposite of what
     * either layer asked for.
     */
    public function testDisabledPluginsUnion(): void
    {
        $config = $this->load(
            ['disabledPlugins' => ['noisy']],
            ['disabledPlugins' => ['slow']],
        );

        $this->assertSame(['noisy', 'slow'], $config->disabledPlugins);
    }

    /**
     * The one rule that cannot be a union. `enabledPlugins` is an explicit
     * list that REPLACES — even when empty — and unioning would leave a
     * project no way to turn off what global turned on.
     */
    public function testEnabledPluginsTakeTheNearestPresentLayer(): void
    {
        $config = $this->load(
            ['enabledPlugins' => ['git', 'todo']],
            ['enabledPlugins' => ['git']],
        );

        $this->assertSame(['git'], $config->enabledPlugins, 'the project list replaces, it does not add to');
    }

    public function testAnEmptyProjectListStillReplacesTheGlobalOne(): void
    {
        $config = $this->load(
            ['enabledPlugins' => ['git', 'todo']],
            ['enabledPlugins' => []],
        );

        $this->assertSame([], $config->enabledPlugins, 'an explicit empty list is a decision, not an absence');
    }

    public function testTheGlobalListIsUsedWhenTheProjectNamesNone(): void
    {
        $config = $this->load(['enabledPlugins' => ['todo']], ['settings' => ['stickyScroll' => false]]);

        $this->assertSame(['todo'], $config->enabledPlugins);
    }

    public function testTheBundledDefaultSurvivesWhenNeitherLayerNamesAList(): void
    {
        $config = $this->load(['settings' => ['theme' => 'light']], ['settings' => ['stickyScroll' => false]]);

        $this->assertSame(IdeConfig::DEFAULT_ENABLED_PLUGINS, $config->enabledPlugins);
    }

    /**
     * Per language, then per field — but `debug.launch` replaces whole. Half a
     * project's launch config and half the global one is a configuration
     * nobody wrote and no adapter accepts.
     */
    public function testLanguagesMergePerFieldButALaunchConfigReplacesWhole(): void
    {
        $config = $this->load(
            [
                'languages' => [
                    'php' => [
                        'indentStyle' => 'tabs',
                        'indentSize' => 4,
                        'debug' => ['adapter' => 'xdebug', 'launch' => ['port' => 9003, 'host' => 'localhost']],
                    ],
                ],
            ],
            [
                'languages' => [
                    'php' => [
                        'indentSize' => 2,
                        'debug' => ['launch' => ['port' => 9999]],
                    ],
                ],
            ],
        );

        $php = $config->languages['php'];
        $this->assertSame('tabs', $php['indentStyle'], 'a field the project did not mention survives');
        $this->assertSame(2, $php['indentSize'], 'one it did wins');
        $this->assertSame('xdebug', $php['debug']['adapter'] ?? null, 'debug merges per field');
        $this->assertSame(['port' => 9999], $php['debug']['launch'] ?? null, 'but launch replaces whole — no leftover host');
    }

    public function testPluginSettingsMergePerPluginThenPerKey(): void
    {
        $config = $this->load(
            ['plugins' => ['git' => ['refreshInterval' => 5, 'gutterMarks' => true], 'todo' => ['tags' => 'TODO']]],
            ['plugins' => ['git' => ['refreshInterval' => 30]]],
        );

        $this->assertSame(['refreshInterval' => 30, 'gutterMarks' => true], $config->plugins['git']);
        $this->assertSame(['tags' => 'TODO'], $config->plugins['todo'], 'a plugin the project did not mention is untouched');
    }

    public function testHighlightMergesTheDefaultAndThePerGrammarMap(): void
    {
        $config = $this->load(
            ['highlight' => ['default' => 'tempest', 'languages' => ['rust' => 'chroma', 'php' => 'tempest']]],
            ['highlight' => ['languages' => ['php' => 'chroma']]],
        );

        $this->assertSame('tempest', $config->highlight['default'], 'the global default survives a project block that omits it');
        $this->assertSame(['rust' => 'chroma', 'php' => 'chroma'], $config->highlight['languages']);
    }

    /** A mistake in the global file must say so, not blame the project's. */
    public function testAWarningNamesTheFileItCameFrom(): void
    {
        $config = $this->load(
            ['settings' => ['bogusGlobalKey' => 1]],
            ['settings' => ['bogusProjectKey' => 1]],
        );

        $this->assertEqualsCanonicalizing(
            [
                new ConfigWarning('Unknown setting "%key%" in %file%', ['%key%' => 'bogusGlobalKey', '%file%' => '~/.porta/config.json']),
                new ConfigWarning('Unknown setting "%key%" in %file%', ['%key%' => 'bogusProjectKey', '%file%' => '.porta.json']),
            ],
            $config->warnings,
        );
    }

    /** No global file at all is the state every existing install is in. */
    public function testAProjectWithNoGlobalFileLoadsExactlyAsBefore(): void
    {
        $this->home = new TempProject([]);
        $this->project = new TempProject(['.porta.json' => json_encode(['settings' => ['cursorShape' => 'block']], \JSON_THROW_ON_ERROR)]);
        putenv('PORTA_HOME='.$this->home->dir);

        $config = IdeConfig::load($this->project->dir);

        $this->assertSame('block', $config->settings['cursorShape']);
        $this->assertSame([], $config->warnings);
        $this->assertSame(IdeConfig::DEFAULT_ENABLED_PLUGINS, $config->enabledPlugins);
    }

    /** And a global file with no project one is the state a new user is in. */
    public function testAGlobalFileAloneIsEnough(): void
    {
        $this->home = new TempProject(['.porta/config.json' => json_encode(['settings' => ['theme' => 'light']], \JSON_THROW_ON_ERROR)]);
        $this->project = new TempProject([]);
        putenv('PORTA_HOME='.$this->home->dir);

        $this->assertSame('light', IdeConfig::load($this->project->dir)->settings['theme']);
    }

    /** An unreadable global file must not take the project's config down with it. */
    public function testABrokenGlobalFileWarnsAndTheProjectStillLoads(): void
    {
        $this->home = new TempProject(['.porta/config.json' => '{not json']);
        $this->project = new TempProject(['.porta.json' => json_encode(['settings' => ['cursorShape' => 'block']], \JSON_THROW_ON_ERROR)]);
        putenv('PORTA_HOME='.$this->home->dir);

        $config = IdeConfig::load($this->project->dir);

        $this->assertSame('block', $config->settings['cursorShape'], 'the project layer is unaffected');
        $this->assertCount(1, $config->warnings);
        $this->assertSame('%file% is not valid JSON (%error%)', $config->warnings[0]->key);
        $this->assertSame('~/.porta/config.json', $config->warnings[0]->params['%file%'] ?? null, 'named as the global file');
    }
}
