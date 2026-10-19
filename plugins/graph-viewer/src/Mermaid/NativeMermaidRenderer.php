<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\Help\Fence\FenceResult;
use Porta\Ui\Theme\Styles;

/** A hand-built native request: parsing, layout and row generation all happen off paint. */
final readonly class NativeMermaidRenderer
{
    public function __construct(
        private MermaidTask $task = new MermaidTask(),
        private MermaidPng $raster = new MermaidPng(),
        private MermaidStyleSource $styles = new MermaidStyleSource(),
    ) {}

    /** @param \Closure(MermaidScene): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function render(string $source, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->scene($source), $success, $error);
    }

    /** @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function rows(string $source, int $width, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->rowWork($source, $width), $success, $error);
    }

    public function cancel(): void
    {
        $this->task->cancel();
    }

    /**
     * @param \Closure(string): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function export(string $source, GraphExportFormat $format, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->exportWork($source, $format), $success, $error);
    }

    /**
     * @param \Closure(string): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function raster(MermaidScene $scene, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->rasterWork($scene), $success, $error);
    }

    /**
     * @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function preview(string $source, int $width, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->previewWork($source, $width), $success, $error);
    }

    /** @return \Generator<int, null, mixed, FenceResult> */
    private function previewWork(string $source, int $width): \Generator
    {
        $work = $this->scene($source);
        yield from $work;
        $scene = $work->getReturn();
        $metadata = MermaidFenceElements::fromScene($scene);
        yield from $metadata;
        $elements = $metadata->getReturn();
        try {
            $image = $this->rasterWork($scene);
            yield from $image;

            return FenceResult::png($image->getReturn())->interactive($elements);
        } catch (\InvalidArgumentException $exception) {
            $rows = MermaidSceneRows::rows($scene, $width, Styles::dark());
            yield from $rows;
            $result = $rows->getReturn();

            return FenceResult::rows($result->rows, $result->origins, $exception->getMessage())->interactive($elements);
        }
    }

    /** @return \Generator<int, null, mixed, string> */
    private function rasterWork(MermaidScene $scene): \Generator
    {
        $drawing = MermaidDrawing::scene($scene);
        yield from $drawing;
        $image = $this->raster->render($drawing->getReturn());
        yield from $image;

        return $image->getReturn();
    }

    /** @return \Generator<int, null, mixed, string> */
    private function exportWork(string $source, GraphExportFormat $format): \Generator
    {
        $scene = $this->scene($source);
        yield from $scene;
        $drawing = MermaidDrawing::scene($scene->getReturn());
        yield from $drawing;
        $image = match ($format) {
            GraphExportFormat::Svg => MermaidSvg::render($drawing->getReturn()),
            GraphExportFormat::Png => $this->raster->render($drawing->getReturn()),
        };
        yield from $image;

        return $image->getReturn();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function scene(string $source): \Generator
    {
        $frontMatter = MermaidFrontMatter::parse(MermaidSource::unmarked($source));
        $preparation = MermaidInitialization::parse($frontMatter->source, $frontMatter->configuration);
        yield from $preparation;
        $initialization = $preparation->getReturn();
        $diagram = new NativeMermaidDiagram($initialization->source, $initialization->configuration, $this->styles);
        $authoredTitle = false;
        foreach ($diagram->statements() as $statement) {
            $authoredTitle = $diagram->isTitle($statement) || $authoredTitle;
            $diagram->accept($statement);
            yield null;
        }
        $layout = $diagram->layout();
        yield from $layout;

        if (null !== $frontMatter->title && !$authoredTitle && $diagram->supportsTitle) {
            $margin = $initialization->configuration->flowchart?->spacing('titleTopMargin', default: 1.0) ?? 1.0;
            $caption = MermaidTitle::apply($layout->getReturn(), $frontMatter->title, $margin);
            yield from $caption;

            return $caption->getReturn()->themed($diagram->palette);
        }

        return $layout->getReturn()->themed($diagram->palette);
    }

    /** @return \Generator<int, null, mixed, FenceResult> */
    private function rowWork(string $source, int $width): \Generator
    {
        $work = $this->scene($source);
        yield from $work;
        $scene = $work->getReturn();

        $rows = MermaidSceneRows::rows($scene, \max(1, $width), Styles::dark());
        yield from $rows;

        $metadata = MermaidFenceElements::fromScene($scene);
        yield from $metadata;

        return $rows->getReturn()->interactive($metadata->getReturn());
    }
}
