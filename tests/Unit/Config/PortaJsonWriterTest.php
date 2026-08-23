<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Tests\Support\TempProject;

final class PortaJsonWriterTest extends TestCase
{
    public function testPersistPluginDisabledAppendsToAnExistingList(): void
    {
        // member: true (add) with an already-non-empty list: the new plugin
        // must land alongside the existing entries, not replace or nest them.
        $project = new TempProject(['.porta.json' => json_encode(['disabledPlugins' => ['already-off']])]);
        $writer = new PortaJsonWriter();

        $this->assertTrue($writer->persistPluginDisabled($project->dir, 'newly-off'));

        $this->assertSame(['already-off', 'newly-off'], IdeConfig::load($project->dir)->disabledPlugins);
    }

    public function testWrittenFileEndsWithATrailingNewline(): void
    {
        $project = new TempProject();
        $writer = new PortaJsonWriter();

        $this->assertTrue($writer->persistSetting($project->dir, 'theme', 'light'));

        $raw = (string) file_get_contents($project->path('.porta.json'));
        $this->assertStringEndsWith(\PHP_EOL, $raw);
    }
}
