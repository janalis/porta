<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * A one-shot child process whose stdout is drained asynchronously on the
 * Revolt event loop: spawn, accumulate output, report the exit code at EOF,
 * cancel mid-flight. The single home for the proc_open → non-blocking pipe →
 * onReadable pattern previously copy-pasted by the project search and the git
 * integration. Stderr is discarded.
 *
 * Long-lived duplex transports (the LSP client, the PTY) have their own
 * plumbing — this is deliberately only the run-to-completion shape.
 */
final class AsyncProcess
{
    /** @var resource|null */
    private $process;
    private ?string $watcher = null;
    private string $stdout = '';

    /**
     * @param list<string> $command
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
    ) {}

    /**
     * Spawn the process and watch its stdout. Returns false when it could not
     * be spawned (no watcher installed, callbacks never fired).
     *
     * @param callable(int, string): void  $onExit  (exit code, full stdout) at EOF
     * @param (callable(string): void)|null $onChunk each stdout chunk as it arrives
     */
    public function start(callable $onExit, ?callable $onChunk = null): bool
    {
        $pipes = [];
        /** @var non-empty-string $cwd */
        $cwd = $this->cwd;
        $process = @proc_open($this->command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd);
        if (!\is_resource($process)) {
            // Defensive: proc_open spawns even unusable binaries (exec
            // failure surfaces later as an exit code), so this is unreachable
            // in tests.
            // @codeCoverageIgnoreStart
            return false;

            // @codeCoverageIgnoreEnd
        }

        $this->process = $process;
        /** @var array{1: resource} $pipes */
        $stdout = $pipes[1];
        stream_set_blocking($stdout, false);

        $this->watcher = EventLoop::onReadable($stdout, function (string $watcherId) use ($stdout, $onExit, $onChunk): void {
            $chunk = @fread($stdout, 65536);
            if (\is_string($chunk) && '' !== $chunk) {
                $this->stdout .= $chunk;
                if (null !== $onChunk) {
                    $onChunk($chunk);
                }

                return;
            }
            if (feof($stdout)) {
                EventLoop::cancel($watcherId);
                $this->watcher = null;
                @fclose($stdout);
                // cancel() also cancels the watcher, so the process is still
                // owned (a resource) whenever this EOF path runs.
                /** @var resource $process */
                $process = $this->process;
                $this->process = null;
                $onExit(@proc_close($process), $this->stdout);
            }
        });

        return true;
    }

    /** All stdout accumulated so far (complete once $onExit has fired). */
    public function output(): string
    {
        return $this->stdout;
    }

    /** Stop watching and terminate the child; safe after exit and idempotent. */
    public function cancel(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (\is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
    }
}
