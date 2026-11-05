<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Janalis\Viz\Mermaid\MermaidCommand;
use Janalis\Viz\Render\Format;
use Porta\Support\Files;
use Porta\Support\SingleSlotProcess;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/** Local mmdc, with a private output directory per request and live tool discovery. */
final class MermaidEngine implements DiagramEngine
{
    /**
     * Puppeteer's launch options for a project not trusted: every request
     * through a proxy that does not answer, loopback included. Opening a
     * diagram fetched what its labels named (`<img src="http://…">`),
     * telling whoever wrote it that it was opened, and from where. mmdc's
     * own assets are intercepted, never fetched: the render still works.
     */
    public const string OFFLINE = __DIR__.'/../../resources/puppeteer-offline.json';

    /**
     * Written where the tool runs: puppeteer's config search starts in its
     * working directory and walks UP, running the first `.puppeteerrc.cjs`
     * it meets (driven with a canary in a parent). The render's directory is
     * this user's, but /tmp above it is anyone's on Linux: an empty config
     * here is found first, and the walk stops.
     */
    public const string CONFIG_STOP = '.puppeteerrc.json';

    private ?string $directory = null;

    /**
     * @param null|(\Closure(): ?string)|string $toolPath      resolved for each render
     * @param null|(\Closure(): ?string)|string $browserConfig resolved for each render too: a project trusted or restricted mid-session takes effect at the next one
     */
    public function __construct(
        private readonly \Closure|string|null $toolPath = null,
        private readonly SingleSlotProcess $slot = new SingleSlotProcess(),
        private readonly Filesystem $files = new Filesystem(),
        private readonly ExecutableFinder $finder = new ExecutableFinder(),
        private readonly \Closure|string|null $browserConfig = null,
    ) {}

    public function baseDpi(): int
    {
        return MermaidCommand::BASE_DPI;
    }

    public function binary(): ?string
    {
        $configured = $this->toolPath instanceof \Closure ? ($this->toolPath)() : $this->toolPath;
        $path = \trim((string) $configured);

        return '' !== $path ? $path : $this->finder->find('mmdc');
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $this->start($source, $dpi, Format::Png, $onPng, $onError);
    }

    /** @param \Closure(string): void $onSvg
     * @param \Closure(string): void $onError
     */
    public function renderSvg(string $source, \Closure $onSvg, \Closure $onError): void
    {
        $this->start($source, $this->baseDpi(), Format::Svg, $onSvg, $onError);
    }

    /** @param \Closure(string): void $onBytes
     * @param \Closure(string): void $onError
     */
    private function start(string $source, int $dpi, Format $format, \Closure $onBytes, \Closure $onError): void
    {
        $this->cancel();
        $tool = $this->binary();
        if (null === $tool) {
            $onError('mmdc not found — install mermaid-cli, or set the "mermaidPath" setting');

            return;
        }
        $directory = \sys_get_temp_dir().'/porta-mermaid-'.\bin2hex(\random_bytes(16));
        $this->files->mkdir($directory, 0o700);
        $this->files->dumpFile($directory.'/'.self::CONFIG_STOP, '{}');
        $this->directory = $directory;
        $output = $directory.'/diagram.'.$format->extension();
        $browserConfig = $this->browserConfig instanceof \Closure ? ($this->browserConfig)() : $this->browserConfig;
        $this->slot->run(
            null === $browserConfig
                ? MermaidCommand::render($tool, $format, $output, $dpi)
                : [...MermaidCommand::render($tool, $format, $output, $dpi), '--puppeteerConfigFile', $browserConfig],
            // Never the project: puppeteer loads a `.puppeteerrc.cjs` from the
            // working directory and RUNS it — a repository's own JavaScript,
            // on opening a diagram (driven with a canary). The render's private
            // directory holds nothing anyone else wrote.
            $directory,
            $source,
            'mmdc',
            function () use ($directory, $output, $format, $onBytes, $onError): void {
                if ($directory !== $this->directory) {
                    return;
                }
                $png = Files::read($output);
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
        // Whole, as the rasterizer's is: mmdc runs in it now, and whatever it
        // leaves there is gone with the render, not a warning and a leak.
        $this->files->remove($this->directory);
        $this->directory = null;
    }

    public function cancel(): void
    {
        $this->slot->cancel();
        $this->cleanup();
    }
}
