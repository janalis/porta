<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\GraphViewer\Graph\DiagramEngine;
use Porta\GraphViewer\Graph\DotLayoutEngine;
use Porta\GraphViewer\Graph\MermaidEngine;
use Porta\GraphViewer\Graph\PlantUmlEngine;
use Porta\GraphViewer\Mermaid\MermaidCliPicture;
use Porta\GraphViewer\Mermaid\MermaidCliRenderer;
use Porta\GraphViewer\Mermaid\MermaidSvgRasterizer;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceResult;

/** Each block owns an engine and cancellation handle; no preview slot is shared. */
final readonly class MarkdownFenceRenderer
{
    /** @var \Closure(): NativeMermaidRenderer each block owns an independent cancellation slot */
    private \Closure $nativeFactory;

    /**
     * @param \Closure(string): (bool|int|string) $setting
     * @param \Closure(string): string $translate
     * @param null|(\Closure(): NativeMermaidRenderer) $nativeFactory
     * @param null|(\Closure(string, string, FenceResult): list<FenceAction>) $actionFactory
     */
    public function __construct(
        private \Closure $setting,
        private \Closure $translate,
        ?\Closure $nativeFactory = null,
        private ?\Closure $actionFactory = null,
        private ?string $browserConfig = null,
    ) {
        $this->nativeFactory = $nativeFactory ?? static fn (): NativeMermaidRenderer => new NativeMermaidRenderer();
    }

    /**
     * @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function __invoke(string $language, string $source, string $path, int $width, \Closure $success, \Closure $error): \Closure
    {
        if ('mermaid' === $language) {
            return $this->mermaid($source, $path, $width, $success, $error);
        }
        $engine = $this->engine($language, \dirname($path));
        $engine->render($source, $engine->baseDpi(), fn (string $bytes) => $this->accept($bytes, $success, $error), fn (string $message) => $error(($this->translate)($message)));

        return $engine->cancel(...);
    }

    /** @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    private function mermaid(string $source, string $path, int $width, \Closure $success, \Closure $error): \Closure
    {
        $native = ($this->nativeFactory)();
        $accept = function (FenceResult $result) use ($source, $path, $success): void {
            $actions = null === $this->actionFactory ? [] : ($this->actionFactory)($source, $path, $result);
            $success($result->interactive($result->elements, $actions));
        };
        if ('cells' === ($this->setting)('renderMode')) {
            return $native->rows($source, $width, $accept, $error);
        }
        $engine = new MermaidEngine(fn (): ?string => $this->path('mermaidPath'), browserConfig: $this->browserConfig);
        $cli = new MermaidCliRenderer($engine, new MermaidSvgRasterizer(\dirname($path), $engine->binary(...), browserConfig: $this->browserConfig));
        $fallback = function (string $message) use ($source, $width, $accept, $error, $native): void {
            $translated = ($this->translate)($message);
            $native->preview(
                $source,
                $width,
                static fn (FenceResult $result) => $accept(
                    (null !== $result->png
                        ? FenceResult::png($result->png, error: $translated)
                        : FenceResult::rows($result->rows, $result->origins, error: $translated.' · '.(string) $result->error))
                        ->interactive($result->elements),
                ),
                static fn (string $message) => $error($translated.' · '.$message),
            );
        };
        $cli->render(
            $source,
            $engine->baseDpi(),
            fn (MermaidCliPicture $picture) => $this->accept($picture->png, static fn (FenceResult $result) => $accept($result->interactive($picture->elements)), $fallback),
            $fallback,
        );

        return static function () use ($cli, $native): void {
            $cli->cancel();
            $native->cancel();
        };
    }

    /** @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     */
    private function accept(string $bytes, \Closure $success, \Closure $error): void
    {
        try {
            $result = FenceResult::png($bytes);
        } catch (\InvalidArgumentException) {
            $error(($this->translate)('Cannot display diagram PNG'));

            return;
        }
        $success($result);
    }

    private function engine(string $language, string $directory): DiagramEngine
    {
        return match ($language) {
            'dot', 'graphviz' => new DotLayoutEngine($directory, fn (): ?string => $this->path('dotPath')),
            'plantuml', 'puml' => new PlantUmlEngine($directory, fn (): ?string => $this->path('plantumlPath')),
            default => throw new \InvalidArgumentException('Unsupported diagram fence: '.$language),
        };
    }

    private function path(string $key): ?string
    {
        $value = \trim((string) ($this->setting)($key));

        return '' === $value ? null : $value;
    }
}
