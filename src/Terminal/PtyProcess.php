<?php

declare(strict_types=1);

namespace Porta\Terminal;

use Revolt\EventLoop;

/**
 * A child process attached to a pseudo-terminal, read asynchronously on the
 * Revolt event loop. Output (stdout+stderr, merged by the PTY) arrives via a
 * callback; keystrokes are forwarded with {@see write()}.
 *
 * Falls back to plain pipes when the platform lacks PTY support, in which case
 * child programs see a non-TTY (no colour) — surfaced by {@see isPty()}.
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

    /** @var (callable(string): void)|null */
    private $onOutput = null;
    /** @var (callable(int): void)|null */
    private $onExit = null;

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

    public function isPty(): bool
    {
        return $this->pty;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function start(): bool
    {
        $env = array_merge(getenv(), $this->env, [
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
        // @mago-expect analysis:possibly-invalid-argument
        $process = @proc_open($this->command, $ptyDescriptors, $pipes, $cwd, $env);
        $this->pty = \is_resource($process);

        // Pipe fallback only runs where PTYs are unavailable; unreachable on
        // this (PTY-capable) build.
        // @codeCoverageIgnoreStart
        if (!$this->pty) {
            $pipeDescriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $process = @proc_open($this->command, $pipeDescriptors, $pipes, $cwd, $env);
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
        stream_set_blocking($this->master, false);
        $this->running = true;

        $this->watcher = EventLoop::onReadable($this->master, function (string $watcherId): void {
            /** @var resource $master */
            $master = $this->master;
            $chunk = @fread($master, 65536);
            if (\is_string($chunk) && '' !== $chunk) {
                if (null !== $this->onOutput) {
                    ($this->onOutput)($chunk);
                }

                return;
            }
            if (!\is_resource($this->master) || feof($this->master)) {
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
            @fwrite($target, $data);
        }
    }

    public function kill(int $signal = \SIGTERM): void
    {
        if (\is_resource($this->process)) {
            @proc_terminate($this->process, $signal);
        }
    }

    public function stop(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (\is_resource($this->process)) {
            @proc_terminate($this->process, \SIGKILL);
            @proc_close($this->process);
        }
        $this->process = null;
        $this->running = false;
    }

    private function finish(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (\is_resource($this->process)) {
            // PTY EOF can arrive a hair before the child is reaped; poll briefly
            // so the real exit code is observed rather than a transient -1.
            $status = proc_get_status($this->process);
            for ($i = 0; $i < 50 && $status['running']; ++$i) {
                // The EOF-before-reap window is sub-200µs (the kernel releases
                // the controlling terminal during exit processing), so event-loop
                // dispatch latency makes this body unreachable deterministically.
                // @codeCoverageIgnoreStart
                usleep(2000);
                $status = proc_get_status($this->process);

                // @codeCoverageIgnoreEnd
            }
            $this->exitCode = $status['running'] ? -1 : (int) $status['exitcode'];
            @proc_close($this->process);
        }
        $this->process = null;
        $this->running = false;
        if (null !== $this->onExit) {
            ($this->onExit)($this->exitCode);
        }
    }
}
