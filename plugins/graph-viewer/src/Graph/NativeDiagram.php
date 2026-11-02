<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Render\Format;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A diagram drawn by viz in PHP, for an engine whose tool is not installed: the
 * same PNG bytes and exports, sliced over the event loop, latest request wins.
 * What the native side does not draw is refused with the reason, which is the
 * error the engine reports.
 */
final readonly class NativeDiagram
{
    /** @param int $baseDpi the tool's own 1:1, which the caller's dpi is relative to */
    public function __construct(
        private Pipeline $pipeline,
        private int $baseDpi,
        private MermaidTask $renderTask = new MermaidTask(),
        private MermaidTask $exportTask = new MermaidTask(),
        private Filesystem $files = new Filesystem(),
    ) {}

    /**
     * @param \Closure(string): void $onPng
     * @param \Closure(string): void $onError
     */
    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        // viz draws its 1:1 at 96 dpi; the viewer asks in the tool's own scale.
        $native = \max(1, \intdiv($dpi * PngBackend::BASE_DPI, $this->baseDpi));
        $this->renderTask->run(new NativeRenderer($this->pipeline)->image($source, Format::Png, $native), $onPng, $onError);
    }

    /**
     * Render $source to $outPath, written only once the render is whole, so a
     * failed export leaves an earlier one intact.
     *
     * @param \Closure(?string): void $onDone null on success, the error text on failure
     */
    public function export(string $source, Format $format, string $outPath, \Closure $onDone): void
    {
        $this->exportTask->run(
            new NativeRenderer($this->pipeline)->image($source, $format),
            function (string $bytes) use ($outPath, $onDone): void {
                try {
                    $this->files->dumpFile($outPath, $bytes);
                } catch (IOExceptionInterface $exception) {
                    $onDone($exception->getMessage());

                    return;
                }
                $onDone(null);
            },
            $onDone,
        );
    }

    public function cancel(): void
    {
        $this->renderTask->cancel();
        $this->exportTask->cancel();
    }
}
