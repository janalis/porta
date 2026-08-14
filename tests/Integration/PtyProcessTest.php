<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
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
        $this->await(static function () use (&$buffer): bool {
            return str_contains(implode("\n", $buffer->getLines()), 'ping');
        });

        $this->assertStringContainsString('ping', implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $buffer->getLines())));
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
