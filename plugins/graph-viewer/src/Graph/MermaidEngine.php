<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Porta\Support\SingleSlotProcess;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/** Local mmdc, with a private output directory per request and live tool discovery. */
final class MermaidEngine implements DiagramEngine
{
    private ?string $directory = null;

    /** @param null|(\Closure(): ?string)|string $toolPath resolved for each render */
    public function __construct(
        private readonly string $rootDir,
        private readonly \Closure|string|null $toolPath = null,
        private readonly SingleSlotProcess $slot = new SingleSlotProcess(),
        private readonly Filesystem $files = new Filesystem(),
        private readonly ExecutableFinder $finder = new ExecutableFinder(),
    ) {}

    public function baseDpi(): int
    {
        return 96;
    }

    public function binary(): ?string
    {
        $configured = $this->toolPath instanceof \Closure ? ($this->toolPath)() : $this->toolPath;
        $path = \trim((string) $configured);

        return '' !== $path ? $path : $this->finder->find('mmdc');
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $this->start($source, $dpi, GraphExportFormat::Png, $onPng, $onError);
    }

    /** @param \Closure(string): void $onSvg
     * @param \Closure(string): void $onError
     */
    public function renderSvg(string $source, \Closure $onSvg, \Closure $onError): void
    {
        $this->start($source, $this->baseDpi(), GraphExportFormat::Svg, $onSvg, $onError);
    }

    /** @param \Closure(string): void $onBytes
     * @param \Closure(string): void $onError
     */
    private function start(string $source, int $dpi, GraphExportFormat $format, \Closure $onBytes, \Closure $onError): void
    {
        $this->cancel();
        $tool = $this->binary();
        if (null === $tool) {
            $onError('mmdc not found — install mermaid-cli, or set the "mermaidPath" setting');

            return;
        }
        $directory = \sys_get_temp_dir().'/porta-mermaid-'.\bin2hex(\random_bytes(16));
        $this->files->mkdir($directory, 0o700);
        $this->directory = $directory;
        $output = $directory.'/diagram.'.$format->extension();
        $this->slot->run(
            [$tool, '--quiet', '--input', '-', '--outputFormat', $format->extension(), '--output', $output, '--scale', (string) (\max(1, $dpi) / $this->baseDpi())],
            $this->rootDir,
            $source,
            'mmdc',
            function () use ($directory, $output, $format, $onBytes, $onError): void {
                if ($directory !== $this->directory) {
                    return;
                }
                $png = \is_file($output) ? (string) \file_get_contents($output) : '';
                $this->cleanup();
                if ('' === $png) {
                    $onError('mmdc produced no '.\strtoupper($format->extension()));

                    return;
                }
                $onBytes($png);
            },
            function (string $error) use ($directory, $onError): void {
                if ($directory !== $this->directory) {
                    return;
                }
                $this->cleanup();
                $onError($error);
            },
        );
    }

    /** Remove the parent too, so a cancelled child cannot later recreate its output. */
    private function cleanup(): void
    {
        if (null === $this->directory) {
            return;
        }
        foreach (GraphExportFormat::cases() as $format) {
            $output = $this->directory.'/diagram.'.$format->extension();
            if (\is_file($output)) {
                \unlink($output);
            }
        }
        \rmdir($this->directory);
        $this->directory = null;
    }

    public function cancel(): void
    {
        $this->slot->cancel();
        $this->cleanup();
    }
}
