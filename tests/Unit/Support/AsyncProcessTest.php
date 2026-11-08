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
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "hello\nworld\n"; exit(3);'], __DIR__);
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

    public function testABinaryThatIsNotOnThePathIsRefusedBeforeAnythingSpawns(): void
    {
        // The one signal a caller has for "this tool is not installed": the
        // array form of proc_open resolves the program itself, so a miss is
        // false HERE rather than an exit 127 from a shell later. Callers lean
        // on it — symfony-lsp tells "curl is not available" from "cannot reach
        // github.com" by exactly this — so it must stay a false, and it must
        // stay silent: no callback ever fires.
        $process = new AsyncProcess(['porta-no-such-binary-e2f7'], __DIR__);
        $fired = false;

        $this->assertFalse($process->start(static function () use (&$fired): void {
            $fired = true;
        }));

        $this->pump(0.05);
        $this->assertFalse($fired, 'nothing was spawned, so nothing can exit');
        $this->assertSame('', $process->output());
    }

    public function testStreamsChunksAsTheyArrive(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "a\n"; usleep(30000); echo "b\n";'], __DIR__);
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
        $this->assertSame("a\nb\n", \implode('', $chunks));
    }

    public function testAChildNeverInheritsTheParentsStdin(): void
    {
        // Without an explicit fd 0 the child would share the IDE's raw-mode
        // terminal and a prompting child would steal the user's keystrokes;
        // /dev/null answers any read with immediate EOF instead.
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo false === fgets(STDIN) ? "eof" : "data";'], __DIR__);
        $exit = null;
        $process->start(static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
        $this->assertSame('eof', $process->output());
    }

    public function testTheTimeoutCancelsAHungChildAndReportsFailure(): void
    {
        // The deadline is generous relative to the child's first write, so
        // the output-kept assertion cannot race the cancel under load.
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "start\n"; sleep(30);'], __DIR__, timeout: 2.5);
        $exits = [];
        $process->start(static function (int $code) use (&$exits): void {
            $exits[] = $code;
        });

        $this->await(static fn (): bool => '' !== $process->output(), what: 'the child to speak before its deadline');
        $this->await(static function () use (&$exits): bool {
            return [] !== $exits;
        }, what: 'the deadline to cancel the hung child');
        $this->pump(0.2); // a cancelled watcher or a second timer firing would double-report
        $this->assertSame([-1], $exits, 'the deadline reported exactly one failure exit');
        $this->assertSame("start\n", $process->output(), 'output up to the deadline is kept');
    }

    public function testAChildThatOutlivesItsPipesIsReapedWithoutBlockingTheLoop(): void
    {
        // The child closes stdout and lingers: EOF fires while it still runs,
        // where a plain proc_close would park the whole event loop.
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'fclose(STDOUT); usleep(300000); exit(7);'], __DIR__);
        $exit = null;
        $process->start(static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $ticks = 0;
        $ticker = EventLoop::repeat(0.02, static function () use (&$ticks): void {
            ++$ticks;
        });
        try {
            $this->await(static function () use (&$exit): bool {
                return null !== $exit;
            });
        } finally {
            EventLoop::cancel($ticker);
        }
        $this->assertSame(7, $exit, 'the straggler was reaped and its real exit code reported');
        $this->assertGreaterThan(5, $ticks, 'the loop kept turning while the child lingered');
    }

    public function testCancelMidRunStopsTheCallbacks(): void
    {
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "start\n"; sleep(5); echo "never\n";'], __DIR__);
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
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'echo "x";'], __DIR__);
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
            __DIR__,
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
        $process = new AsyncProcess([\PHP_BINARY, '-r', 'fwrite(STDERR, "dropped");'], __DIR__);
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
            __DIR__,
            captureStderr: true,
            env: ['PORTA_PROBE' => '42', 'PATH' => (string) \getenv('PATH')],
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
            __DIR__,
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
        $payload = \str_repeat('x', 512 * 1024); // 8x a 64KB pipe buffer
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'echo strlen(stream_get_contents(STDIN));'],
            __DIR__,
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
     * fwrite parked the caller inside start() until the child exited — 1.5 s
     * of dead event loop here, and unbounded for a longer-lived child. The
     * watcher simply stops being writable and start() returns at once.
     *
     * Measured around start() rather than with a tick counter: the blocking
     * write happened before any loop turn, so ticks could not observe it.
     */
    public function testStartReturnsImmediatelyWhenTheChildNeverReadsStdin(): void
    {
        $process = new AsyncProcess(
            [\PHP_BINARY, '-r', 'usleep(1500000); echo "done";'], // never touches STDIN
            __DIR__,
            stdin: \str_repeat('y', 512 * 1024),
        );

        $out = null;
        $before = \hrtime(as_number: true);
        $process->start(static function (int $code, string $stdout) use (&$out): void {
            $out = $stdout;
        });
        $spentInStart = (\hrtime(as_number: true) - $before) / 1e6;

        $this->await(static function () use (&$out): bool {
            return null !== $out;
        }, timeout: 10.0);

        $this->assertSame('done', $out);
        // Half the child's life, not a tight bound: blocking costs all of it,
        // and on a loaded box proc_open() alone passed the 100 ms this was.
        $this->assertLessThan(750.0, $spentInStart, 'start() must not block on the child draining stdin');
    }
}
