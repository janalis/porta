<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AsyncProcess;
use Revolt\EventLoop;

final class AsyncProcessTest extends TestCase
{
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

    public function testCollectsStdoutAndExitCode(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "hello\nworld\n"; exit(3);'], getcwd());
        $exit = null;
        $out = null;
        $this->assertTrue($process->start(function (int $code, string $stdout) use (&$exit, &$out): void {
            $exit = $code;
            $out = $stdout;
        }));

        $this->await(static fn () => null !== $exit);
        $this->assertSame(3, $exit);
        $this->assertSame("hello\nworld\n", $out);
        $this->assertSame("hello\nworld\n", $process->output());
    }

    public function testStreamsChunksAsTheyArrive(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "a\n"; usleep(30000); echo "b\n";'], getcwd());
        $chunks = [];
        $exit = null;
        $process->start(
            onExit: function (int $code) use (&$exit): void {
                $exit = $code;
            },
            onChunk: function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        $this->await(static fn () => null !== $exit);
        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(2, \count($chunks), 'output split across sleeps arrives in separate chunks');
        $this->assertSame("a\nb\n", implode('', $chunks));
    }

    public function testCancelMidRunStopsTheCallbacks(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "start\n"; sleep(5); echo "never\n";'], getcwd());
        $exited = false;
        $process->start(function () use (&$exited): void {
            $exited = true;
        });

        $this->await(static fn () => '' !== $process->output());
        $process->cancel();
        $this->await(static fn () => false, timeout: 0.1); // give a cancelled watcher a chance to misfire
        $this->assertFalse($exited, 'onExit must not fire after cancel');
        $this->assertSame("start\n", $process->output());
    }

    public function testCancelIsIdempotentAndSafeAfterExit(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "x";'], getcwd());
        $exit = null;
        $process->start(function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->await(static fn () => null !== $exit);
        $process->cancel();
        $process->cancel();
        $this->assertSame(0, $exit);
    }
}
