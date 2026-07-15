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
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    public function testCollectsStdoutAndExitCode(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "hello\nworld\n"; exit(3);'], getcwd());
        $exit = null;
        $out = null;
        $this->assertTrue($process->start(static function (int $code, string $stdout) use (&$exit, &$out): void {
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
            onExit: static function (int $code) use (&$exit): void {
                $exit = $code;
            },
            onChunk: static function (string $chunk) use (&$chunks): void {
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
        $process->start(static function () use (&$exited): void {
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
        $process->start(static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->await(static fn () => null !== $exit);
        $process->cancel();
        $process->cancel();
        $this->assertSame(0, $exit);
    }

    public function testCapturesStderrAlongsideStdout(): void
    {
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "boom"); exit(1);'],
            getcwd(),
            captureStderr: true,
        );
        $exit = null;
        $this->assertTrue($process->start(static function (int $code) use (&$exit): void {
            $exit = $code;
        }));

        $this->await(static fn () => null !== $exit);
        $this->assertSame(1, $exit);
        $this->assertSame('out', $process->output());
        $this->assertSame('boom', $process->errorOutput(), 'stderr drained into its own buffer');
    }

    public function testStderrStaysEmptyWhenNotCaptured(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'fwrite(STDERR, "dropped");'], getcwd());
        $exit = null;
        $process->start(static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->await(static fn () => null !== $exit);
        $this->assertSame('', $process->errorOutput());
    }

    public function testExplicitEnvironmentReachesTheChild(): void
    {
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'echo getenv("PORTA_PROBE") ?: "unset";'],
            getcwd(),
            captureStderr: true,
            env: ['PORTA_PROBE' => '42', 'PATH' => (string) getenv('PATH')],
        );
        $out = null;
        $process->start(static function (int $code, string $stdout) use (&$out): void {
            $out = $stdout;
        });

        $this->await(static fn () => null !== $out);
        $this->assertSame('42', $out);
    }

    public function testCancelMidFlightWithStderrCaptureIsClean(): void
    {
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'fwrite(STDERR, "start"); sleep(5); fwrite(STDERR, "late");'],
            getcwd(),
            captureStderr: true,
        );
        $exited = false;
        $process->start(static function () use (&$exited): void {
            $exited = true;
        });
        $this->await(static fn () => false, 0.06); // a few ticks: mid-flight
        $process->cancel();
        $process->cancel(); // idempotent

        $this->await(static fn () => false, 0.1);
        $this->assertFalse($exited, 'the exit callback never fires after cancel');
    }
}
