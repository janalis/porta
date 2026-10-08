<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\TempProject;

final class PluginSettingsTest extends TestCase
{
    /**
     * A scope pinned to Project: the assertions below read the project's
     * .porta.json back, and SettingsScope otherwise defaults to Global — the
     * suite's shared fixture home.
     */
    private static function projectScope(string $rootDir): SettingsScope
    {
        $scope = new SettingsScope(ConfigFiles::for($rootDir));
        $scope->set(ConfigScope::Project);

        return $scope;
    }

    /** @return array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null} */
    private function descriptor(string $key, string $kind, bool|int|string $default, ?array $options = null): array
    {
        return ['key' => $key, 'label' => \ucfirst($key), 'kind' => $kind, 'default' => $default, 'options' => $options, 'hint' => null, 'min' => null, 'max' => null, 'step' => null];
    }

    public function testRowsCarryCoercedValuesAndDefaults(): void
    {
        $settings = new PluginSettings('/p', ['demo' => ['binary' => '/usr/bin/x', 'strict' => 1, 'level' => 'bogus']]);
        $settings->registerFor('demo', [
            $this->descriptor('binary', 'text', ''),
            $this->descriptor('strict', 'bool', false),
            $this->descriptor('level', 'choice', 'low', ['low', 'high']),
            $this->descriptor('jobs', 'int', 4),
        ]);

        $rows = $settings->rows('demo');
        $this->assertSame(['pluginSetting:binary', 'pluginSetting:strict', 'pluginSetting:level', 'pluginSetting:jobs'], \array_column($rows, 'key'));
        $this->assertSame('/usr/bin/x', $rows[0]->value);
        $this->assertTrue($rows[1]->value, 'the raw 1 coerced to bool');
        $this->assertSame('low', $rows[2]->value, 'an out-of-options choice falls back to the default');
        $this->assertSame(4, $rows[3]->value, 'unset values show the default');
        $this->assertSame(['low', 'high'], $rows[2]->options);

        $this->assertSame([], $settings->rows('unknown'), 'no descriptors → no rows');
        $this->assertSame('', $settings->value('demo', 'nope'), 'an unregistered key reads empty');
    }

    public function testChangeCoercesPersistsAndRemoveForDropsDescriptorsOnly(): void
    {
        $project = new TempProject();
        $settings = new PluginSettings($project->dir, [], null, self::projectScope($project->dir));
        $settings->registerFor('demo', [
            $this->descriptor('binary', 'text', ''),
            $this->descriptor('level', 'choice', 'low', ['low', 'high']),
            $this->descriptor('jobs', 'int', 4),
        ]);

        $settings->change('demo', 'binary', '/opt/tool');
        $settings->change('demo', 'level', 'nonsense'); // coerces to the default
        $settings->change('demo', 'jobs', '9'); // coerces to the declared int kind
        $settings->change('demo', 'ghost', 'x'); // unregistered → no-op

        $this->assertSame('/opt/tool', $settings->value('demo', 'binary'), 'the live map updated');
        $this->assertSame('low', $settings->value('demo', 'level'));
        $this->assertSame(9, $settings->value('demo', 'jobs'));
        $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('/opt/tool', $config['plugins']['demo']['binary'], 'persisted to .porta.json');
        $this->assertArrayNotHasKey('ghost', $config['plugins']['demo']);

        $settings->removeFor('demo');
        $this->assertSame([], $settings->rows('demo'), 'descriptors gone');
        $this->assertSame('/opt/tool', $settings->raw('demo', 'binary'), 'persisted values survive a removal');
    }

    public function testResetDropsTheLiveValueAndThePersistedOne(): void
    {
        $project = new TempProject(['.porta.json' => (string) \json_encode(['plugins' => ['demo' => ['binary' => '/usr/bin/x', 'strict' => true]]], \JSON_THROW_ON_ERROR)]);
        $settings = new PluginSettings($project->dir, ['demo' => ['binary' => '/usr/bin/x', 'strict' => true]], null, self::projectScope($project->dir));
        $settings->registerFor('demo', [$this->descriptor('binary', 'text', '/default/bin'), $this->descriptor('strict', 'bool', false)]);
        $this->assertSame('/usr/bin/x', $settings->value('demo', 'binary'));

        $settings->reset('demo', 'binary');

        $this->assertSame('/default/bin', $settings->value('demo', 'binary'), 'the live value falls back to the descriptor default');
        $file = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('binary', $file['plugins']['demo'], 'and the key is gone from disk, not set to the default');
        $this->assertArrayHasKey('strict', $file['plugins']['demo'], 'the plugin\'s other settings are untouched');
    }

    /** An emptied plugin entry — and then an emptied block — is removed rather than left as `{}`. */
    public function testResettingAPluginsLastSettingDropsItsEntryAndTheBlock(): void
    {
        $project = new TempProject(['.porta.json' => (string) \json_encode(['plugins' => ['demo' => ['binary' => '/usr/bin/x']]], \JSON_THROW_ON_ERROR)]);
        $settings = new PluginSettings($project->dir, ['demo' => ['binary' => '/usr/bin/x']], null, self::projectScope($project->dir));
        $settings->registerFor('demo', [$this->descriptor('binary', 'text', '')]);

        $settings->reset('demo', 'binary');

        $file = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('plugins', $file);
    }
}
