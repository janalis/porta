<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\DiagnosticLog;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Clock\MockClock;

final class DiagnosticLogTest extends TestCase
{
    /** Every entry carries one, so every test needs a clock it can predict. */
    private const string STAMP = '2026-09-09 08:07:00';

    private function log(string $path, int $maxBytes = DiagnosticLog::MAX_BYTES): DiagnosticLog
    {
        return new DiagnosticLog($path, $maxBytes, new MockClock(self::STAMP));
    }

    public function testForRootBuildsThePortaChannelPath(): void
    {
        $log = DiagnosticLog::forRoot('/tmp/proj', 'error');

        $this->assertSame('/tmp/proj/.porta/error.log', $log->path());
    }

    public function testAppendCreatesTheDirectoryAndAppendsLines(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log');

        $log->append('first');
        $log->append('second');

        $this->assertSame(self::STAMP." first\n".self::STAMP." second\n", \file_get_contents($log->path()));
    }

    /**
     * The reason the stamp exists at all.
     *
     * A repeating fault writes identical bytes every time, so an unstamped log
     * cannot say whether its entries are from this morning or from before the
     * fix that closed them — and the file's mtime dates only the newest. This
     * repo's `error.log` held twenty-one byte-identical traces and nothing that
     * placed any of them in time.
     */
    public function testALogThatIsASymlinkIsNotWrittenThrough(): void
    {
        $outside = new TempProject(['.bashrc' => "export A=1\n"]);
        $project = new TempProject(['.porta/keep' => '']);
        \symlink($outside->path('.bashrc'), $project->path('.porta/error.log'));

        DiagnosticLog::forRoot($project->dir, channel: 'error')->append('boom $(touch pwned)');

        $this->assertSame("export A=1\n", \file_get_contents($outside->path('.bashrc')));
    }

    public function testALogInAPortaFolderThatIsALinkIsNotWritten(): void
    {
        $outside = new TempProject(['error.log' => "export A=1\n"]);
        $project = new TempProject();
        \symlink($outside->dir, $project->path('.porta'));

        DiagnosticLog::forRoot($project->dir, channel: 'error')->append('boom $(touch pwned)');

        $this->assertSame("export A=1\n", \file_get_contents($outside->path('error.log')));
    }

    public function testEachEntryIsDated(): void
    {
        $project = new TempProject();
        $clock = new MockClock('2026-09-05 15:39:00');
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log', clock: $clock);

        $log->append('RenderException: the same crash');
        $clock->modify('+3 days');
        $log->append('RenderException: the same crash');

        $this->assertSame(
            "2026-09-05 15:39:00 RenderException: the same crash\n2026-09-08 15:39:00 RenderException: the same crash\n",
            \file_get_contents($log->path()),
            'two identical faults, told apart by nothing but their stamps',
        );
    }

    /**
     * A stack trace is ONE entry, however many lines it spans: stamping its
     * continuation lines would prefix the trace's own frames, and a reader
     * counting entries with `grep '^2'` would count frames instead.
     */
    public function testAMultiLineTraceIsStampedOnceOnItsFirstLine(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log');

        $log->append("RuntimeException: boom\n#0 /app/src/A.php(1)\n#1 {main}");

        $this->assertSame(
            self::STAMP." RuntimeException: boom\n#0 /app/src/A.php(1)\n#1 {main}\n",
            \file_get_contents($log->path()),
        );
    }

    /**
     * A cap too small to hold one whole entry keeps nothing.
     *
     * The degenerate end of the trimming rule, not the rule itself — {@see
     * testPastTheCapTheRecentEntriesSurvive()} is that. Here the recent half is
     * four bytes and contains no entry start, so there is no honest way to keep
     * any of it: the survivors have to begin with a stamp or a reader counting
     * entries would find the middle of a stack trace posing as one.
     */
    public function testACapTooSmallForAnEntryKeepsNothing(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log', maxBytes: 8);

        $log->append('0123456789'); // stamp + 11 bytes on disk — past the cap
        $log->append('fresh');

        $this->assertSame(self::STAMP." fresh\n", \file_get_contents($log->path()), 'nothing of the old file survived, because none of its tail was an entry');
    }

    /**
     * Past the cap, the RECENT half survives.
     *
     * The perverse case this closes: a crash storm is exactly what pushes this
     * file past its cap, so under the old rule the log was emptied at the
     * moment it had the most to say. The class docblock's own example — the
     * twenty-one identical traces that sat in this repo's `error.log` — is the
     * history that would have gone.
     */
    public function testPastTheCapTheRecentEntriesSurvive(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log', maxBytes: 120);

        // Drive it to the exact moment of overflow, so the next append is the
        // one that decides whether history survives. Anything less is timing:
        // straight after a reset the file legitimately holds one entry.
        $n = 0;
        do {
            $log->append('entry '.++$n);
        } while (\filesize($log->path()) <= 120 && $n < 100);
        $last = 'entry '.$n;

        $log->append('newest');

        $kept = (string) \file_get_contents($log->path());
        $this->assertStringEndsWith(self::STAMP." newest\n", $kept, 'the entry that crossed the cap is there, and LAST — a log whose newest line is in the middle is not a log');
        $this->assertStringContainsString($last, $kept, 'and so is the one before it — the file was trimmed, not emptied');
        $this->assertStringNotContainsString('entry 1'."\n", $kept, 'while the oldest was dropped to make room');
        $this->assertLessThanOrEqual(2 * 120, \strlen($kept), 'and the file stays bounded');
    }

    public function testAtTheCapItStillAppends(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log', maxBytes: 25);

        $log->append('0123'); // stamp + space + 4 + newline = exactly 25 bytes — at the cap, not past it
        $log->append('456');

        $this->assertSame(self::STAMP." 0123\n".self::STAMP." 456\n", \file_get_contents($log->path()));
    }

    /**
     * The size is COUNTED, not stat'd — which is the whole point of this round.
     *
     * Five of the 41 blocked-loop backtraces in the user's freeze.log are inside
     * append(), all of them reached from the freeze watchdog: the instrument
     * writing the stall line was blocked on the filesystem, making the stall it
     * had just measured longer, up to four times a second while the disk was
     * slow. Three of the four syscalls an append used to make were the size and
     * directory checks, and neither is needed twice.
     *
     * Observable as the consequence it buys: bytes another writer adds are not
     * seen, so the cap holds per writer. Pinned rather than hidden — that is
     * what the counter costs.
     */
    public function testTheSizeIsCountedRatherThanStattedAgainEachTime(): void
    {
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log', maxBytes: 60);
        $log->append('first');

        // Another writer pushes the file far past the cap between appends.
        \file_put_contents($log->path(), \str_repeat('x', times: 500), \FILE_APPEND);
        $log->append('second');

        $kept = (string) \file_get_contents($log->path());
        $this->assertStringContainsString('first', $kept, 'no trim happened: this writer has not written that much');
        $this->assertStringContainsString(self::STAMP.' second', $kept);
        $this->assertGreaterThan(500, \strlen($kept), 'and the other writer\'s bytes are all still there');
    }

    public function testAnExistingLogIsSizedOnceBeforeTheFirstAppend(): void
    {
        // The counter starts from what is already on disk, or a session that
        // opens a log left oversized by the last one would never trim it.
        $project = new TempProject();
        \mkdir($project->dir.'/.porta', permissions: 0o755, recursive: true);
        \file_put_contents($project->dir.'/.porta/diag.log', self::STAMP." old\n".\str_repeat('y', times: 200)."\n");
        $log = $this->log($project->dir.'/.porta/diag.log', maxBytes: 60);

        $log->append('fresh');

        $kept = (string) \file_get_contents($log->path());
        $this->assertStringContainsString('fresh', $kept);
        $this->assertLessThanOrEqual((2 * 60) + \strlen(self::STAMP.' fresh'), \strlen($kept), 'the inherited size was read and the file trimmed');
    }

    public function testAFailedWriteMakesTheNextAppendLookForTheDirectoryAgain(): void
    {
        // The price of asking once: a directory removed mid-session costs the
        // line that discovers it. What must not happen is costing every line
        // after that one too.
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log');
        $log->append('before');
        \exec('rm -rf '.\escapeshellarg($project->dir.'/.porta'));

        $log->append('lost with the directory');
        $this->assertFileDoesNotExist($log->path(), 'the write failed and the line went with it');

        $log->append('after');

        $kept = (string) \file_get_contents($log->path());
        $this->assertStringContainsString('after', $kept, 'the directory was recreated for the next line');
        $this->assertStringNotContainsString('lost', $kept);
    }

    public function testAnUnwritableLocationStaysSilent(): void
    {
        $project = new TempProject(['blocker' => 'a regular file where the log dir should go']);
        $log = $this->log($project->path('blocker').'/.porta/diag.log');

        $log->append('lost'); // the parent is a file: nothing to create, nothing thrown

        $this->assertFileDoesNotExist($log->path());
    }

    public function testAMissingParentTreeIsNotCreated(): void
    {
        // Only the `.porta` directory itself is ever created, and only inside a
        // directory that already exists. A log path under a tree that is not
        // there is a misconfiguration, and quietly minting the whole chain for
        // it would scatter empty directories wherever a path was fat-fingered.
        $project = new TempProject();
        $log = $this->log($project->dir.'/absent/deeper/.porta/diag.log');

        $log->append('lost');

        $this->assertDirectoryDoesNotExist($project->dir.'/absent', 'nothing above the log directory is minted');
        $this->assertFileDoesNotExist($log->path());
    }

    public function testTheLogDirectoryIsCreatedGroupAndWorldReadable(): void
    {
        // 0755, not 0777: the diagnostics are readable by whoever is helping
        // (and by a CI step collecting them), and writable only by their owner.
        // The umask is pinned because it is what decides the answer — the mode
        // asked for is masked, so a test that let the environment choose would
        // pass or fail on the machine rather than on the code.
        $project = new TempProject();
        $log = $this->log($project->dir.'/.porta/diag.log');
        $previous = \umask(0o022);

        try {
            $log->append('first');
        } finally {
            \umask($previous);
        }

        $this->assertSame(0o755, 0o777 & (int) \fileperms($project->dir.'/.porta'));
    }

    /**
     * A permission-denied mkdir() emits a native PHP warning; phpunit.xml.dist
     * turns any triggered warning into a test failure, so this only passes if
     * append()'s own error handler actually suppresses it (never throws, never
     * leaks) — exactly the "must not break the app it is diagnosing" contract.
     */
    public function testANativeMkdirWarningIsSuppressedNotLeaked(): void
    {
        $project = new TempProject();
        \chmod($project->dir, permissions: 0o555); // exists, but mkdir() inside it fails
        $log = $this->log($project->dir.'/.porta/diag.log');

        try {
            $log->append('lost');
        } finally {
            \chmod($project->dir, permissions: 0o755);
        }

        $this->assertFileDoesNotExist($log->path());
    }

    /** Same guarantee as above, for the write itself once the directory already exists. */
    public function testANativeFilePutContentsWarningIsSuppressedNotLeaked(): void
    {
        $project = new TempProject();
        \mkdir($project->dir.'/.porta', permissions: 0o777, recursive: true);
        \chmod($project->dir.'/.porta', permissions: 0o555); // exists, but is not writable
        $log = $this->log($project->dir.'/.porta/diag.log');

        try {
            $log->append('lost');
        } finally {
            \chmod($project->dir.'/.porta', permissions: 0o755);
        }

        $this->assertFileDoesNotExist($log->path());
    }
}
