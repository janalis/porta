<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Install\PortaBinary;
use Porta\Tests\Support\TempProject;

final class PortaBinaryTest extends TestCase
{
    public function testOutsideAPharItIsThePackagesOwnBinPorta(): void
    {
        $this->assertSame(\realpath(\dirname(__DIR__, levels: 3).'/bin/porta'), PortaBinary::path());
        $this->assertSame('', PortaBinary::phar());
    }

    public function testRelaunchKeepsStableEntryAndInterpreterLinks(): void
    {
        $project = new TempProject(['Cellar/porta/v1/porta.phar' => 'phar', 'Cellar/php/v1/bin/php' => 'php']);
        \mkdir($project->path('bin'));
        \mkdir($project->path('opt'));
        \symlink($project->path('Cellar/porta/v1/porta.phar'), $project->path('bin/porta'));
        \symlink($project->path('Cellar/php/v1'), $project->path('opt/php'));
        $this->assertStringEndsWith('/bin/porta', PortaBinary::entry($project->path('bin/porta'), $project->path('Cellar/porta/v1/porta.phar')));
        $this->assertSame(PortaBinary::path(), PortaBinary::entry($project->path('missing'), PortaBinary::path()));
        $this->assertSame($project->path('opt/php/bin/php'), PortaBinary::interpreter($project->path('Cellar/php/v1/bin/php')));
        $project->write('wrapper.php', '<?php // an unrelated caller');
        $this->assertSame($project->path('Cellar/porta/v1/porta.phar'), PortaBinary::entry($project->path('wrapper.php'), $project->path('Cellar/porta/v1/porta.phar')));
        $project->write('Cellar/porta/v1/wrapper.php', '<?php // another file in the same keg');
        $this->assertSame($project->path('Cellar/porta/v1/porta.phar'), PortaBinary::entry($project->path('Cellar/porta/v1/wrapper.php'), $project->path('Cellar/porta/v1/porta.phar')));
        \unlink($project->path('opt/php'));
        $this->assertSame($project->path('Cellar/php/v1/bin/php'), PortaBinary::interpreter($project->path('Cellar/php/v1/bin/php')));
        $this->assertSame('/usr/bin/php', PortaBinary::interpreter('/usr/bin/php'));
    }
}
