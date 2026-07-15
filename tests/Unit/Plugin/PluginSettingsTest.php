<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\PluginSettings;
use Porta\Tests\Support\TempProject;

final class PluginSettingsTest extends TestCase
{
    /** @return array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null} */
    private function descriptor(string $key, string $kind, bool|int|string $default, ?array $options = null): array
    {
        return ['key' => $key, 'label' => ucfirst($key), 'kind' => $kind, 'default' => $default, 'options' => $options, 'hint' => null];
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
        $this->assertSame(['pluginSetting:binary', 'pluginSetting:strict', 'pluginSetting:level', 'pluginSetting:jobs'], array_column($rows, 'key'));
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
        $settings = new PluginSettings($project->dir, []);
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
        $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertSame('/opt/tool', $config['plugins']['demo']['binary'], 'persisted to .porta.json');
        $this->assertArrayNotHasKey('ghost', $config['plugins']['demo']);

        $settings->removeFor('demo');
        $this->assertSame([], $settings->rows('demo'), 'descriptors gone');
        $this->assertSame('/opt/tool', $settings->raw('demo', 'binary'), 'persisted values survive a removal');
    }
}
