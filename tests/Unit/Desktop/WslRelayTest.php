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

    /** What the relay wrote to its stderr, drained while waiting for it to end. */
    private string $errors = '';

    /**
     * A relay a test left running is ended as the window would end it. Killing
     * the relay alone left its command behind: the command has a session of its
     * own, and on macOS opening the PTY gives it no controlling terminal, so no
     * hang-up ever reaches it. Every run leaked a shell, and one holding
     * PHPUnit's output kept a pipe on it open for good.
     */
    protected function tearDown(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }
        if (\proc_get_status($this->process)['running']) {
            $this->closeTheWindow();
        }
    }

    /** The window going away: the relay hangs its command up and ends with it. */
    private function closeTheWindow(): void
    {
        if (\is_resource($this->pipes[0] ?? null)) {
            \fclose($this->pipes[0]);
        }
        $deadline = \microtime(true) + 8.0;
        while (\proc_get_status($this->process)['running'] && \microtime(true) < $deadline) {
            // What the command still prints must flow, or the relay blocks writing it.
            $this->output .= (string) \fread($this->pipes[1], length: 65_536);
            \usleep(20_000);
        }
        $status = \proc_get_status($this->process);
        if (!$status['running']) {
            return;
        }
        // It did not hang up: end the command's own session, then the relay.
        foreach (\explode("\n", \trim((string) \shell_exec('pgrep -P '.$status['pid']))) as $child) {
            if ('' === $child) {
                continue;
            }
            \posix_kill(-(int) $child, \SIGKILL);
        }
        \proc_terminate($this->process, \SIGKILL);
        \proc_close($this->process);
    }

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
        \stream_set_blocking($this->pipes[2], enable: false);
        do {
            // What it still prints must flow, or the relay blocks writing it
            // and never sees its command end — as closeTheWindow() drains.
            $this->output .= (string) \fread($this->pipes[1], length: 65_536);
            $this->errors .= (string) \fread($this->pipes[2], length: 65_536);
            $status = \proc_get_status($this->process);
            \usleep(20_000);
        } while ($status['running'] && \microtime(true) < $deadline);
        $this->assertFalse($status['running'], $status['running'] ? $this->stillRunning($status['pid']) : '');

        return $status['exitcode'];
    }

    /**
     * What a relay that outlived its deadline was doing. It happens about once in
     * thirty full CI suites, never in a loop of this test alone (480 runs, on the
     * hosted runner and a VM): the failure has to say why itself.
     */
    /** {@see exitStatus()} for a relay whose stdout this test has closed. */
    private function exitStatusUnread(): int
    {
        $deadline = \microtime(true) + 8.0;
        do {
            $status = \proc_get_status($this->process);
            \usleep(20_000);
        } while ($status['running'] && \microtime(true) < $deadline);
        $this->assertFalse($status['running'], 'the relay ended');

        return $status['exitcode'];
    }

    private function stillRunning(int $pid): string
    {
        $read = static fn (string $file): string => \is_readable($file) ? (string) \file_get_contents($file) : '';
        $report = "The relay (pid {$pid}) is still running.\n";
        $pids = [$pid];
        for ($i = 0; $i < \count($pids) && $i < 8; ++$i) {
            $files = \glob("/proc/{$pids[$i]}/task/*/children");
            foreach (false === $files ? [] : $files as $file) {
                foreach (\explode(' ', \trim($read($file))) as $child) {
                    if ('' === $child) {
                        continue;
                    }
                    $pids[] = (int) $child;
                }
            }
        }
        foreach ($pids as $each) {
            \preg_match_all('/^(State|SigPnd|ShdPnd|SigBlk|SigIgn|SigCgt):.*$/m', $read("/proc/{$each}/status"), $lines);
            $report .= \sprintf("%d %s wchan=%s\n  %s\n", $each, \str_replace("\0", ' ', $read("/proc/{$each}/cmdline")), $read("/proc/{$each}/wchan"), \implode("\n  ", $lines[0]));
        }
        \stream_set_blocking($this->pipes[2], enable: false);

        return $report.'Its stderr: '.$this->errors.\fread($this->pipes[2], length: 65_536);
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
        $this->closeTheWindow();
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
        // On Linux the terminal then fails every read (EIO). Each read printed
        // PHP's notice into the output this relay carries — once a run here,
        // a pipeful on a loaded runner, where it blocked writing and never
        // saw its command end (wchan=anon_pipe_write, the command a zombie).
        $this->assertStringNotContainsString('fread()', $this->output.$this->errors);
    }

    /** A command that ignores the hangup is killed after a grace period: the relay, and wsl.exe, do not outlive the window for good. */
    public function testACommandIgnoringTheHangupIsEndedAnyway(): void
    {
        $this->relay(['/bin/sh', '-c', "trap '' HUP; echo GO; while :; do sleep 0.05; done"]);
        $this->await('GO');

        \fclose($this->pipes[0]);

        $this->assertNotSame(0, $this->exitStatus());
    }

    /**
     * A command that ignores the hangup and keeps printing: once the window
     * is gone, the relay's write fails, and it ends the command before it
     * leaves — it left it running with no one to see it.
     */
    public function testACommandStillPrintingIsEndedWhenItsWindowCannotTakeIt(): void
    {
        $marker = \tempnam(\sys_get_temp_dir(), prefix: 'porta-relay-');
        $this->relay(['/bin/sh', '-c', "trap '' HUP; echo GO; while :; do echo tick; touch '{$marker}'; sleep 0.05; done"]);
        $this->await('GO');

        \fclose($this->pipes[0]);
        \fclose($this->pipes[1]); // the window gone: no one reads what it prints
        $this->assertNotSame(0, $this->exitStatusUnread());
        \clearstatcache();
        $last = (int) \filemtime($marker);
        \usleep(1_200_000);
        \clearstatcache();
        $this->assertSame($last, (int) \filemtime($marker), 'the command ends with the relay');
        \unlink($marker);
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
        $this->closeTheWindow();
    }
}
