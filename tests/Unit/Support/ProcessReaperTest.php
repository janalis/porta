<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\ProcessReaper;
use Porta\Tests\Support\PumpsLoop;

final class ProcessReaperTest extends TestCase
{
    use PumpsLoop;

    /** A child that ignores SIGTERM, says so, and gives up on its own after ten seconds. */
    private const string STUBBORN = 'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); echo "ready\n"; for ($i = 0; $i < 100; ++$i) { usleep(100000); }';

    public function testATerminatedChildThatIgnoresItIsKilledOnceItsGraceIsOver(): void
    {
        [$process, $pid] = $this->stubborn();

        ProcessReaper::terminate($process);

        $this->pump(ProcessReaper::GRACE - 0.5);
        $this->assertTrue(\posix_kill($pid, signal: 0), 'its grace first');
        $this->await(static fn (): bool => false === \posix_kill($pid, signal: 0), timeout: 3.0, what: 'the kill');
    }

    public function testAChildMerelyWaitedForIsNeverKilled(): void
    {
        [$process, $pid] = $this->stubborn();

        ProcessReaper::closeWhenDead($process);

        $this->pump(ProcessReaper::GRACE + 0.5);
        $this->assertTrue(\posix_kill($pid, signal: 0), 'a child that closed its pipes may still be finishing');
        \posix_kill($pid, signal: 9);
        $this->await(static fn (): bool => false === \posix_kill($pid, signal: 0), timeout: 3.0, what: 'the clean-up');
    }

    /** @return array{resource, int} */
    private function stubborn(): array
    {
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, '-r', self::STUBBORN], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->assertSame("ready\n", \fgets($pipes[1]), 'SIGTERM is ignored from here');

        return [$process, \proc_get_status($process)['pid']];
    }

    public function testAChildAskedToExitHasItsMomentThenIsTerminated(): void
    {
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, '-r', 'echo "ready\n"; sleep(10);'], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->assertSame("ready\n", \fgets($pipes[1]));
        $pid = \proc_get_status($process)['pid'];

        ProcessReaper::terminate($process, after: 0.5);

        $this->pump(0.2);
        $this->assertTrue(\posix_kill($pid, signal: 0), 'its moment first');
        // Gone before the kill would come, at its moment plus the grace: it was terminated.
        $this->await(static fn (): bool => false === \posix_kill($pid, signal: 0), timeout: ProcessReaper::GRACE - 0.3, what: 'the terminate after it');
    }
}
