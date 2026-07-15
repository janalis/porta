<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class PtyProcessTest extends TestCase
{
    /** @var list<PtyProcess> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            $p->stop();
        }
        $this->procs = [];
    }

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
        $this->assertTrue($p->isPty(), 'a real PTY was allocated');

        $this->await(static fn () => null !== $exit);
        $this->assertSame(3, $exit);
        $this->assertSame(3, $p->exitCode());
        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $buffer->getLines());
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
        $this->await(static fn () => str_contains(implode("\n", $buffer->getLines()), 'ping'));

        $this->assertStringContainsString('ping', implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $buffer->getLines())));
        $p->kill();
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
}
