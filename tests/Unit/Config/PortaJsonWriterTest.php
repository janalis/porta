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

    /**
     * A `.porta.json` the decoder cannot read is kept, not destroyed.
     *
     * `edit()` did `JsonFile::read($file) ?? []`, which cannot tell a MISSING
     * file from a malformed one — `null` is the answer to both. So a config with
     * a trailing comma, the commonest JSON typo there is, meant the next
     * settings change started from an empty array and wrote back a file holding
     * only the key just toggled. Theme, language, plugin list, LSP config,
     * per-language indents: gone, silently, from one click in Settings.
     *
     * Writing anyway is deliberate and stays — {@see
     * \Porta\Tests\Unit\Config\IdeConfigTest::testPersistSettingIgnoresCorruptExistingFile()}
     * has asserted it for a long time, and refusing would strand the user: the
     * Settings view offers no way to repair their JSON, so every change would
     * fail until they found a text editor. What was wrong is LOSING the file.
     */
    public function testAMalformedConfigIsMovedAsideRatherThanDestroyed(): void
    {
        $malformed = "{\n  \"settings\": {\n    \"theme\": \"light\",\n  }\n}\n";
        $project = new TempProject(['.porta.json' => $malformed]);
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'autoPairs', false), 'the setting still saves');
        $this->assertSame(
            $malformed,
            file_get_contents($project->path('.porta.json.invalid')),
            'and the unreadable file is set aside byte-for-byte',
        );
        $fresh = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertFalse($fresh['settings']['autoPairs'], 'the new file holds the change');
    }

    /** A readable file is edited in place — nothing is set aside. */
    public function testAReadableConfigIsNotMovedAside(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['settings' => ['theme' => 'light']])]);
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'autoPairs', false));

        $this->assertFileDoesNotExist($project->path('.porta.json.invalid'));
        $config = IdeConfig::load($project->dir);
        $this->assertSame('light', $config->settings['theme'], 'the existing setting survived the edit');
    }

    /** A file that is simply ABSENT is still created, which is the other half. */
    public function testAnAbsentConfigIsStillCreated(): void
    {
        $project = new TempProject();
        $writer = new PortaJsonWriter(ConfigFiles::for($project->dir));

        $this->assertTrue($writer->persistSetting(ConfigScope::Project, 'theme', 'light'));
        $this->assertFileExists($project->path('.porta.json'));
    }
}
