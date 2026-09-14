<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Tests\Support\PreservesPortaHome;

final class ConfigFilesTest extends TestCase
{
    use PreservesPortaHome;

    public function testEachScopeHasItsOwnFile(): void
    {
        $files = new ConfigFiles('/project', '/home/dev');

        $this->assertSame('/project/.porta.json', $files->path(ConfigScope::Project));
        $this->assertSame('/home/dev/.porta/config.json', $files->path(ConfigScope::Global));
    }

    /**
     * The global file is a SIBLING of the global plugin dir, not `~/.porta.json`.
     * Someone running `porta ~` would otherwise have one file playing both
     * layers, where a project write and a global write are the same write.
     */
    public function testTheGlobalFileDoesNotCollideWithAProjectOpenedAtHome(): void
    {
        $files = new ConfigFiles('/home/dev', '/home/dev');

        $this->assertNotSame($files->path(ConfigScope::Project), $files->path(ConfigScope::Global));
    }

    /** No PORTA_HOME and no HOME: there is nowhere to put a user-level file. */
    public function testTheGlobalPathIsNullWithoutAHome(): void
    {
        $files = new ConfigFiles('/project');

        $this->assertNull($files->path(ConfigScope::Global));
        $this->assertSame('/project/.porta.json', $files->path(ConfigScope::Project), 'the project file is unaffected');
    }

    public function testForResolvesHomeFromTheEnvironment(): void
    {
        \putenv('PORTA_HOME=/tmp/porta-home-fixture');

        $this->assertSame(
            '/tmp/porta-home-fixture/.porta/config.json',
            ConfigFiles::for('/project')->path(ConfigScope::Global),
        );
    }

    public function testLabelsNameTheFileTheUserWouldOpen(): void
    {
        $files = new ConfigFiles('/project', '/home/dev');

        $this->assertSame('.porta.json', $files->label(ConfigScope::Project));
        $this->assertSame('~/.porta/config.json', $files->label(ConfigScope::Global));
    }

    /** Global first: it sets the baseline that project overrides. */
    public function testPrecedenceOrderPutsGlobalUnderProject(): void
    {
        $this->assertSame(
            [ConfigScope::Global, ConfigScope::Project],
            ConfigScope::inPrecedenceOrder(),
        );
    }
}
