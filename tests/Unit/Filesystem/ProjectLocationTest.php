<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Tests\Support\TempProject;

/** A project is a server's when its `.porta/` says so — and its cache is where Porta opens it. */
final class ProjectLocationTest extends TestCase
{
    public function testAProjectWithoutAMarkerIsThisMachines(): void
    {
        $project = new TempProject(['a.php' => '']);

        $this->assertFalse(ProjectLocation::forRoot($project->dir)->isRemote());
        $this->assertNull(ProjectLocation::forRoot($project->dir)->remote);
    }

    public function testAMarkerThatNamesNoServerIsIgnored(): void
    {
        $project = new TempProject([ProjectLocation::MARKER => '{"url": 42}']);
        $this->assertFalse(ProjectLocation::forRoot($project->dir)->isRemote());

        $project->write(ProjectLocation::MARKER, '{"url": "/not/remote"}');
        $this->assertFalse(ProjectLocation::forRoot($project->dir)->isRemote());
    }

    public function testPreparingMakesAMarkedCacheThatReadsBackAsTheServer(): void
    {
        $home = new TempProject();
        $spec = new RemoteSpec('ssh', 'example.org', '/srv/app', 'deploy');

        $root = ProjectLocation::prepare($spec, $home->dir);

        $this->assertSame(\realpath($home->path('.porta/remote/'.$spec->id().'/app')), $root);
        $this->assertEquals($spec, ProjectLocation::forRoot($root)->remote);
        $this->assertTrue(ProjectLocation::forRoot($root)->isRemote());
        $this->assertSame(0o700, \fileperms($root.'/.porta') & 0o777, 'what is kept here is this user\'s alone');
        $this->assertSame($root, ProjectLocation::prepare($spec, $home->dir), 'the same server project is the same cache, next time too');
    }
}
