<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\GitInfo;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

final class GitInfoTest extends TestCase
{
    public function testParseModifiedAddedDeleted(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            @@ -2 +2 @@ context
            -old line 2
            +new line 2
            @@ -3,0 +4 @@ context
            +added line 4
            @@ -6,2 +7,0 @@ context
            -removed a
            -removed b
            DIFF;

        $marks = GitInfo::parseHunks($diff);

        $this->assertSame('modified', $marks[1]); // line 2 (0-based 1)
        $this->assertSame('added', $marks[3]); // line 4 (0-based 3)
        $this->assertSame('deleted', $marks[6]); // deletion survives on line 7
    }

    public function testParseMultiLineAdditionAndGrowthClassification(): void
    {
        // 1 old line replaced by 3 new: first is modified, extras are added.
        $marks = GitInfo::parseHunks("@@ -5 +5,3 @@\n-old\n+a\n+b\n+c\n");

        $this->assertSame('modified', $marks[4]);
        $this->assertSame('added', $marks[5]);
        $this->assertSame('added', $marks[6]);
    }

    public function testParsePureAdditionBlock(): void
    {
        $marks = GitInfo::parseHunks("@@ -0,0 +1,2 @@\n+first\n+second\n");

        $this->assertSame('added', $marks[0]);
        $this->assertSame('added', $marks[1]);
    }

    public function testParseIgnoresNonHunkLines(): void
    {
        $this->assertSame([], GitInfo::parseHunks("just some text\nno hunks here\n"));
    }

    public function testDisabledWhenNotAGitRepo(): void
    {
        $project = new TempProject(['a.txt' => "x\n"]);
        $git = new GitInfo($project->dir);

        $this->assertFalse($git->isAvailable());

        $branch = 'unset';
        $git->branch(function (?string $b) use (&$branch): void {
            $branch = $b;
        });
        $this->assertNull($branch, 'branch resolves synchronously to null when disabled');

        $marks = 'unset';
        $git->diff($project->path('a.txt'), function (array $m) use (&$marks): void {
            $marks = $m;
        });
        $this->assertSame([], $marks);

        // Write operations short-circuit too.
        $staged = 'unset';
        $git->stage($project->path('a.txt'), function (bool $ok) use (&$staged): void {
            $staged = $ok;
        });
        $this->assertFalse($staged);

        $committed = 'unset';
        $git->commit('msg', function (bool $ok) use (&$committed): void {
            $committed = $ok;
        });
        $this->assertFalse($committed);

        $blame = 'unset';
        $git->blameLine($project->path('a.txt'), 0, function (?array $b) use (&$blame): void {
            $blame = $b;
        });
        $this->assertNull($blame);
    }

    public function testParseBlameLine(): void
    {
        $porcelain = "0123456789abcdef0123456789abcdef01234567 1 1 1\nauthor Jane Dev\n"."author-mail <jane@example.com>\n"."summary Add the thing\n"."\tcode line\n";
        $this->assertSame(
            ['sha' => '01234567', 'author' => 'Jane Dev', 'summary' => 'Add the thing'],
            GitInfo::parseBlameLine($porcelain),
        );
        $this->assertNull(GitInfo::parseBlameLine(''), 'no blame output -> null');
    }

    public function testStageCommitAndBlameAgainstRealRepository(): void
    {
        if (null === self::findGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project->dir);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project->dir, 'a.txt');

        $git = new GitInfo($project->dir);

        // Stage a new change.
        $project->write('a.txt', "first\nsecond\n");
        $staged = null;
        $git->stage($project->path('a.txt'), function (bool $ok) use (&$staged): void {
            $staged = $ok;
        });
        $this->await(static fn () => null !== $staged);
        $this->assertTrue($staged, 'git add succeeded');

        // Commit it.
        $committed = null;
        $git->commit('add second line', function (bool $ok) use (&$committed): void {
            $committed = $ok;
        });
        $this->await(static fn () => null !== $committed);
        $this->assertTrue($committed, 'git commit succeeded');

        // Blame the committed second line.
        $blame = 'pending';
        $git->blameLine($project->path('a.txt'), 1, function (?array $b) use (&$blame): void {
            $blame = $b;
        });
        $this->await(static fn () => 'pending' !== $blame);
        $this->assertNotNull($blame);
        $this->assertSame('tester', $blame['author']);
        $this->assertSame('add second line', $blame['summary']);

        $git->cancelAll();
    }

    public function testAgainstARealRepository(): void
    {
        if (null === self::findGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project->dir);
        $project->write('a.txt', "line1\nline2\nline3\n");
        $this->gitCommit($project->dir, 'a.txt');

        // Modify line 2 and append a line
        $project->write('a.txt', "line1\nCHANGED\nline3\nADDED\n");

        $git = new GitInfo($project->dir);
        $this->assertTrue($git->isAvailable());

        $branch = 'pending';
        $git->branch(function (?string $b) use (&$branch): void {
            $branch = $b;
        });

        $marks = null;
        $git->diff($project->path('a.txt'), function (array $m) use (&$marks): void {
            $marks = $m;
        });

        $this->await(static fn () => 'pending' !== $branch && null !== $marks);

        $this->assertNotNull($branch, 'branch name resolved');
        $this->assertSame('modified', $marks[1] ?? null, 'line 2 modified');
        $this->assertSame('added', $marks[3] ?? null, 'line 4 added');

        $git->cancelAll();
    }

    public function testCancelAllTerminatesInFlightProcesses(): void
    {
        if (null === self::findGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project->dir);
        $project->write('a.txt', "x\n");
        $this->gitCommit($project->dir, 'a.txt');

        $git = new GitInfo($project->dir);

        // Start requests but cancel before the event loop drains them, so the
        // processes are still live when cancelAll() runs.
        $done = false;
        $git->branch(function () use (&$done): void {
            $done = true;
        });
        $git->diff($project->path('a.txt'), function () use (&$done): void {
            $done = true;
        });
        $git->cancelAll();

        // Give any (cancelled) watchers a chance to have fired.
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.2, static fn () => $suspension->resume());
        $suspension->suspend();

        $this->assertFalse($done, 'cancelled requests never invoke their callbacks');
    }

    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    private function gitInit(string $dir): void
    {
        exec('git -C '.escapeshellarg($dir).' init -q 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.email t@t 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.name tester 2>/dev/null');
    }

    private function gitCommit(string $dir, string $file): void
    {
        exec('git -C '.escapeshellarg($dir).' add '.escapeshellarg($file).' 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' commit -qm init 2>/dev/null');
    }

    private static function findGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }
}
