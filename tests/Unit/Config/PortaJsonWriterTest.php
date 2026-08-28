<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Tests\Support\TempProject;

final class PortaJsonWriterTest extends TestCase
{
    /**
     * An environment with neither PORTA_HOME nor HOME has nowhere to put a
     * user-level file. The write reports failure rather than inventing a path
     * — which would be `/.porta/config.json`, at the filesystem root.
     *
     * Every caller already reads false as "the write failed" and flashes
     * accordingly, so nothing new has to learn about this case.
     */
    public function testAGlobalWriteFailsWhenTheEnvironmentNamesNoHome(): void
    {
        $project = new TempProject();
        $writer = new PortaJsonWriter(new ConfigFiles($project->dir));

        $this->assertFalse($writer->persistSetting(ConfigScope::Global, 'theme', 'light'));

        $this->assertTrue(
            $writer->persistSetting(ConfigScope::Project, 'theme', 'light'),
            'and the project scope is unaffected — it needs no home',
        );
    }

    public function testPersistPluginDisabledAppendsToAnExistingList(): void
    {
        // member: true (add) with an already-non-empty list: the new plugin
        // must land alongside the existing entries, not replace or nest them.
        $project = new TempProject(['.porta.json' => json_encode(['disabledPlugins' => ['already-off']])]);
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistPluginDisabled(ConfigScope::Project, 'newly-off'));

        $this->assertSame(['already-off', 'newly-off'], IdeConfig::load($project->dir)->disabledPlugins);
    }

    public function testWrittenFileEndsWithATrailingNewline(): void
    {
        $project = new TempProject();
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'theme', 'light'));

        $raw = (string) file_get_contents($project->path('.porta.json'));
        $this->assertStringEndsWith(\PHP_EOL, $raw);
    }
}
