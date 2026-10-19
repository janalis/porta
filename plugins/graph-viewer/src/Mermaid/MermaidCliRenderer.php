<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use Porta\GraphViewer\Graph\MermaidEngine;
use Porta\Ui\Graphics\PngDimensions;

/** Rasterize one SVG and project native semantics onto it; retain CLI PNG fallback when the browser is unavailable. */
final class MermaidCliRenderer
{
    /** @var array{source: string, tool: string|null, svg: string}|null One bounded snapshot keeps zoom refinements on the same layout. */
    private ?array $snapshot = null;

    public function __construct(
        private readonly MermaidEngine $engine,
        private readonly MermaidSvgRasterizer $rasterizer,
        private readonly NativeMermaidRenderer $native = new NativeMermaidRenderer(),
        private readonly MermaidTask $mapping = new MermaidTask(),
    ) {}

    /** @param \Closure(MermaidCliPicture): void $success
     * @param \Closure(string): void $error
     */
    public function render(string $source, int $dpi, \Closure $success, \Closure $error): void
    {
        $this->cancel();
        if (null === $this->rasterizer->binary()) {
            $this->fallback($source, $dpi, $success, $error);

            return;
        }
        $tool = $this->engine->binary();
        if (null !== $this->snapshot && $source === $this->snapshot['source'] && $tool === $this->snapshot['tool']) {
            $this->convert($source, $this->snapshot['svg'], $dpi, $success, $error);

            return;
        }
        $this->engine->renderSvg(
            $source,
            function (string $svg) use ($source, $tool, $dpi, $success, $error): void {
                $this->snapshot = \strlen($svg) <= 4_194_304 ? ['source' => $source, 'tool' => $tool, 'svg' => $svg] : null;
                $this->convert($source, $svg, $dpi, $success, $error);
            },
            $error,
        );
    }

    /** @param \Closure(MermaidCliPicture): void $success
     * @param \Closure(string): void $error
     */
    private function convert(string $source, string $svg, int $dpi, \Closure $success, \Closure $error): void
    {
        $this->rasterizer->rasterize(
            $svg,
            \max(1, $dpi) / $this->engine->baseDpi(),
            fn (string $png) => $this->map($source, $svg, $png, $dpi, $success, $error),
            fn () => $this->fallback($source, $dpi, $success, $error),
        );
    }

    /** @param \Closure(MermaidCliPicture): void $success
     * @param \Closure(string): void $error
     */
    private function map(string $source, string $svg, string $png, int $dpi, \Closure $success, \Closure $error): void
    {
        $size = PngDimensions::read($png);
        if (null === $size) {
            $this->fallback($source, $dpi, $success, $error);

            return;
        }
        $ratio = $this->engine->baseDpi() / \max(1, $dpi);
        $unmapped = static fn () => $success(new MermaidCliPicture($png, scene: null));
        $this->native->render(
            $source,
            fn (MermaidScene $scene): \Closure => $this->mapping->run(
                $this->picture($svg, $scene, $png, $size[0] * $ratio, $size[1] * $ratio),
                $success,
                $unmapped,
            ),
            $unmapped,
        );
    }

    /** @return \Generator<int, null, mixed, MermaidCliPicture> */
    private function picture(string $svg, MermaidScene $source, string $png, float $width, float $height): \Generator
    {
        $work = MermaidSvgMap::project($svg, $source, $width, $height);
        yield from $work;

        $scene = $work->getReturn();
        $elements = [];
        if (null !== $scene) {
            $metadata = MermaidFenceElements::fromScene($scene);
            yield from $metadata;
            $elements = $metadata->getReturn();
        }

        return new MermaidCliPicture($png, $scene, $elements);
    }

    /** @param \Closure(MermaidCliPicture): void $success
     * @param \Closure(string): void $error
     */
    private function fallback(string $source, int $dpi, \Closure $success, \Closure $error): void
    {
        $this->engine->render($source, $dpi, static fn (string $png) => $success(new MermaidCliPicture($png, scene: null)), $error);
    }

    public function cancel(): void
    {
        $this->engine->cancel();
        $this->rasterizer->cancel();
        $this->native->cancel();
        $this->mapping->cancel();
    }
}
