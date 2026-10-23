<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class PtyProcessTest extends TestCase
{
    use PumpsLoop;

    /**
     * A child that closes every descriptor that could hold the terminal — not
     * just stdio: the child inherits spare copies above fd 2, how many
     * depending on what the parent had open — so the PTY reports EOF at once
     * while the child lives on, then exits 7.
     *
     * @return list<string>
     */
    private static function closesItsTerminalThenExits7(float $after): array
    {
        // The long-lived variant execs its sleep, so the kill that ends it
        // leaves no orphan behind; the short one has an exit code to report.
        $tail = $after >= 10 ? 'exec sleep '.$after : 'sleep '.$after.'; exit 7';

        return ['/bin/sh', '-c', 'fd=0; while [ $fd -lt 256 ]; do eval "exec $fd<&-"; fd=$((fd+1)); done; '.$tail];
    }

    /** @var list<PtyProcess> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            $p->stop();
        }
        $this->procs = [];
    }

    public function testStreamsOutputAndReportsExitCode(): void
    {
        $buffer = new TerminalBuffer();
        $exit = null;
        $p = new PtyProcess(['/bin/sh', '-c', 'printf "line1\nline2\n"; exit 3'], __DIR__);
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...))->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });

        $this->assertTrue($p->start());
        $this->assertTrue(Probe::get($p, 'pty'), 'a real PTY was allocated');

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
        $this->assertSame(3, $exit);
        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines);
        $this->assertContains('line1', $lines);
        $this->assertContains('line2', $lines);
        $this->assertFalse($p->isRunning());
    }

    /**
     * The PTY can report EOF long before the child is reaped: a child that
     * closes its terminal and keeps going, or one the machine is too loaded to
     * schedule through its exit. Its exit code is still the one it exits with
     * — never a -1 guessed after a fixed spin, which made a waiter for exit 0
     * wait forever.
     */
    public function testAChildStillAliveAtTheTerminalsEofReportsItsRealExitCode(): void
    {
        $exit = null;
        $p = new PtyProcess(self::closesItsTerminalThenExits7(0.3), __DIR__);
        $this->procs[] = $p;
        $p->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });

        $this->assertTrue($p->start());
        $this->await(static fn (): bool => null !== Probe::get($p, 'reaping'), what: 'the terminal to close while the child lives on');
        $this->assertTrue($p->isRunning(), 'running until it is reaped: a waiter on the end must hear the code with it');
        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        }, what: 'the exit to be reported');

        $this->assertFalse($p->isRunning());
        $this->assertSame(7, $exit);
        $this->assertSame(7, $p->exitCode());
    }

    /** A process stopped while its exit is still being collected never reports: its session may already belong to the next run. */
    public function testAStopWhileTheExitIsCollectedSilencesTheReport(): void
    {
        $exit = null;
        $p = new PtyProcess(self::closesItsTerminalThenExits7(30), __DIR__);
        $this->procs[] = $p;
        $p->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($p->start());
        $this->await(static fn (): bool => null !== Probe::get($p, 'reaping'), what: 'the terminal to close while the child lives on');

        $reaping = Probe::get($p, 'reaping');
        $this->assertIsResource($reaping, 'the child outlived its terminal, so it is still being collected');
        $pid = \proc_get_status($reaping)['pid'];
        $p->stop();
        $this->await(static fn (): bool => !\posix_kill($pid, signal: 0), what: 'the child that outlived its terminal to be killed and reaped, not left running');

        $this->assertNull($exit);
        $this->assertNull($p->exitCode(), 'a stopped process has no exit code of its own');
    }

    /**
     * A paste far larger than the terminal's buffer arrives whole. The master
     * side is non-blocking, so a write takes only what fits; the rest used to
     * be dropped without a word, and a paste past the queue's cap killed the
     * writer outright.
     */
    public function testAWriteLargerThanTheTerminalTakesAtOnceArrivesWhole(): void
    {
        $out = \sys_get_temp_dir().'/porta-pty-paste-'.\getmypid().'-'.\bin2hex(\random_bytes(4));
        // Past the 8 MiB a NonBlockingWriter queues by default: an overflow
        // used to kill the writer, and every later keystroke with it.
        $size = 9_000_000;
        $p = new PtyProcess(['/bin/sh', '-c', 'stty raw -echo; exec head -c '.$size.' > '.$out], __DIR__);
        $this->procs[] = $p;
        $exit = null;
        $p->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($p->start());
        $this->pump(0.2); // stty has run

        $p->write(\str_repeat('x', $size));

        try {
            $this->await(
                static function () use (&$exit): bool {
                    return null !== $exit;
                },
                timeout: 30.0,
                what: 'the child to have read every byte',
            );
            $this->assertSame(0, $exit);
            $this->assertSame($size, \filesize($out));
        } finally {
            if (\is_file($out)) {
                \unlink($out);
            }
        }
    }

    /** Input to a process that is gone — stopped, or never started — goes nowhere, quietly. */
    public function testWritingToAStoppedProcessIsANoOp(): void
    {
        $unstarted = new PtyProcess(['/bin/cat'], __DIR__);
        $unstarted->write('before');
        $p = new PtyProcess(['/bin/cat'], __DIR__);
        $this->procs[] = $p;
        $this->assertTrue($p->start());
        $p->stop();

        $p->write('after');

        $this->assertFalse($p->isRunning());
    }

    /**
     * A paste the child never reads waits in a queue with a watcher on the
     * loop (in raw mode: a cooked terminal discards what overflows its line
     * instead of pushing back). Stopping the process, or its terminal closing, takes that watcher
     * away — or the loop keeps a write to a dead terminal armed forever.
     */
    public function testQueuedInputLeavesNothingOnTheLoopWhenTheProcessGoes(): void
    {
        $stopped = new PtyProcess(['/bin/sh', '-c', 'stty raw -echo; exec sleep 30'], __DIR__);
        $this->procs[] = $stopped;
        $this->assertTrue($stopped->start());
        $this->pump(0.2); // raw mode is set: the terminal pushes back instead of discarding
        $stopped->write(\str_repeat('x', times: 200_000));
        $watcher = $this->inputWatcher($stopped);
        $stopped->stop();
        $this->assertFalse(self::onTheLoop($watcher), 'stopped: the queue\'s watcher is gone');

        $exit = null;
        $ended = new PtyProcess(['/bin/sh', '-c', 'stty raw -echo; sleep 0.3'], __DIR__);
        $this->procs[] = $ended;
        $ended->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($ended->start());
        $this->pump(0.1);
        $ended->write(\str_repeat('x', times: 200_000));
        $watcher = $this->inputWatcher($ended);
        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        }, what: 'the child to end without reading');
        $this->assertFalse(self::onTheLoop($watcher), 'ended: the queue\'s watcher is gone');
    }

    /** The id of the watcher draining $p's queued input — there is one, or the test proves nothing. */
    private function inputWatcher(PtyProcess $p): string
    {
        $watcher = Probe::get(Probe::object($p, 'input'), 'watcher');
        $this->assertIsString($watcher, 'more input than the terminal takes at once is queued, with a watcher');

        return $watcher;
    }

    private static function onTheLoop(string $watcher): bool
    {
        return \in_array($watcher, EventLoop::getIdentifiers(), strict: true);
    }

    public function testForwardsInputToTheChild(): void
    {
        // `cat` echoes stdin back through the PTY.
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/cat'], __DIR__);
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $p->write("ping\n");
        $this->await(static function () use (&$buffer): bool {
            return \str_contains(\implode("\n", $buffer->lines), 'ping');
        });

        $this->assertStringContainsString('ping', \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines)));
        $p->stop();
    }

    public function testStopIsIdempotentAndSafeBeforeStart(): void
    {
        $p = new PtyProcess(['/bin/sh', '-c', 'sleep 5'], __DIR__);
        $this->procs[] = $p;
        $p->start();
        $this->assertTrue($p->isRunning());

        $p->stop();
        $p->stop(); // second stop is a no-op
        $this->assertFalse($p->isRunning());

        $fresh = new PtyProcess(['/bin/sh', '-c', 'true'], __DIR__);
        $fresh->stop(); // stop before start
        $this->assertFalse($fresh->isRunning());
    }

    public function testDefaultColumnsAndRowsReachTheChildEnvironmentExactly(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'printf "%s:%s" "$COLUMNS" "$LINES"'], __DIR__);
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $this->await(static function () use (&$buffer): bool {
            return \str_contains(\implode('', $buffer->lines), ':');
        });
        $this->assertSame('80:24', AnsiUtils::stripAnsiCodes(\implode('', $buffer->lines)));
    }

    public function testCustomEnvAndParentEnvAndOverridesAllReachTheChild(): void
    {
        // array_merge(getenv(), $this->env, [COLUMNS/LINES/TERM]): each of the
        // three sources must survive — a base var inherited from this process,
        // a caller-supplied extra var, and the computed overrides.
        \putenv('PORTA_TEST_PARENT_VAR=from_parent');
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(
            ['/bin/sh', '-c', 'printf "%s|%s|%s" "$PORTA_TEST_PARENT_VAR" "$PORTA_TEST_EXTRA" "$COLUMNS"'],
            __DIR__,
            env: ['PORTA_TEST_EXTRA' => 'from_ctor'],
        );
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $this->await(static function () use (&$buffer): bool {
            return \str_contains(\implode('', $buffer->lines), '|');
        });
        \putenv('PORTA_TEST_PARENT_VAR');
        $this->assertSame('from_parent|from_ctor|80', AnsiUtils::stripAnsiCodes(\implode('', $buffer->lines)));
    }

    public function testStderrIsMergedIntoTheSamePtyOutputStream(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'echo out-line; echo err-line >&2'], __DIR__);
        $this->procs[] = $p;
        $exit = null;
        $p->onOutput($buffer->feed(...))->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($p->start());

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
        $plain = \implode('|', \array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines));
        $this->assertStringContainsString('out-line', $plain);
        $this->assertStringContainsString('err-line', $plain, 'stderr must reach the same merged stream as stdout');
    }

    public function testStopCancelsTheWatcherSoNoFurtherOutputEverArrives(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'while true; do echo tick; sleep 0.02; done'], __DIR__);
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $this->await(static function () use (&$buffer): bool {
            return $buffer->lineCount() > 1;
        });
        $before = EventLoop::getIdentifiers();
        $p->stop();
        $after = EventLoop::getIdentifiers();
        $this->assertLessThan(\count($before), \count($after), 'stop() must cancel its own watcher, not just null the property');

        $countAfterStop = $buffer->lineCount();
        // Give any leaked watcher a chance to fire before asserting silence.
        // pump(), not a tight `while (…) { EventLoop::run(); }`: that spun a
        // whole core for the 0.15s, and this is a genuine negative — the child
        // is still echoing every 0.02s, so the span has to be real time and
        // there is nothing to await.
        $this->pump(0.15);
        $this->assertSame($countAfterStop, $buffer->lineCount(), 'no output may arrive after stop()');
    }

    public function testStopActuallyKillsTheOsProcessNotJustTheLocalFlags(): void
    {
        $p = new PtyProcess(['/bin/sh', '-c', 'sleep 30'], __DIR__);
        $this->procs[] = $p;
        $this->assertTrue($p->start());
        $status = \proc_get_status(Probe::get($p, 'process'));
        $pid = $status['pid'];

        $p->stop();

        $this->assertFalse(\posix_kill($pid, signal: 0), 'the child OS process must actually be terminated, not merely marked stopped locally');
    }
}
