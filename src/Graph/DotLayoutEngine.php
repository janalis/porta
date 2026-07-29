<?php

declare(strict_types=1);

namespace Porta\Graph;

use Porta\Support\AsyncProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The Graphviz-backed {@see LayoutEngine}: `dot -Tjson0` as an async child
 * process, DOT fed via stdin (the onWritable feeder keeps large documents off
 * the critical path — no temp file). Success is keyed on the EXIT CODE, never
 * on stderr presence: dot writes warnings there and still exits 0, and stderr
 * must be captured regardless or a warning-spewing dot blocks on a full pipe.
 *
 * One layout slot, latest wins: a new layout() cancels the in-flight process
 * (the TodoScanner single-slot precedent), so a stale answer can never repaint
 * over a newer graph. Pathological output sizes are bounded upstream — the
 * viewer refuses DOT documents over {@see DotFiles::MAX_BYTES} — and by PHP's
 * own memory limit, so no separate output cap is imposed here.
 */
final class DotLayoutEngine implements LayoutEngine
{
    private ?AsyncProcess $running = null;

    private ?AsyncProcess $exporting = null;

    public function __construct(
        private readonly string $rootDir,
        // The "Graphviz dot path" setting; '' resolves to null (auto-discover).
        #[Autowire('%env(default::PORTA_DOT_PATH)%')]
        private readonly ?string $dotPath = null,
    ) {}

    public function binary(): ?string
    {
        // An explicit path (the dotPath setting) wins over discovery, the gitPath pattern.
        if (null !== $this->dotPath && '' !== $this->dotPath) {
            return $this->dotPath;
        }

        return new ExecutableFinder()->find('dot');
    }

    public function layout(string $dot, \Closure $onDone, \Closure $onError): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $onError('graphviz not found');

            return;
        }
        $this->running?->cancel();
        $process = new AsyncProcess([$binary, '-Tjson0'], $this->rootDir, stdin: $dot, captureStderr: true);
        $this->running = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $onDone, $onError): void {
            if ($this->running === $process) {
                $this->running = null;
            }
            if (0 !== $code) {
                $stderr = trim($process->errorOutput());
                $onError('' !== $stderr ? $stderr : "dot failed (exit {$code})");

                return;
            }
            try {
                $onDone(DotJsonParser::parse($stdout));
            } catch (GraphParseException $e) {
                $onError($e->getMessage());
            }
        });
        if (!$started) {
            // proc_open spawns even unusable binaries (exec failure surfaces
            // as an exit code), so this is unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onError('could not start dot');

            // @codeCoverageIgnoreEnd
        }
    }

    public function export(string $dot, GraphExportFormat $format, string $outPath, \Closure $onDone): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $onDone('graphviz not found');

            return;
        }
        $this->exporting?->cancel();
        $process = new AsyncProcess([$binary, $format->flag(), '-o', $outPath], $this->rootDir, stdin: $dot, captureStderr: true);
        $this->exporting = $process;
        $started = $process->start(function (int $code) use ($process, $onDone): void {
            if ($this->exporting === $process) {
                $this->exporting = null;
            }
            if (0 === $code) {
                $onDone(null);

                return;
            }
            $stderr = trim($process->errorOutput());
            $onDone('' !== $stderr ? $stderr : "dot failed (exit {$code})");
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $this->exporting = null;
            $onDone('could not start dot');

            // @codeCoverageIgnoreEnd
        }
    }

    /** Stop any in-flight processes (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->running?->cancel();
        $this->running = null;
        $this->exporting?->cancel();
        $this->exporting = null;
    }
}
