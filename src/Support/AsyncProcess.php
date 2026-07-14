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
    /** @var resource|null */
    private $process;

    /** @var list<string> ids of the active pipe watchers */
    private array $watchers = [];

    private string $stdout = '';

    private string $stderr = '';

    private int $openStreams = 0;

    /**
     * @param list<string>               $command
     * @param string|null                $stdin         payload written to the child's stdin, which is
     *                                                  then closed (e.g. a patch for `git apply -`);
     *                                                  null leaves stdin unattached
     * @param bool                       $captureStderr drain stderr into errorOutput() instead of /dev/null
     * @param array<string, string>|null $env           the child's full environment; null inherits the parent's
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
        private readonly ?string $stdin = null,
        private readonly bool $captureStderr = false,
        private readonly ?array $env = null,
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
        $descriptors = [1 => ['pipe', 'w'], 2 => $this->captureStderr ? ['pipe', 'w'] : ['file', '/dev/null', 'w']];
        if (null !== $this->stdin) {
            $descriptors[0] = ['pipe', 'r'];
        }
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd, $this->env);
        if (!\is_resource($process)) {
            // Defensive: proc_open spawns even unusable binaries (exec
            // failure surfaces later as an exit code), so this is unreachable
            // in tests.
            // @codeCoverageIgnoreStart
            return false;

            // @codeCoverageIgnoreEnd
        }

        $this->process = $process;
        if (null !== $this->stdin) {
            // Write-and-close before watching stdout. Payloads here are patch-
            // sized (well under the pipe buffer), so a blocking write cannot
            // stall the loop the way streaming would.
            /** @var array{0: resource, 1: resource} $pipes */
            @fwrite($pipes[0], $this->stdin);
            @fclose($pipes[0]);
        }
        /** @var array{1: resource, 2?: resource} $pipes */
        $this->openStreams = $this->captureStderr ? 2 : 1;
        $this->watch($pipes[1], $onExit, function (string $chunk) use ($onChunk): void {
            $this->stdout .= $chunk;
            if (null !== $onChunk) {
                $onChunk($chunk);
            }
        });
        if ($this->captureStderr && isset($pipes[2])) {
            $this->watch($pipes[2], $onExit, function (string $chunk): void {
                $this->stderr .= $chunk;
            });
        }

        return true;
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
        stream_set_blocking($stream, false);
        $this->watchers[] = EventLoop::onReadable($stream, function (string $watcherId) use ($stream, $onExit, $accumulate): void {
            $chunk = @fread($stream, 65536);
            if (\is_string($chunk) && '' !== $chunk) {
                $accumulate($chunk);

                return;
            }
            if (feof($stream)) {
                EventLoop::cancel($watcherId);
                $this->watchers = array_values(array_filter($this->watchers, static fn (string $id): bool => $id !== $watcherId));
                @fclose($stream);
                if (--$this->openStreams > 0) {
                    return; // the other pipe is still draining
                }
                // cancel() also cancels the watchers, so the process is still
                // owned (a resource) whenever this EOF path runs.
                /** @var resource $process */
                $process = $this->process;
                $this->process = null;
                $onExit(@proc_close($process), $this->stdout);
            }
        });
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
        if (\is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
    }
}
