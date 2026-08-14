<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\DiagnosticLog;
use Porta\Tests\Support\TempProject;

final class DiagnosticLogTest extends TestCase
{
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
}
