<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Event\SettingsRowsStale;
use Porta\Settings\SettingsProvenance;
use Porta\Tests\Support\TempProject;

/**
 * Which file a shown value came from — the question nobody had to ask while
 * there was only one.
 */
final class SettingsProvenanceTest extends TestCase
{
    /** Held so the destructor does not remove the dir mid-test. */
    private TempProject $project;

    private TempProject $home;

    /**
     * @param array<string, mixed>|null $global
     * @param array<string, mixed>|null $project
     */
    private function provenance(?array $global, ?array $project): SettingsProvenance
    {
        $this->home = new TempProject(null === $global ? [] : ['.porta/config.json' => json_encode($global, \JSON_THROW_ON_ERROR)]);
        $this->project = new TempProject(null === $project ? [] : ['.porta.json' => json_encode($project, \JSON_THROW_ON_ERROR)]);

        return new SettingsProvenance(new ConfigFiles($this->project->dir, $this->home->dir));
    }

    public function testAValueNoFileSetsHasNoOrigin(): void
    {
        $provenance = $this->provenance(null, null);

        $this->assertNull($provenance->originOf(['settings', 'theme']), 'a default is not "from" anywhere');
        $this->assertFalse($provenance->shadowsGlobal(['settings', 'theme']));
    }

    public function testAValueOnlyTheGlobalFileSetsComesFromGlobal(): void
    {
        $provenance = $this->provenance(['settings' => ['theme' => 'light']], null);

        $this->assertSame(ConfigScope::Global, $provenance->originOf(['settings', 'theme']));
        $this->assertFalse($provenance->shadowsGlobal(['settings', 'theme']), 'nothing to shadow');
    }

    public function testAValueOnlyTheProjectFileSetsComesFromTheProject(): void
    {
        $provenance = $this->provenance(null, ['settings' => ['theme' => 'light']]);

        $this->assertSame(ConfigScope::Project, $provenance->originOf(['settings', 'theme']));
        $this->assertFalse($provenance->shadowsGlobal(['settings', 'theme']));
    }

    /**
     * The case the tag exists for: the screen shows the project's value while
     * a different one sits in the global file, and nothing on screen says so.
     */
    public function testAProjectValueOverAGlobalOneIsBothProjectAndShadowing(): void
    {
        $provenance = $this->provenance(
            ['settings' => ['theme' => 'light']],
            ['settings' => ['theme' => 'dark']],
        );

        $this->assertSame(ConfigScope::Project, $provenance->originOf(['settings', 'theme']));
        $this->assertTrue($provenance->shadowsGlobal(['settings', 'theme']));
    }

    /** Nested paths: a language's field, a plugin's setting, a grammar's backend. */
    public function testItWalksNestedPaths(): void
    {
        $provenance = $this->provenance(
            ['languages' => ['php' => ['indentStyle' => 'tabs']], 'plugins' => ['git' => ['path' => '/usr/bin/git']]],
            ['highlight' => ['languages' => ['rust' => 'chroma']]],
        );

        $this->assertSame(ConfigScope::Global, $provenance->originOf(['languages', 'php', 'indentStyle']));
        $this->assertSame(ConfigScope::Global, $provenance->originOf(['plugins', 'git', 'path']));
        $this->assertSame(ConfigScope::Project, $provenance->originOf(['highlight', 'languages', 'rust']));
        $this->assertNull($provenance->originOf(['languages', 'php', 'indentSize']), 'a sibling it does not set');
        $this->assertNull($provenance->originOf(['languages', 'rust', 'indentStyle']), 'a branch it does not have');
    }

    /**
     * A `false` is a value. Treating "set to false" as "not set" would drop the
     * tag from exactly the rows people turn off.
     */
    public function testAFalseValueCountsAsSet(): void
    {
        $provenance = $this->provenance(['settings' => ['stickyScroll' => false]], null);

        $this->assertSame(ConfigScope::Global, $provenance->originOf(['settings', 'stickyScroll']));
    }

    /**
     * A path may name a BLOCK rather than a leaf, and a block that exists is
     * set. `['languages', 'php']` asks "does this file configure PHP at all",
     * which is a different question from any single field and a legitimate one
     * to ask of a path-addressed API.
     */
    public function testAPathEndingOnABlockIsSet(): void
    {
        $provenance = $this->provenance(['languages' => ['php' => ['indentStyle' => 'tabs']]], null);

        $this->assertSame(ConfigScope::Global, $provenance->originOf(['languages', 'php']));
        $this->assertNull($provenance->originOf(['languages', 'rust']), 'a block it does not have');
    }

    /**
     * A path that runs into a scalar before its last segment means the file's
     * shape is not what the caller assumed. Neither answer is "this key is
     * set", and it must not throw.
     */
    public function testAPathThroughAScalarIsNotSet(): void
    {
        $provenance = $this->provenance(['settings' => 'not-an-object'], null);

        $this->assertNull($provenance->originOf(['settings', 'theme']));
    }

    /** The files are read once and dropped when the row set moves. */
    public function testTheFilesAreRereadAfterSettingsRowsStale(): void
    {
        $provenance = $this->provenance(null, null);
        $this->assertNull($provenance->originOf(['settings', 'theme']));

        file_put_contents(
            $this->project->path('.porta.json'),
            json_encode(['settings' => ['theme' => 'light']], \JSON_THROW_ON_ERROR),
        );
        $this->assertNull($provenance->originOf(['settings', 'theme']), 'still the memoised read');

        $provenance->onSettingsRowsStale(new SettingsRowsStale());

        $this->assertSame(ConfigScope::Project, $provenance->originOf(['settings', 'theme']));
    }
}
