<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The Graphviz-backed {@see LayoutEngine}: `dot -Tjson0` for geometry and
 * `dot -Tpng` for the picture, both as async child processes with DOT fed via
 * stdin (the onWritable feeder keeps large documents off the critical path — no
 * temp file, and the PNG comes back the same way). Success is keyed on the EXIT
 * CODE, never on stderr presence: dot writes warnings there and still exits 0,
 * and stderr must be captured regardless or a warning-spewing dot blocks on a
 * full pipe.
 *
 * Three independent slots (layout / render / export), each latest wins: a new
 * call cancels that slot's in-flight process (the TodoScanner single-slot
 * precedent), so a stale answer can never repaint over a newer graph, and a
 * zoom-driven re-render cannot cancel the geometry it is about to be drawn with. Pathological output sizes are bounded upstream — the
 * viewer refuses DOT documents over {@see DotFiles::MAX_BYTES} — and by PHP's
 * own memory limit, so no separate output cap is imposed here.
 */
final class DotLayoutEngine implements LayoutEngine
{
    private ?AsyncProcess $running = null;

    private ?AsyncProcess $exporting = null;

    private ?AsyncProcess $rendering = null;

    public function __construct(
        private readonly string $rootDir,
        // The "Graphviz dot path" setting; '' resolves to null (auto-discover).
        private readonly ?string $dotPath = null,
    ) {}

    public function baseDpi(): int
    {
        // 72 dpi is one PostScript point per pixel, which is what makes a dot
        // bitmap share its coordinates with the layout it was drawn from.
        return 72;
    }

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

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $onError('graphviz not found');

            return;
        }
        $this->rendering?->cancel();
        // -Gpad=0 is what makes the pixel grid line up with the layout: dot pads
        // a bitmap by 4 points a side by default (measured — `margin` is NOT the
        // knob, it leaves the canvas untouched), which would shift every
        // coordinate the viewer hit-tests against. With pad 0 the PNG is exactly
        // ceil(bb) × dpi/72 pixels.
        $process = new AsyncProcess([$binary, '-Tpng', '-Gdpi='.$dpi, '-Gpad=0'], $this->rootDir, stdin: $source, captureStderr: true);
        $this->rendering = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $onPng, $onError): void {
            if ($this->rendering === $process) {
                $this->rendering = null;
            }
            if (0 !== $code) {
                // A dot built without libgd has no PNG support and says so here;
                // the viewer treats that as "fall back to the cell rendering".
                $stderr = trim($process->errorOutput());
                $onError('' !== $stderr ? $stderr : "dot failed (exit {$code})");

                return;
            }
            $onPng($stdout);
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $this->rendering = null;
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
        $this->rendering?->cancel();
        $this->rendering = null;
    }
}
