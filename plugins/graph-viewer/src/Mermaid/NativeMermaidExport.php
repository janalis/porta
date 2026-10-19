<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphExportFormat;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Publish only a fully rendered diagram; a failed or cancelled render leaves the old export intact. */
final readonly class NativeMermaidExport
{
    public function __construct(
        private NativeMermaidRenderer $renderer = new NativeMermaidRenderer(),
        private Filesystem $files = new Filesystem(),
    ) {}

    /** @param \Closure(?string): void $done */
    public function export(string $source, GraphExportFormat $format, string $path, \Closure $done): void
    {
        $this->renderer->export(
            $source,
            $format,
            function (string $bytes) use ($path, $done): void {
                try {
                    $this->files->dumpFile($path, $bytes);
                } catch (IOExceptionInterface $exception) {
                    $done($exception->getMessage());

                    return;
                }
                $done(null);
            },
            $done,
        );
    }

    public function cancel(): void
    {
        $this->renderer->cancel();
    }
}
