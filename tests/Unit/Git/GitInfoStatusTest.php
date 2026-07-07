<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\ChangeEntry;
use Porta\Git\GitInfo;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

/**
 * Real-git integration tests for the changes-view plumbing (status, cached /
 * worktree / untracked diffs, unstage, discard). Skipped when git is absent.
 */
final class GitInfoStatusTest extends TestCase
{
    public function testStatusListsStagedUnstagedAndUntracked(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one changed\n");   // unstaged edit
        $project->write('new.txt', "fresh\n");       // untracked
        exec('git -C '.escapeshellarg($project->dir).' add new.txt 2>/dev/null'); // staged add

        $entries = null;
        $raw = '';
        $git->status(function (array $e, string $r) use (&$entries, &$raw): void {
            $entries = $e;
            $raw = $r;
        });
        $this->await(static fn (): bool => null !== $entries);

        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry->path] = $entry;
        }
        $this->assertSame('none', $byPath['a.txt']->stagedState());
        $this->assertSame('M', $byPath['a.txt']->displayStatus());
        $this->assertSame('full', $byPath['new.txt']->stagedState());
        $this->assertStringContainsString('a.txt', $raw, 'the raw porcelain output rides along');
    }

    public function testCachedAndWorktreeDiffsSplitTheChange(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\ntwo\n"]);
        $project->write('a.txt', "one staged\ntwo\n");
        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');
        $project->write('a.txt', "one staged\ntwo worktree\n"); // further unstaged edit

        $cached = $worktree = null;
        $git->cachedFileDiff($project->path('a.txt'), function (string $out) use (&$cached): void {
            $cached = $out;
        });
        $git->worktreeFileDiff($project->path('a.txt'), function (string $out) use (&$worktree): void {
            $worktree = $out;
        });
        $this->await(static fn (): bool => null !== $cached && null !== $worktree);

        $this->assertStringContainsString('+one staged', $cached);
        $this->assertStringNotContainsString('worktree', $cached);
        $this->assertStringContainsString('+two worktree', $worktree);
        $this->assertStringNotContainsString('+one staged', $worktree);
    }

    public function testUntrackedFileDiffShowsEverythingAsAdditions(): void
    {
        $git = $this->repo($project, committed: ['seed.txt' => "s\n"]);
        $project->write('brand-new.txt', "alpha\nbeta\n");

        $diff = null;
        $git->untrackedFileDiff($project->path('brand-new.txt'), function (string $out) use (&$diff): void {
            $diff = $out;
        });
        $this->await(static fn (): bool => null !== $diff);

        $this->assertStringContainsString('+alpha', $diff);
        $this->assertStringContainsString('+beta', $diff);
    }

    public function testUnstageAndDiscardRoundTrip(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one changed\n");
        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');

        $unstaged = null;
        $git->unstageFile($project->path('a.txt'), function (bool $ok) use (&$unstaged): void {
            $unstaged = $ok;
        });
        $this->await(static fn (): bool => null !== $unstaged);
        $this->assertTrue($unstaged);
        exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
        $this->assertSame(' M a.txt', $lines[0], 'the edit moved back to the worktree side');

        $discarded = null;
        $git->discardFile($project->path('a.txt'), function (bool $ok) use (&$discarded): void {
            $discarded = $ok;
        });
        $this->await(static fn (): bool => null !== $discarded);
        $this->assertTrue($discarded);
        $this->assertSame("one\n", file_get_contents($project->path('a.txt')), 'worktree restored to HEAD');
    }

    public function testAmendRewritesTheLastCommitAndPrefillReadsIt(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one more\n");
        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');

        $committed = null;
        $git->commit("second\n\nwith body", function (bool $ok) use (&$committed): void {
            $committed = $ok;
        });
        $this->await(static fn (): bool => null !== $committed);
        $this->assertTrue($committed);

        $message = null;
        $git->lastCommitMessage(function (string $m) use (&$message): void {
            $message = $m;
        });
        $this->await(static fn (): bool => null !== $message);
        $this->assertSame("second\n\nwith body", $message, 'the full message comes back for the amend prefill');

        $amended = null;
        $git->commit('second, reworded', function (bool $ok) use (&$amended): void {
            $amended = $ok;
        }, amend: true);
        $this->await(static fn (): bool => null !== $amended);
        $this->assertTrue($amended);
        exec('git -C '.escapeshellarg($project->dir).' log --format=%s 2>/dev/null', $subjects);
        $this->assertSame(['second, reworded', 'init'], $subjects, 'amend rewrote the tip without adding a commit');
    }

    public function testUndoLastCommitSoftResetsAndRefusesTheRootCommit(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "two\n");
        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm second 2>/dev/null');

        $undone = null;
        $git->undoLastCommit(function (bool $ok) use (&$undone): void {
            $undone = $ok;
        });
        $this->await(static fn (): bool => null !== $undone);
        $this->assertTrue($undone);
        exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
        $this->assertSame(['M  a.txt'], $lines, 'the undone commit\'s change is back and still staged');

        $refused = null;
        $git->undoLastCommit(function (bool $ok) use (&$refused): void {
            $refused = $ok;
        });
        $this->await(static fn (): bool => null !== $refused);
        $this->assertFalse($refused, 'the root commit has no parent to reset onto');
    }

    public function testEveryNewVerbShortCircuitsWithoutARepo(): void
    {
        $project = new TempProject(['a.txt' => "x\n"]);
        $git = new GitInfo($project->dir);
        $this->assertFalse($git->isAvailable());

        $calls = [];
        $git->status(function (array $e, string $r) use (&$calls): void {
            $calls[] = ['status', $e, $r];
        });
        $git->cachedFileDiff($project->path('a.txt'), function (string $out) use (&$calls): void {
            $calls[] = ['cached', $out];
        });
        $git->worktreeFileDiff($project->path('a.txt'), function (string $out) use (&$calls): void {
            $calls[] = ['worktree', $out];
        });
        $git->untrackedFileDiff($project->path('a.txt'), function (string $out) use (&$calls): void {
            $calls[] = ['untracked', $out];
        });
        $git->unstageFile($project->path('a.txt'), function (bool $ok) use (&$calls): void {
            $calls[] = ['unstage', $ok];
        });
        $git->discardFile($project->path('a.txt'), function (bool $ok) use (&$calls): void {
            $calls[] = ['discard', $ok];
        });
        $git->applyCached("diff --git a/x b/x\n", function (bool $ok) use (&$calls): void {
            $calls[] = ['apply', $ok];
        });
        $git->log(10, function (array $commits) use (&$calls): void {
            $calls[] = ['log', $commits];
        });
        $git->commitFiles('deadbeef', function (array $files) use (&$calls): void {
            $calls[] = ['files', $files];
        });
        $git->commitFileDiff('deadbeef', 'a.txt', function (string $raw) use (&$calls): void {
            $calls[] = ['show', $raw];
        });
        $git->commit('msg', function (bool $ok, string $out) use (&$calls): void {
            $calls[] = ['commit', $ok, $out];
        });
        $git->lastCommitMessage(function (string $m) use (&$calls): void {
            $calls[] = ['last', $m];
        });
        $git->undoLastCommit(function (bool $ok) use (&$calls): void {
            $calls[] = ['undo', $ok];
        });

        $this->assertSame(
            [['status', [], ''], ['cached', ''], ['worktree', ''], ['untracked', ''], ['unstage', false], ['discard', false], ['apply', false], ['log', []], ['files', []], ['show', ''], ['commit', false, ''], ['last', ''], ['undo', false]],
            $calls,
            'all resolve synchronously with empty/false results',
        );
    }

    /** A GitInfo on a fresh repo with $committed files committed; skips without git. */
    private function repo(?TempProject &$project, array $committed): GitInfo
    {
        if (null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $project = new TempProject($committed);
        exec('git -C '.escapeshellarg($project->dir).' init -q 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' add -A 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init 2>/dev/null');

        return new GitInfo($project->dir);
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
