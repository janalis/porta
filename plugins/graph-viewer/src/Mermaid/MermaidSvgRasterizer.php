<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\MermaidEngine;
use Porta\Support\SingleSlotProcess;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/** Rasterize the CLI SVG in its installed browser, preserving HTML/math labels and the exact snapshot. */
final class MermaidSvgRasterizer
{
    public const string SCRIPT = __DIR__.'/../../resources/mermaid-raster.mjs';

    private ?string $directory = null;

    /** @param (\Closure(): ?string)|string|null $cliPath
     * @param (\Closure(): ?string)|string|null $nodePath
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly \Closure|string|null $cliPath = null,
        private readonly \Closure|string|null $nodePath = null,
        private readonly SingleSlotProcess $slot = new SingleSlotProcess(),
        private readonly Filesystem $files = new Filesystem(),
        private readonly ExecutableFinder $finder = new ExecutableFinder(),
        private readonly string $script = self::SCRIPT,
        private readonly ?string $browserConfig = null,
    ) {}

    public function binary(): ?string
    {
        return null === $this->resolve($this->cliPath, 'mmdc') ? null : $this->resolve($this->nodePath, 'node');
    }

    /** @param \Closure(string): void $onPng
     * @param \Closure(string): void $onError
     */
    public function rasterize(string $svg, ?float $scale, \Closure $onPng, \Closure $onError): void
    {
        $this->cancel();
        $node = $this->resolve($this->nodePath, 'node');
        $cli = $this->resolve($this->cliPath, 'mmdc');
        $factor = $scale ?? 1.0;
        $invalidScale = !\is_finite($factor) || $factor <= 0;
        if (null === $node || null === $cli || $invalidScale) {
            $onError($invalidScale ? 'Invalid SVG raster scale' : 'The Mermaid CLI browser is unavailable');

            return;
        }
        $directory = $this->prepare($svg, $onError);
        if (null === $directory) {
            return;
        }
        $this->slot->run(
            // The project root is the page's base, said outright: a label's
            // relative image resolves there, not where the browser runs.
            [$node, $directory.'/raster.mjs', $cli, $directory.'/diagram.svg', (string) $factor, $this->rootDir, ...(null === $this->browserConfig ? [] : [$this->browserConfig])],
            // Never the project: the browser it starts is puppeteer's, which
            // RUNS a `.puppeteerrc.cjs` found in the working directory.
            $directory,
            '',
            'the Mermaid SVG browser',
            function (string $png) use ($onPng): void {
                $this->cleanup();
                $onPng($png);
            },
            function (string $error) use ($onError): void {
                $this->cleanup();
                $onError($error);
            },
        );
    }

    /** @param \Closure(string): void $onError */
    private function prepare(string $svg, \Closure $onError): ?string
    {
        $this->directory = \sys_get_temp_dir().'/porta-mermaid-svg-'.\bin2hex(\random_bytes(16));
        try {
            $this->files->mkdir($this->directory, 0o700);
            $this->files->dumpFile($this->directory.'/'.MermaidEngine::CONFIG_STOP, '{}'); // its browser is puppeteer's too
            $this->files->dumpFile($this->directory.'/diagram.svg', $svg);
            $this->files->dumpFile($this->directory.'/raster.mjs', $this->files->readFile($this->script));
        } catch (IOExceptionInterface $exception) {
            $this->cleanup();
            $onError($exception->getMessage());

            return null;
        }

        return $this->directory;
    }

    private function resolve(\Closure|string|null $path, string $fallback): ?string
    {
        $configured = \trim((string) ($path instanceof \Closure ? $path() : $path));

        return '' === $configured ? $this->finder->find($fallback) : $this->finder->find($configured) ?? $configured;
    }

    private function cleanup(): void
    {
        if (null !== $this->directory) {
            $this->files->remove($this->directory);
            $this->directory = null;
        }
    }

    public function cancel(): void
    {
        $this->slot->cancel();
        $this->cleanup();
    }
}
