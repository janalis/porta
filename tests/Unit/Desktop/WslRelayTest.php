<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\LoginEnvironment;

/** The Windows application's terminal, exercised over real pipes and a real PTY. */
final class WslRelayTest extends TestCase
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $output = '';

    /** @param list<string> $command */
    private function relay(array $command, int $columns = 100, int $rows = 30): void
    {
        $relay = \dirname(__DIR__, 3).'/desktop/runtime/wsl-relay.php';
        $process = \proc_open(
            [...LoginEnvironment::bareProcess((string) \ini_get('extension_dir')), $relay, (string) $columns, (string) $rows, ...$command],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );
        $this->assertIsResource($process);
        $this->process = $process;
        \stream_set_blocking($this->pipes[1], enable: false);
    }

    private function await(string $text): void
    {
        $deadline = \microtime(true) + 8.0;
        while (!\str_contains($this->output, $text) && \microtime(true) < $deadline) {
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            if (0 < (int) \stream_select($read, $write, $except, seconds: 0, microseconds: 50_000)) {
                $this->output .= (string) \fread($this->pipes[1], length: 65_536);
            }
        }
        $this->assertStringContainsString($text, $this->output);
    }

    private function exitStatus(): int
    {
        $deadline = \microtime(true) + 8.0;
        do {
            $status = \proc_get_status($this->process);
            \usleep(20_000);
        } while ($status['running'] && \microtime(true) < $deadline);
        $this->assertFalse($status['running']);

        return $status['exitcode'];
    }

    private function type(string $bytes): void
    {
        \fwrite($this->pipes[0], "\x00".\pack('N', \strlen($bytes)).$bytes);
    }

    public function testTheCommandRunsOnARealTerminalOfTheAskedSize(): void
    {
        $this->relay(['/bin/sh', '-c', 'test -t 0 && test -t 1 && echo IS_A_TTY; stty size; exit 3']);

        $this->await('IS_A_TTY');
        $this->await('30 100');
        $this->assertSame(3, $this->exitStatus());
    }

    public function testInputArrivesEvenWhenAFrameIsSplit(): void
    {
        $this->relay(['/bin/sh', '-c', 'stty -echo; echo GO; read line; echo "got:$line"']);
        $this->await('GO');
        $frame = "\x00".\pack('N', 6)."hello\n";

        \fwrite($this->pipes[0], \substr($frame, offset: 0, length: 3));
        \fflush($this->pipes[0]);
        \usleep(100_000);
        \fwrite($this->pipes[0], \substr($frame, offset: 3));

        $this->await('got:hello');
    }

    public function testAResizeReachesTheCommand(): void
    {
        $this->relay(['/bin/sh', '-c', 'trap "stty size" WINCH; echo GO; while :; do sleep 0.05; done']);
        $this->await('GO');

        \fwrite($this->pipes[0], "\x01".\pack('nn', 132, 43));

        $this->await('43 132');
        \proc_terminate($this->process);
    }

    public function testPrivateSequencesCrossByteForByte(): void
    {
        $sequence = '\033]7777;porta-desktop;abc;ready;L3dvcms=\033\\\\';
        $this->relay(['/bin/sh', '-c', "printf '{$sequence}'; echo DONE"]);

        $this->await('DONE');
        $this->assertStringContainsString("\x1b]7777;porta-desktop;abc;ready;L3dvcms=\x1b\\", $this->output);
    }

    public function testTheWindowGoingAwayEndsTheCommand(): void
    {
        $this->relay(['/bin/sh', '-c', 'echo GO; while :; do sleep 0.05; done']);
        $this->await('GO');

        \fclose($this->pipes[0]);

        $this->assertNotSame(0, $this->exitStatus());
    }

    public function testNoCommandIsRefused(): void
    {
        $this->relay([]);

        $this->assertSame(2, $this->exitStatus());
    }

    public function testATypedLineIsEchoedByTheTerminal(): void
    {
        $this->relay(['/bin/sh', '-c', 'echo GO; cat']);
        $this->await('GO');

        $this->type("typed\n");

        $this->await('typed');
        \proc_terminate($this->process);
    }
}
