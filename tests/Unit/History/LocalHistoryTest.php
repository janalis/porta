<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\LocalHistory;
use Porta\Tests\Support\TempProject;

final class LocalHistoryTest extends TestCase
{
    /** A deterministic monotonic clock. */
    private function clock(): \Closure
    {
        $t = 1_000;

        return function () use (&$t): int {
            return $t += 1000;
        };
    }

    public function testSnapshotAndList(): void
    {
        $project = new TempProject(['a.php' => "v1\n"]);
        $history = new LocalHistory($project->dir, $this->clock());
        $path = $project->path('a.php');

        $this->assertSame(0, $history->snapshot($path, "v1\n"));
        $this->assertSame(0, $history->snapshot($path, "v2\n"));

        $list = $history->list($path);
        $this->assertCount(2, $list);
        $this->assertSame("v2\n", $history->read($list[0]['file']), 'newest first');
        $this->assertSame("v1\n", $history->read($list[1]['file']));
        $this->assertGreaterThan($list[1]['stamp'], $list[0]['stamp']);
    }

    public function testIdenticalContentIsNotDuplicated(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $history = new LocalHistory($project->dir, $this->clock());
        $path = $project->path('a.php');

        $history->snapshot($path, 'same');
        $history->snapshot($path, 'same'); // no-op
        $this->assertCount(1, $history->list($path));
    }

    public function testPruneKeepsOnlyTheCap(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $history = new LocalHistory($project->dir, $this->clock(), maxPerFile: 3);
        $path = $project->path('a.php');

        for ($i = 0; $i < 6; ++$i) {
            $pruned = $history->snapshot($path, "content {$i}");
        }
        $this->assertSame(1, $pruned, 'each save past the cap prunes one');
        $list = $history->list($path);
        $this->assertCount(3, $list);
        // The three most recent survive.
        $this->assertSame('content 5', $history->read($list[0]['file']));
        $this->assertSame('content 3', $history->read($list[2]['file']));
    }

    public function testListForUnknownFileIsEmpty(): void
    {
        $project = new TempProject();
        $history = new LocalHistory($project->dir);
        $this->assertSame([], $history->list($project->path('never.php')));
    }

    public function testReadMissingSnapshotReturnsNull(): void
    {
        $project = new TempProject();
        $history = new LocalHistory($project->dir);
        $this->assertNull($history->read($project->path('.porta/history/x/1.snap')));
    }

    public function testSnapshotFailsWhenDirCannotBeCreated(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        // Make the history base path a file so mkdir underneath fails.
        @mkdir($project->path('.porta'), 0777, true);
        file_put_contents($project->path('.porta/history'), 'not a dir');

        $history = new LocalHistory($project->dir, $this->clock());
        $this->assertSame(-1, $history->snapshot($project->path('a.php'), 'x'));
    }

    public function testSnapshotFailsWhenFileCannotBeWritten(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $history = new LocalHistory($project->dir, $this->clock());
        $path = $project->path('a.php');

        // First snapshot creates the per-file directory; make it read-only so
        // the next write fails.
        $history->snapshot($path, 'first');
        $reflection = new \ReflectionMethod($history, 'dirFor');
        $dir = $reflection->invoke($history, $path);
        chmod($dir, 0555);

        $this->assertSame(-1, $history->snapshot($path, 'second'));

        chmod($dir, 0755); // restore so TempProject cleanup succeeds
    }
}
