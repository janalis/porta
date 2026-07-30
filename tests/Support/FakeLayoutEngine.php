<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Graph\DiagramEngine;
use Porta\Graph\GraphExportFormat;
use Porta\Graph\GraphLayout;
use Porta\Graph\LayoutEngine;

/**
 * A scriptable {@see LayoutEngine} for widget tests: delivers a queued layout
 * or bitmap (or error) synchronously, or holds the callbacks for manual delivery
 * when `$deferred` — no dot binary, no subprocess, no event loop.
 */
final class FakeLayoutEngine implements DiagramEngine, LayoutEngine
{
    public int $layoutCalls = 0;

    /** @var list<array{string, GraphExportFormat, string}> */
    public array $exports = [];

    public ?string $binary = '/fake/dot';

    /** Graphviz's 1:1; a PlantUML fake would answer 96. */
    public int $baseDpi = 72;

    /** The next layout() outcome: a layout, an error string, or null to answer nothing. */
    public GraphLayout|string|null $nextResult = null;

    public bool $deferred = false;

    /** @var ?\Closure(GraphLayout): void the held onDone when $deferred */
    public ?\Closure $pendingDone = null;

    /** @var ?\Closure(string): void the held onError when $deferred */
    public ?\Closure $pendingError = null;

    /** @var list<int> the dpi of every render() call, in order */
    public array $renderDpis = [];

    /** The next render() outcome: PNG bytes, an error string, or null to answer nothing. */
    public ?string $nextPng = null;

    public bool $pngIsError = false;

    /** @var ?\Closure(string): void the held render onPng when $deferred */
    public ?\Closure $pendingPng = null;

    /** @var ?\Closure(string): void the held render onError when $deferred */
    public ?\Closure $pendingPngError = null;

    public function binary(): ?string
    {
        return $this->binary;
    }

    public function baseDpi(): int
    {
        return $this->baseDpi;
    }

    public function layout(string $dot, \Closure $onDone, \Closure $onError): void
    {
        ++$this->layoutCalls;
        if ($this->deferred) {
            $this->pendingDone = $onDone;
            $this->pendingError = $onError;

            return;
        }
        if ($this->nextResult instanceof GraphLayout) {
            $onDone($this->nextResult);

            return;
        }
        if (\is_string($this->nextResult)) {
            $onError($this->nextResult);
        }
    }

    public function render(string $dot, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $this->renderDpis[] = $dpi;
        if ($this->deferred) {
            $this->pendingPng = $onPng;
            $this->pendingPngError = $onError;

            return;
        }
        if (null === $this->nextPng) {
            return;
        }
        $this->pngIsError ? $onError($this->nextPng) : $onPng($this->nextPng);
    }

    public function export(string $dot, GraphExportFormat $format, string $outPath, \Closure $onDone): void
    {
        $this->exports[] = [$dot, $format, $outPath];
        $onDone(null);
    }
}
