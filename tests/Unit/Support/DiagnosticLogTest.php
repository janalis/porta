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

        $this->assertSame(self::STAMP." first\n".self::STAMP." second\n", file_get_contents($log->path()));
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
            file_get_contents($log->path()),
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
            file_get_contents($log->path()),
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

        $this->assertSame(self::STAMP." fresh\n", file_get_contents($log->path()), 'nothing of the old file survived, because none of its tail was an entry');
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
        } while (filesize($log->path()) <= 120 && $n < 100);
        $last = 'entry '.$n;

        $log->append('newest');

        $kept = (string) file_get_contents($log->path());
        $this->assertStringContainsString('newest', $kept, 'the entry that crossed the cap is there');
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

        $this->assertSame(self::STAMP." 0123\n".self::STAMP." 456\n", file_get_contents($log->path()));
    }

    public function testAnUnwritableLocationStaysSilent(): void
    {
        $project = new TempProject(['blocker' => 'a regular file where the log dir should go']);
        $log = $this->log($project->path('blocker').'/.porta/diag.log');

        $log->append('lost'); // the parent is a file: nothing to create, nothing thrown

        $this->assertFileDoesNotExist($log->path());
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
        chmod($project->dir, permissions: 0o555); // exists, but mkdir() inside it fails
        $log = $this->log($project->dir.'/.porta/diag.log');

        try {
            $log->append('lost');
        } finally {
            chmod($project->dir, permissions: 0o755);
        }

        $this->assertFileDoesNotExist($log->path());
    }

    /** Same guarantee as above, for the write itself once the directory already exists. */
    public function testANativeFilePutContentsWarningIsSuppressedNotLeaked(): void
    {
        $project = new TempProject();
        mkdir($project->dir.'/.porta', permissions: 0o777, recursive: true);
        chmod($project->dir.'/.porta', permissions: 0o555); // exists, but is not writable
        $log = $this->log($project->dir.'/.porta/diag.log');

        try {
            $log->append('lost');
        } finally {
            chmod($project->dir.'/.porta', permissions: 0o755);
        }

        $this->assertFileDoesNotExist($log->path());
    }
}
