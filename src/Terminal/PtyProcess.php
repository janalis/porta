<?php

declare(strict_types=1);

namespace Porta\Terminal;

use Porta\Support\NonBlockingWriter;
use Porta\Support\ProcessReaper;
use Revolt\EventLoop;

/**
 * A child process attached to a pseudo-terminal, read asynchronously on the
 * Revolt event loop. Output (stdout+stderr, merged by the PTY) arrives via a
 * callback; keystrokes are forwarded with {@see write()}.
 *
 * Falls back to plain pipes when the platform lacks PTY support, in which case
 * child programs see a non-TTY (no colour) — tracked by the `$pty` flag.
 */
final class PtyProcess
{
    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $master;
    /** @var resource|null pipe-fallback stdin */
    private $stdin;
    private ?string $watcher = null;
    private bool $pty = false;
    private bool $running = false;
    private int $exitCode = -1;
    /** @var resource|null a child whose terminal closed, until the reaper collects it */
    private $reaping;
    private bool $stopped = false;
    /** The run's process group — its leader's pid — once the shim gave it one; 0 without. */
    private int $group = 0;
    /** What is typed or pasted, queued until the child's side takes it: a write takes only what fits. */
    private ?NonBlockingWriter $input = null;

    /** @var (callable(string): void)|null */
    private $onOutput;
    /** @var (callable(int): void)|null */
    private $onExit;

    /**
     * @param list<string> $command
     * @param array<string, string> $env extra environment (COLUMNS/LINES/TERM added)
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
        private readonly int $columns = 80,
        private readonly int $rows = 24,
        private readonly array $env = [],
    ) {}

    public function onOutput(callable $callback): self
    {
        $this->onOutput = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function start(): bool
    {
        $env = \array_merge(\getenv(), $this->env, [
            'COLUMNS' => (string) $this->columns,
            'LINES' => (string) $this->rows,
            'TERM' => 'xterm-256color',
        ]);

        // Prefer a real PTY; fall back to pipes if unsupported.
        $pipes = [];
        /** @var non-empty-string $cwd */
        $cwd = $this->cwd;
        $ptyDescriptors = [['pty'], ['pty'], ['pty']];
        // proc_open()'s ['pty'] descriptor is a real Linux/macOS feature that
        // mago's stub does not model (it only lists pipe/file/resource forms).
        // @mago-expect lint:no-error-control-operator -- proc_open warns when the system refuses a PTY or cannot spawn the binary; either way the false return drives the pipe fallback below, which answers the second case for good.
        // Irreducible without lying about the descriptor. Re-checked 2026-09-02.
        // @mago-expect analysis:possibly-invalid-argument
        $process = @\proc_open($this->inItsOwnSession(), $ptyDescriptors, $pipes, $cwd, $env);
        $this->pty = \is_resource($process);

        // Pipe fallback only runs where PTYs are unavailable; unreachable on
        // this (PTY-capable) build.
        // @codeCoverageIgnoreStart
        if (!$this->pty) {
            $pipeDescriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return is handled below.
            $process = @\proc_open($this->command, $pipeDescriptors, $pipes, $cwd, $env);
            if (!\is_resource($process)) {
                return false;
            }
            /** @var array{0: resource, 1?: resource, 2?: resource} $pipes */
            $this->stdin = $pipes[0];
        }
        // @codeCoverageIgnoreEnd

        /** @var resource $process */
        $this->process = $process;
        // Kept apart from the process: stopping the group must outlive the
        // leader, which can end (or close its terminal) and leave a server
        // it started running. A pid is not reused while a group carries it.
        $this->group = $this->pty && $this->sessioned() ? \proc_get_status($process)['pid'] : 0;
        /** @var array{0: resource, 1?: resource, 2?: resource} $pipes */
        $this->master = $pipes[1] ?? $pipes[0];
        \stream_set_blocking($this->master, enable: false);
        $input = $this->pty ? $this->master : $this->stdin;
        \assert(\is_resource($input), description: 'a started process has somewhere to send input');
        \stream_set_blocking($input, enable: false);
        // A child that died takes no more input; its end is reported by the
        // read side, so a failed write has nothing to add. No cap, as for the
        // terminal's own output: an overflow would kill the writer for good,
        // and every keystroke after a huge paste would go with it.
        $this->input = new NonBlockingWriter($input, static function (string $reason): void {}, \PHP_INT_MAX);
        $this->running = true;

        $this->watcher = EventLoop::onReadable($this->master, function (string $watcherId): void {
            /** @var resource $master */
            $master = $this->master;
            // Linux answers a read on the master after the child closed its end
            // with EIO (macOS with an empty read at EOF): fread() warns and
            // returns false. That is the end of the output, not an error.
            // @mago-expect lint:no-error-control-operator -- a pty master reports its closed slave as EIO on Linux, a warning no guard can predict; false is read as EOF just below.
            $chunk = \is_resource($master) ? @\fread($master, length: 65_536) : false;
            if (\is_string($chunk) && '' !== $chunk) {
                if (null !== $this->onOutput) {
                    ($this->onOutput)($chunk);
                }

                return;
            }
            if (false === $chunk || !\is_resource($this->master) || \feof($this->master)) {
                $this->finish();
            }
        });

        return true;
    }

    /**
     * Forward bytes — keystrokes, a paste — to the child. The terminal takes
     * about a kilobyte at a time; the rest is queued and goes out as the child
     * reads, in order, instead of being dropped.
     */
    public function write(string $data): void
    {
        $this->input?->write($data);
        if ("\x03" === $data && $this->interruptsWithoutAKernel()) {
            $this->signalGroup(\SIGINT); // @codeCoverageIgnore -- only where the session has no controlling tty, which macOS always gives it
        }
    }

    /**
     * Whether Ctrl+C should interrupt and the kernel will not see to it: the
     * terminal's `isig` is on (`stty -a` on the master reads the child's own
     * modes) and the run has no controlling tty (`ps` names no terminal
     * foreground group for it).
     */
    private function interruptsWithoutAKernel(): bool
    {
        $modes = $this->probe(['stty', '-a'], $this->master);

        return 1 === \preg_match('/(?<![-\w])isig\b/', $modes) && (int) \trim($this->probe(['ps', '-o', 'tpgid=', '-p', (string) $this->group])) <= 0;
    }

    /**
     * What $command prints, its input $stdin when one is given; '' when it cannot run.
     *
     * @param list<string>  $command
     * @param resource|null $stdin
     */
    private function probe(array $command, $stdin = null): string
    {
        $said = '';
        $pipes = [];
        $input = ['pipe', 'r'];
        if (\is_resource($stdin)) {
            $input = $stdin;
        }
        $process = \proc_open($command, [0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = $pipes[1] ?? null;
        if (\is_resource($process) && \is_resource($out)) {
            $said = (string) \stream_get_contents($out);
            \proc_close($process);
        }

        return $said;
    }

    /**
     * The command, started in a session of its own through a few lines of
     * PHP that size the terminal and then become it (`pcntl_exec`, same pid):
     * the run and everything it starts are one process group Porta can stop
     * whole. Run in Porta's own group, a `php -S` a composer script started
     * outlived its tab, its re-run and the project, holding its port.
     *
     * macOS makes the terminal the new session's controlling tty, so Ctrl+C
     * is the kernel's to deliver, as on any terminal: a SIGINT to the job in
     * the foreground while `isig` is on, and a plain key to a program in raw
     * mode — an editor's prefix, fzf's cancel. Where the session gets none,
     * {@see write()} stands in for it, under the same rule. (Opening the tty
     * to acquire one would make the session hold it: a child that closed its
     * terminal would no longer read as EOF.)
     *
     * @return list<string>
     */
    private function inItsOwnSession(): array
    {
        $shim = \sprintf(
            '@exec("stty rows %d cols %d 2>/dev/null"); if (function_exists("posix_setsid")) { posix_setsid(); } pcntl_exec("/usr/bin/env", array_slice($argv, 1)); exit(127);',
            $this->rows,
            $this->columns,
        );

        return $this->sessioned() ? [\PHP_BINARY, '-r', $shim, '--', ...$this->command] : $this->command;
    }

    /** Whether the run starts in a session of its own — a group Porta can signal whole. */
    private function sessioned(): bool
    {
        return \function_exists('pcntl_exec') && \function_exists('posix_kill') && \is_executable('/usr/bin/env');
    }

    /** Send $signal to the run's whole process group, when it has one of its own. */
    private function signalGroup(int $signal): void
    {
        if ($this->group > 0) {
            \posix_kill(-$this->group, $signal);
        }
    }

    public function stop(): void
    {
        // A stopped process never reports — not even an exit the reaper is
        // still collecting: the session may already belong to the next run.
        // One that outlived its terminal is killed like any other; the reaper
        // still collects it.
        $this->stopped = true;
        $this->input?->close();
        $this->input = null;
        if (\is_resource($this->reaping)) {
            \proc_terminate($this->reaping, \SIGKILL);
        }
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        // The whole group: a server the run started goes with it.
        $this->signalGroup(\SIGKILL);
        if (\is_resource($this->process)) {
            \proc_terminate($this->process, \SIGKILL);
            \proc_close($this->process);
        }
        $this->process = null;
        $this->running = false;
    }

    /** The exit code once the process has ended by itself; null while it runs, and for one that was stopped. */
    public function exitCode(): ?int
    {
        return null === $this->process && $this->exitCode >= 0 ? $this->exitCode : null;
    }

    /**
     * The terminal closed: collect the exit code once the child is reaped.
     *
     * EOF on the PTY says only that nothing holds the terminal any more — the
     * child can outlive it, and on a loaded machine its exit can take a while
     * to become waitable. The reaper answers at once for one already gone (the
     * common case) and polls a straggler on a timer, so the code reported is
     * the one the child exits with, never a -1 guessed after a fixed spin; and
     * the loop never blocks waiting.
     */
    private function finish(): void
    {
        $this->input?->close();
        $this->input = null;
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        // Still running until it is reaped: what waits for the end must hear
        // the exit code with it, not a moment before it.
        $process = $this->process;
        $this->process = null;
        if (\is_resource($process)) {
            $this->reaping = $process;
            ProcessReaper::closeWhenDead($process, function (int $code): void {
                $this->reaping = null;
                if ($this->stopped) {
                    return;
                }
                $this->running = false;
                $this->exitCode = $code;
                if (null !== $this->onExit) {
                    ($this->onExit)($code);
                }
            });
        }
    }
}
