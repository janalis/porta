<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FileLock;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

final class FileLockTest extends TestCase
{
    public function testLeaseExcludesOtherInstancesAndProcessesUntilReleased(): void
    {
        $project = new TempProject();
        $one = new FileLock($project->path('lease'));
        $two = new FileLock($project->path('lease'));
        $this->assertTrue($one->acquire());
        $this->assertFalse($one->acquire());
        $this->assertFalse($two->acquire());
        $child = new Process([\PHP_BINARY, '-r', '$f=fopen($argv[1],"c"); exit(flock($f, LOCK_EX|LOCK_NB)?0:1);', $project->path('lease')]);
        $this->assertSame(1, $child->run());
        $one->release();
        $one->release();
        $this->assertTrue($two->acquire());
        $two->release();
        $this->assertSame(0, $child->run());
        $this->assertFalse(new FileLock($project->path('missing/lease'))->acquire());
        $this->assertFalse(new FileLock($project->dir)->acquire());
        $project->write('target', 'data');
        \symlink($project->path('target'), $project->path('link'));
        $this->assertFalse(new FileLock($project->path('link'))->acquire());
        $this->assertSame('data', \file_get_contents($project->path('target')));
    }
}
