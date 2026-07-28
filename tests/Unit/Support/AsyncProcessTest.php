<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AsyncProcess;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

final class AsyncProcessTest extends TestCase
{
    use PumpsLoop;

    public function testCollectsStdoutAndExitCode(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "hello\nworld\n"; exit(3);'], getcwd());
        $exit = null;
        $out = null;
        $this->assertTrue($process->start(static function (int $code, string $stdout) use (&$exit, &$out): void {
            $exit = $code;
            $out = $stdout;
        }));

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
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

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
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

        $this->await(static function () use (&$process): bool {
            return '' !== $process->output();
        });
        $process->cancel();
        $this->pump(0.1); // give a cancelled watcher a chance to misfire
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

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
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

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
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

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
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

        $this->await(static function () use (&$out): bool {
            return null !== $out;
        });
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
        $this->pump(0.06); // a few ticks: mid-flight
        $process->cancel();
        $process->cancel(); // idempotent

        $this->pump(0.1);
        $this->assertFalse($exited, 'the exit callback never fires after cancel');
    }

    /**
     * The regression this exists for: a payload larger than the OS pipe buffer
     * (64 KB) used to be written with one blocking fwrite, so the loop stalled
     * until the child drained it — and if the child was itself waiting on us,
     * forever. The writer now feeds the pipe from an onWritable watcher.
     */
    public function testStdinLargerThanThePipeBufferDoesNotStall(): void
    {
        $payload = str_repeat('x', 512 * 1024); // 8x a 64KB pipe buffer
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'echo strlen(stream_get_contents(STDIN));'],
            (string) getcwd(),
            stdin: $payload,
        );

        $out = null;
        $this->assertTrue($process->start(static function (int $code, string $stdout) use (&$out): void {
            $out = $stdout;
        }));

        $this->await(static function () use (&$out): bool {
            return null !== $out;
        }, timeout: 10.0);

        $this->assertSame((string) \strlen($payload), $out, 'the child received every byte');
    }

    /**
     * The discriminating case: a child that never reads stdin. Once the 64KB
     * pipe buffer fills there is no reader to drain it, so the old blocking
     * fwrite parked the caller inside start() until the child exited — 300ms
     * of dead event loop here, and unbounded for a longer-lived child. The
     * watcher simply stops being writable and start() returns at once.
     *
     * Measured around start() rather than with a tick counter: the blocking
     * write happened before any loop turn, so ticks could not observe it.
     */
    public function testStartReturnsImmediatelyWhenTheChildNeverReadsStdin(): void
    {
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'usleep(400000); echo "done";'], // never touches STDIN
            (string) getcwd(),
            stdin: str_repeat('y', 512 * 1024),
        );

        $out = null;
        $before = hrtime(as_number: true);
        $process->start(static function (int $code, string $stdout) use (&$out): void {
            $out = $stdout;
        });
        $spentInStart = (hrtime(as_number: true) - $before) / 1e6;

        $this->await(static function () use (&$out): bool {
            return null !== $out;
        }, timeout: 10.0);

        $this->assertSame('done', $out);
        $this->assertLessThan(100.0, $spentInStart, 'start() must not block on the child draining stdin');
    }
}
