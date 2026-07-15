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
        $project->write('a.txt', "one changed\n"); // unstaged edit
        $project->write('new.txt', "fresh\n"); // untracked
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

    public function testDiffContextControlsTheSurroundingLines(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, 30)))."\n";
        $git = $this->repo($project, committed: ['a.txt' => $body]);
        $project->write('a.txt', str_replace('line 15', 'line 15 EDITED', $body));

        $default = $ten = null;
        $git->worktreeFileDiff($project->path('a.txt'), function (string $out) use (&$default): void {
            $default = $out;
        });
        $git->worktreeFileDiff(
            $project->path('a.txt'),
            function (string $out) use (&$ten): void {
                $ten = $out;
            },
            context: 10,
        );
        $this->await(static fn (): bool => null !== $default && null !== $ten);

        $this->assertStringContainsString(" line 12\n", $default, 'git default: three lines of context');
        $this->assertStringNotContainsString(" line 5\n", $default);
        $this->assertStringContainsString(" line 5\n", $ten, '-U10 reaches ten lines out');
        $this->assertStringNotContainsString(" line 1\n", $ten);

        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');
        $full = null;
        $git->cachedFileDiff(
            $project->path('a.txt'),
            function (string $out) use (&$full): void {
                $full = $out;
            },
            context: 999_999,
        );
        $this->await(static fn (): bool => null !== $full);
        $this->assertStringContainsString(" line 1\n", $full, 'the huge -U shows the whole file');
        $this->assertStringContainsString(" line 30\n", $full);
    }

    public function testIgnoreWhitespaceDropsIndentOnlyChanges(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "alpha\nbravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel\n"]);
        // One indent-only change (bravo) and one real change (hotel).
        $project->write('a.txt', "alpha\n  bravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel CHANGED\n");

        $plain = $ignoring = null;
        $git->worktreeFileDiff($project->path('a.txt'), function (string $out) use (&$plain): void {
            $plain = $out;
        });
        $git->worktreeFileDiff(
            $project->path('a.txt'),
            function (string $out) use (&$ignoring): void {
                $ignoring = $out;
            },
            ignoreWhitespace: true,
        );
        $this->await(static fn (): bool => null !== $plain && null !== $ignoring);

        $this->assertStringContainsString('+  bravo', $plain, 'the plain diff shows the indent change');
        $this->assertStringNotContainsString('bravo', $ignoring, '-w drops the whitespace-only hunk');
        $this->assertStringContainsString('+hotel CHANGED', $ignoring, 'real changes survive -w');

        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm ws 2>/dev/null');
        exec('git -C '.escapeshellarg($project->dir).' log -1 --format=%H 2>/dev/null', $sha);

        $cached = $show = null;
        $project->write('a.txt', "alpha\n    bravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel CHANGED\n");
        exec('git -C '.escapeshellarg($project->dir).' add a.txt 2>/dev/null');
        $git->cachedFileDiff(
            $project->path('a.txt'),
            function (string $out) use (&$cached): void {
                $cached = $out;
            },
            ignoreWhitespace: true,
        );
        $git->commitFileDiff(
            $sha[0],
            'a.txt',
            function (string $out) use (&$show): void {
                $show = $out;
            },
            ignoreWhitespace: true,
        );
        $this->await(static fn (): bool => null !== $cached && null !== $show);

        $this->assertStringNotContainsString('bravo', $cached, 'the cached -w diff hides the reindent');
        $this->assertStringNotContainsString('bravo', $show, "the commit's -w patch hides its indent hunk");
        $this->assertStringContainsString('+hotel CHANGED', $show);
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
        $git->commit(
            'second, reworded',
            function (bool $ok) use (&$amended): void {
                $amended = $ok;
            },
            amend: true,
        );
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
        $git->branches(function (array $branches) use (&$calls): void {
            $calls[] = ['branches', $branches];
        });
        $git->checkout('main', function (bool $ok, string $error) use (&$calls): void {
            $calls[] = ['checkout', $ok, $error];
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
            [
                ['status', [], ''],
                ['cached', ''],
                ['worktree', ''],
                ['untracked', ''],
                ['unstage', false],
                ['discard', false],
                ['apply', false],
                ['log', []],
                ['branches', []],
                ['checkout', false, 'git is not available'],
                ['files', []],
                ['show', ''],
                ['commit', false, ''],
                ['last', ''],
                ['undo', false],
            ],
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

    public function testBranchListSwitchAndCreate(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} branch feature 2>/dev/null");

        $results = [];
        $git->branches(function (array $branches) use (&$results): void {
            $results['list'] = $branches;
        });
        $this->await(function () use (&$results): bool {
            return isset($results['list']);
        });
        $names = array_column($results['list'], 'name');
        $this->assertContains('feature', $names);
        $current = array_values(array_filter($results['list'], static fn (array $b): bool => $b['current']));
        $this->assertCount(1, $current, 'exactly one branch is current');

        $git->checkout('feature', function (bool $ok, string $error) use (&$results): void {
            $results['checkout'] = $ok;
        });
        $this->await(function () use (&$results): bool {
            return isset($results['checkout']);
        });
        $this->assertTrue($results['checkout']);
        exec("git -C {$d} rev-parse --abbrev-ref HEAD", $head);
        $this->assertSame('feature', $head[0] ?? '');

        $git->createBranch('fresh', function (bool $ok, string $error) use (&$results): void {
            $results['create'] = $ok;
        });
        $this->await(function () use (&$results): bool {
            return isset($results['create']);
        });
        $this->assertTrue($results['create']);

        // Duplicate creation fails with git's message.
        $git->createBranch('feature', function (bool $ok, string $error) use (&$results): void {
            $results['dup'] = ['ok' => $ok, 'error' => $error];
        });
        $this->await(function () use (&$results): bool {
            return isset($results['dup']);
        });
        $this->assertFalse($results['dup']['ok']);
        $this->assertNotSame('', $results['dup']['error']);
    }

    public function testDirtyWorkingTreeCheckoutFailsWithGitsMessage(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} branch other 2>/dev/null");
        // Diverge the branches on the same file, then dirty the worktree so
        // the checkout must refuse.
        exec("git -C {$d} checkout -q other && printf 'theirs\\n' > ".escapeshellarg($project->path('a.txt'))." && git -C {$d} commit -aqm theirs && git -C {$d} checkout -q -");
        file_put_contents($project->path('a.txt'), "dirty\n");

        $result = null;
        $git->checkout('other', function (bool $ok, string $error) use (&$result): void {
            $result = ['ok' => $ok, 'error' => $error];
        });
        $this->await(function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error'], "git's refusal reaches the caller verbatim");
    }

    public function testDeleteBranchMergedAndUnmerged(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} branch merged 2>/dev/null");
        exec("git -C {$d} checkout -qb unmerged && printf 'diverge\\n' >> ".escapeshellarg($project->path('a.txt'))." && git -C {$d} commit -aqm diverge && git -C {$d} checkout -q - 2>/dev/null");

        $results = [];
        $git->deleteBranch('merged', function (bool $ok, string $error) use (&$results): void {
            $results['merged'] = $ok;
        });
        $this->await(function () use (&$results): bool {
            return isset($results['merged']);
        });
        $this->assertTrue($results['merged']);

        $git->deleteBranch('unmerged', function (bool $ok, string $error) use (&$results): void {
            $results['unmerged'] = ['ok' => $ok, 'error' => $error];
        });
        $this->await(function () use (&$results): bool {
            return isset($results['unmerged']);
        });
        $this->assertFalse($results['unmerged']['ok'], '-d refuses an unmerged branch');
        $this->assertNotSame('', $results['unmerged']['error'], "git's refusal (which names -D) reaches the caller");
    }

    public function testCheckoutTrackingCreatesALocalTrackingBranch(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $d = escapeshellarg($project->dir);
        // A local bare remote with a branch the local repo does not have.
        exec(
            "git -C {$d} checkout -qb feature && git -C {$d} checkout -q - && git init -q --bare "
            .escapeshellarg($project->path('origin.git'))
            ." && git -C {$d} remote add origin "
            .escapeshellarg($project->path('origin.git'))
            ." && git -C {$d} push -q origin feature && git -C {$d} branch -q -D feature && git -C {$d} fetch -q origin",
        );

        $result = null;
        $git->checkoutTracking('origin/feature', function (bool $ok, string $error) use (&$result): void {
            $result = ['ok' => $ok, 'error' => $error];
        });
        $this->await(function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result['ok'], $result['error']);
        exec("git -C {$d} rev-parse --abbrev-ref feature@{upstream} 2>/dev/null", $upstream);
        $this->assertSame('origin/feature', $upstream[0] ?? '', 'the new local branch tracks the remote');
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

    public function testLogSkipReturnsTheOlderPage(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} commit -q --allow-empty -m second 2>/dev/null");
        exec("git -C {$d} commit -q --allow-empty -m third 2>/dev/null");

        $pages = [];
        $git->log(2, function (array $commits) use (&$pages): void {
            $pages[0] = array_column($commits, 'subject');
        });
        $git->log(
            2,
            function (array $commits) use (&$pages): void {
                $pages[1] = array_column($commits, 'subject');
            },
            skip: 2,
        );

        $this->await(static fn (): bool => 2 === \count($pages));
        $this->assertSame(['third', 'second'], $pages[0]);
        $this->assertSame(['init'], $pages[1], '--skip lands on the older page');
    }
}
