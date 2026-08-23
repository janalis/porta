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
        $p = new PtyProcess(['/bin/sh', '-c', 'printf "line1\nline2\n"; exit 3'], getcwd());
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
        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines);
        $this->assertContains('line1', $lines);
        $this->assertContains('line2', $lines);
        $this->assertFalse($p->isRunning());
    }

    public function testForwardsInputToTheChild(): void
    {
        // `cat` echoes stdin back through the PTY.
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/cat'], getcwd());
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $p->write("ping\n");
        $this->await(static function () use (&$buffer): bool {
            return str_contains(implode("\n", $buffer->lines), 'ping');
        });

        $this->assertStringContainsString('ping', implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines)));
        $p->stop();
    }

    public function testStopIsIdempotentAndSafeBeforeStart(): void
    {
        $p = new PtyProcess(['/bin/sh', '-c', 'sleep 5'], getcwd());
        $this->procs[] = $p;
        $p->start();
        $this->assertTrue($p->isRunning());

        $p->stop();
        $p->stop(); // second stop is a no-op
        $this->assertFalse($p->isRunning());

        $fresh = new PtyProcess(['/bin/sh', '-c', 'true'], getcwd());
        $fresh->stop(); // stop before start
        $this->assertFalse($fresh->isRunning());
    }

    public function testDefaultColumnsAndRowsReachTheChildEnvironmentExactly(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'printf "%s:%s" "$COLUMNS" "$LINES"'], getcwd());
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $this->await(static function () use (&$buffer): bool {
            return str_contains(implode('', $buffer->lines), ':');
        });
        $this->assertSame('80:24', AnsiUtils::stripAnsiCodes(implode('', $buffer->lines)));
    }

    public function testCustomEnvAndParentEnvAndOverridesAllReachTheChild(): void
    {
        // array_merge(getenv(), $this->env, [COLUMNS/LINES/TERM]): each of the
        // three sources must survive — a base var inherited from this process,
        // a caller-supplied extra var, and the computed overrides.
        putenv('PORTA_TEST_PARENT_VAR=from_parent');
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(
            ['/bin/sh', '-c', 'printf "%s|%s|%s" "$PORTA_TEST_PARENT_VAR" "$PORTA_TEST_EXTRA" "$COLUMNS"'],
            getcwd(),
            env: ['PORTA_TEST_EXTRA' => 'from_ctor'],
        );
        $this->procs[] = $p;
        $p->onOutput($buffer->feed(...));
        $this->assertTrue($p->start());

        $this->await(static function () use (&$buffer): bool {
            return str_contains(implode('', $buffer->lines), '|');
        });
        putenv('PORTA_TEST_PARENT_VAR');
        $this->assertSame('from_parent|from_ctor|80', AnsiUtils::stripAnsiCodes(implode('', $buffer->lines)));
    }

    public function testStderrIsMergedIntoTheSamePtyOutputStream(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'echo out-line; echo err-line >&2'], getcwd());
        $this->procs[] = $p;
        $exit = null;
        $p->onOutput($buffer->feed(...))->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($p->start());

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        });
        $plain = implode('|', array_map(AnsiUtils::stripAnsiCodes(...), $buffer->lines));
        $this->assertStringContainsString('out-line', $plain);
        $this->assertStringContainsString('err-line', $plain, 'stderr must reach the same merged stream as stdout');
    }

    public function testStopCancelsTheWatcherSoNoFurtherOutputEverArrives(): void
    {
        $buffer = new TerminalBuffer();
        $p = new PtyProcess(['/bin/sh', '-c', 'while true; do echo tick; sleep 0.02; done'], getcwd());
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
        $deadline = microtime(as_float: true) + 0.15;
        while (microtime(as_float: true) < $deadline) {
            EventLoop::run();
        }
        $this->assertSame($countAfterStop, $buffer->lineCount(), 'no output may arrive after stop()');
    }

    public function testStopActuallyKillsTheOsProcessNotJustTheLocalFlags(): void
    {
        $p = new PtyProcess(['/bin/sh', '-c', 'sleep 30'], getcwd());
        $this->procs[] = $p;
        $this->assertTrue($p->start());
        $status = proc_get_status(Probe::get($p, 'process'));
        $pid = $status['pid'];

        $p->stop();

        $this->assertFalse(posix_kill($pid, signal: 0), 'the child OS process must actually be terminated, not merely marked stopped locally');
    }
}
