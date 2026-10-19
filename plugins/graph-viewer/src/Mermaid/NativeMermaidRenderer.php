<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use Porta\Help\Fence\FenceResult;
use Porta\Ui\Theme\Styles;

/**
 * The library's native pipeline, sliced over the event loop: parsing, layout and
 * row generation all happen off paint, a step at a time, latest request wins.
 */
final readonly class NativeMermaidRenderer
{
    public function __construct(
        private MermaidTask $task = new MermaidTask(),
        private MermaidPipeline $pipeline = new MermaidPipeline(),
    ) {}

    /** @param \Closure(MermaidScene): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function render(string $source, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->pipeline->scene($source), $success, $error);
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
    public function export(string $source, Format $format, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->pipeline->export($source, $format), $success, $error);
    }

    /**
     * @param \Closure(string): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function raster(MermaidScene $scene, \Closure $success, \Closure $error): \Closure
    {
        return $this->task->run($this->pipeline->image($scene, Format::Png), $success, $error);
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
        $work = $this->pipeline->scene($source);
        yield from $work;
        $scene = $work->getReturn();
        $metadata = MermaidFenceElements::fromScene($scene);
        yield from $metadata;
        $elements = $metadata->getReturn();
        try {
            $image = $this->pipeline->image($scene, Format::Png);
            yield from $image;

            return FenceResult::png($image->getReturn())->interactive($elements);
        } catch (\InvalidArgumentException $exception) {
            $rows = MermaidSceneRows::rows($scene, $width, Styles::dark());
            yield from $rows;
            $result = $rows->getReturn();

            return FenceResult::rows($result->rows, $result->origins, $exception->getMessage())->interactive($elements);
        }
    }

    /** @return \Generator<int, null, mixed, FenceResult> */
    private function rowWork(string $source, int $width): \Generator
    {
        $work = $this->pipeline->scene($source);
        yield from $work;
        $scene = $work->getReturn();

        $rows = MermaidSceneRows::rows($scene, \max(1, $width), Styles::dark());
        yield from $rows;

        $metadata = MermaidFenceElements::fromScene($scene);
        yield from $metadata;

        return $rows->getReturn()->interactive($metadata->getReturn());
    }
}
