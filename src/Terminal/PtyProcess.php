<?php

declare(strict_types=1);

namespace Porta\Terminal;

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
        // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return drives the pipe fallback below.
        // Irreducible without lying about the descriptor. Re-checked 2026-09-02.
        // @mago-expect analysis:possibly-invalid-argument
        $process = @\proc_open($this->command, $ptyDescriptors, $pipes, $cwd, $env);
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
        /** @var array{0: resource, 1?: resource, 2?: resource} $pipes */
        $this->master = $pipes[1] ?? $pipes[0];
        \stream_set_blocking($this->master, enable: false);
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
     * Forward bytes (keystrokes) to the child.
     */
    public function write(string $data): void
    {
        $target = $this->pty ? $this->master : $this->stdin;
        if (\is_resource($target)) {
            // @mago-expect lint:no-error-control-operator -- fwrite to a pty/pipe whose child has died (the fd is still a live resource) raises an unavoidable EPIPE/EIO warning that no is_resource guard prevents.
            @\fwrite($target, $data);
        }
    }

    public function stop(): void
    {
        // A stopped process never reports — not even an exit the reaper is
        // still collecting: the session may already belong to the next run.
        // One that outlived its terminal is killed like any other; the reaper
        // still collects it.
        $this->stopped = true;
        if (\is_resource($this->reaping)) {
            \proc_terminate($this->reaping, \SIGKILL);
        }
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
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
