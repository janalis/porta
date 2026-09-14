<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * One child process at a time, latest wins: starting a new one cancels
 * whatever this slot was still running, so a stale answer can never repaint
 * over a newer one. Success is keyed on the EXIT CODE, never on stderr
 * presence — a well-behaved converter can warn there and still succeed — but
 * stderr is drained regardless, or a chatty one blocks on a full pipe. The
 * bookkeeping {@see \Porta\GraphViewer\Graph\DotLayoutEngine},
 * {@see \Porta\GraphViewer\Graph\PlantUmlEngine} and
 * {@see \Porta\ImageViewer\SvgRasterizer} all need identically.
 */
final class SingleSlotProcess
{
    private ?AsyncProcess $current = null;

    /**
     * Cancel whatever's in this slot, start $argv fed $stdin, and report:
     * $onSuccess($stdout) on exit 0, $onFailure($reason) otherwise — trimmed
     * stderr, or a "$label failed (exit N)" / "could not start $label"
     * fallback when stderr is empty.
     *
     * @param list<string>            $argv
     * @param \Closure(string): void  $onSuccess
     * @param \Closure(string): void  $onFailure
     */
    public function run(array $argv, string $rootDir, ?string $stdin, string $label, \Closure $onSuccess, \Closure $onFailure): void
    {
        $this->current?->cancel();
        $process = new AsyncProcess($argv, $rootDir, stdin: $stdin, captureStderr: true);
        $this->current = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $label, $onSuccess, $onFailure): void {
            if ($this->current === $process) {
                $this->current = null;
            }
            if (0 !== $code) {
                $stderr = \trim($process->errorOutput());
                $onFailure('' !== $stderr ? $stderr : "{$label} failed (exit {$code})");

                return;
            }
            $onSuccess($stdout);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries (exec failure surfaces
            // as an exit code), so this is unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->current = null;
            $onFailure("could not start {$label}");

            // @codeCoverageIgnoreEnd
        }
    }

    /** Stop the in-flight process, if any (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->current?->cancel();
        $this->current = null;
    }
}
