<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * A one-shot child process whose stdout is drained asynchronously on the
 * Revolt event loop: spawn, accumulate output, report the exit code at EOF,
 * cancel mid-flight. The single home for the proc_open → non-blocking pipe →
 * onReadable pattern previously copy-pasted by the project search and the git
 * integration. Stderr is discarded unless $captureStderr is set — git remote
 * operations need it, because git writes progress and every human-relevant
 * error there.
 *
 * Long-lived duplex transports (the LSP client, the PTY) have their own
 * plumbing — this is deliberately only the run-to-completion shape.
 */
final class AsyncProcess
{
    /**
     * Generous ceiling for a network-bound child (a git clone, a
     * composer/brew/cargo install) — a stall past it frees the caller's
     * single process slot rather than pinning it for the rest of the session.
     */
    public const float EXTERNAL_TOOL_TIMEOUT = 300.0;

    /**
     * Ceiling for an interactive, single-file tool run (format/lint/highlight
     * on save or on type) — generous for one file, short enough that a hang
     * recovers within the session rather than pinning the slot for 5 minutes.
     */
    public const float INTERACTIVE_TOOL_TIMEOUT = 60.0;

    /** @var resource|null */
    private $process;

    /** @var list<string> ids of the active pipe watchers */
    private array $watchers = [];

    private string $stdout = '';

    private string $stderr = '';

    private int $openStreams = 0;

    private ?string $timeoutTimer = null;

    /**
     * @param list<string>               $command
     * @param string|null                $stdin         payload written to the child's stdin, which is
     *                                                  then closed (e.g. a patch for `git apply -`);
     *                                                  null attaches /dev/null — never the parent's
     *                                                  terminal, whose raw-mode keystrokes belong to
     *                                                  the editor, not to a child that prompts
     * @param bool                       $captureStderr drain stderr into errorOutput() instead of /dev/null
     * @param array<string, string>|null $env           the child's full environment; null inherits the parent's
     * @param float|null                 $timeout       deadline in seconds; on expiry the child is
     *                                                  cancelled and $onExit fires with exit code -1,
     *                                                  so a hung subprocess cannot pin its owner's
     *                                                  slot for the rest of the session
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
        private readonly ?string $stdin = null,
        private readonly bool $captureStderr = false,
        private readonly ?array $env = null,
        private readonly ?float $timeout = null,
    ) {}

    /**
     * Spawn the process and watch its pipes. Returns false when it could not
     * be spawned (no watcher installed, callbacks never fired).
     *
     * @param callable(int, string): void  $onExit  (exit code, full stdout) once every pipe hit EOF
     * @param (callable(string): void)|null $onChunk each stdout chunk as it arrives
     */
    public function start(callable $onExit, ?callable $onChunk = null): bool
    {
        $pipes = [];
        /** @var non-empty-string $cwd */
        $cwd = $this->cwd;
        $descriptors = [
            0 => null !== $this->stdin ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => $this->captureStderr ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
        ];
        // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return is handled below.
        $process = @\proc_open($this->command, $descriptors, $pipes, $cwd, ProcessEnvironment::withoutRestart($this->env ?? \getenv()));
        if (!\is_resource($process)) {
            // Reached whenever the binary is not on the PATH: the ARRAY form
            // of proc_open resolves the program itself and returns false when
            // posix_spawn cannot, where the string form would have handed the
            // miss to a shell and reported it later as exit 127. So callers
            // that need to tell "the tool is missing" from "the tool failed"
            // get that answer here, before anything is spawned.
            return false;
        }

        $this->process = $process;
        if (null !== $this->stdin) {
            /** @var array{0: resource, 1: resource} $pipes */
            $this->feed($pipes[0], $this->stdin);
        }
        /** @var array{1: resource, 2?: resource} $pipes */
        $this->openStreams = $this->captureStderr ? 2 : 1;
        $this->watch($pipes[1], $onExit, function (string $chunk) use ($onChunk): void {
            $this->stdout .= $chunk;
            if (null !== $onChunk) {
                $onChunk($chunk);
            }
        });
        if ($this->captureStderr && \array_key_exists(2, $pipes)) {
            $this->watch($pipes[2], $onExit, function (string $chunk): void {
                $this->stderr .= $chunk;
            });
        }
        if (null !== $this->timeout) {
            $this->timeoutTimer = EventLoop::delay($this->timeout, function () use ($onExit): void {
                $this->timeoutTimer = null;
                $this->cancel();
                $onExit(-1, $this->stdout);
            });
        }

        return true;
    }

    /**
     * Write $payload into the child's stdin without blocking, closing the pipe
     * once it has all been handed over.
     *
     * A single `fwrite` only works while the payload fits the OS pipe buffer
     * (64 KB on macOS and Linux) — beyond that the write blocks until the child
     * drains it, which on a single event loop means the whole IDE stalls waiting
     * for a process that may itself be waiting on us. Feeding it from an
     * `onWritable` watcher keeps arbitrarily large payloads (a whole source file
     * handed to an out-of-process highlighter) off the critical path.
     *
     * @param resource $stream
     */
    private function feed($stream, string $payload): void
    {
        \stream_set_blocking($stream, enable: false);
        $offset = 0;
        $this->watchers[] = EventLoop::onWritable($stream, function (string $watcherId) use ($stream, $payload, &$offset): void {
            // @mago-expect lint:no-error-control-operator -- writing to a child that already exited raises an unavoidable EPIPE warning no is_resource guard prevents.
            $written = \is_resource($stream) ? @\fwrite($stream, \substr($payload, $offset)) : false;
            if (\is_int($written)) {
                $offset += $written;
            }

            if (false !== $written && $offset < \strlen($payload)) {
                return; // the pipe is full; resume when it drains
            }

            EventLoop::cancel($watcherId);
            $this->watchers = \array_values(\array_filter($this->watchers, static fn (string $id): bool => $id !== $watcherId));
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        });
    }

    /**
     * Drain one pipe; the last pipe to hit EOF closes the process and fires
     * $onExit exactly once.
     *
     * @param resource                    $stream
     * @param callable(int, string): void $onExit
     * @param \Closure(string): void      $accumulate
     */
    private function watch($stream, callable $onExit, \Closure $accumulate): void
    {
        \stream_set_blocking($stream, enable: false);
        $this->watchers[] = EventLoop::onReadable($stream, function (string $watcherId) use ($stream, $onExit, $accumulate): void {
            $chunk = \is_resource($stream) ? \fread($stream, length: 65_536) : false;
            if (\is_string($chunk) && '' !== $chunk) {
                $accumulate($chunk);

                return;
            }
            if (\feof($stream)) {
                EventLoop::cancel($watcherId);
                $this->watchers = \array_values(\array_filter($this->watchers, static fn (string $id): bool => $id !== $watcherId));
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
                if (--$this->openStreams > 0) {
                    return; // the other pipe is still draining
                }
                $this->cancelTimeout();
                // cancel() also cancels the watchers, so the process is still
                // owned (a resource) whenever this EOF path runs.
                /** @var resource $process */
                $process = $this->process;
                $this->process = null;
                $this->closeWhenDead($process, function (int $code) use ($onExit): void {
                    $onExit($code, $this->stdout);
                });
            }
        });
    }

    /**
     * @param resource                    $process
     * @param (\Closure(int): void)|null $onReaped receives proc_get_status's exit code
     */
    private function closeWhenDead($process, ?\Closure $onReaped = null): void
    {
        ProcessReaper::closeWhenDead($process, $onReaped);
    }

    private function cancelTimeout(): void
    {
        if (null !== $this->timeoutTimer) {
            EventLoop::cancel($this->timeoutTimer);
            $this->timeoutTimer = null;
        }
    }

    /** All stdout accumulated so far (complete once $onExit has fired). */
    public function output(): string
    {
        return $this->stdout;
    }

    /** All captured stderr (complete once $onExit has fired; empty unless $captureStderr). */
    public function errorOutput(): string
    {
        return $this->stderr;
    }

    /** Stop watching and terminate the child; safe after exit and idempotent. */
    public function cancel(): void
    {
        foreach ($this->watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        $this->watchers = [];
        $this->cancelTimeout();
        if (\is_resource($this->process)) {
            ProcessReaper::terminate($this->process);
        }
        $this->process = null;
    }
}
