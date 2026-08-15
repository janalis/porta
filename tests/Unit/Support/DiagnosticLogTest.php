<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\DiagnosticLog;
use Porta\Tests\Support\TempProject;

final class DiagnosticLogTest extends TestCase
{
    public function testForRootBuildsThePortaChannelPath(): void
    {
        $log = DiagnosticLog::forRoot('/tmp/proj', 'error');

        $this->assertSame('/tmp/proj/.porta/error.log', $log->path());
    }

    public function testAppendCreatesTheDirectoryAndAppendsLines(): void
    {
        $project = new TempProject();
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log');

        $log->append('first');
        $log->append('second');

        $this->assertSame("first\nsecond\n", file_get_contents($log->path()));
    }

    public function testAnOversizedFileStartsOverInsteadOfGrowing(): void
    {
        $project = new TempProject();
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log', maxBytes: 8);

        $log->append('0123456789'); // 11 bytes on disk — past the cap
        $log->append('fresh');

        $this->assertSame("fresh\n", file_get_contents($log->path()), 'the oversized file was started over, not appended to');
    }

    public function testAtTheCapItStillAppends(): void
    {
        $project = new TempProject();
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log', maxBytes: 5);

        $log->append('0123'); // exactly 5 bytes on disk — at the cap, not past it
        $log->append('456');

        $this->assertSame("0123\n456\n", file_get_contents($log->path()));
    }

    public function testAnUnwritableLocationStaysSilent(): void
    {
        $project = new TempProject(['blocker' => 'a regular file where the log dir should go']);
        $log = new DiagnosticLog($project->path('blocker').'/.porta/diag.log');

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
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log');

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
        $log = new DiagnosticLog($project->dir.'/.porta/diag.log');

        try {
            $log->append('lost');
        } finally {
            chmod($project->dir.'/.porta', permissions: 0o755);
        }

        $this->assertFileDoesNotExist($log->path());
    }
}
