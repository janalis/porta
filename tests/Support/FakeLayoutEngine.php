<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Graph\GraphExportFormat;
use Porta\Graph\GraphLayout;
use Porta\Graph\LayoutEngine;

/**
 * A scriptable {@see LayoutEngine} for widget tests: delivers a queued layout
 * (or error) synchronously, or holds the callbacks for manual delivery when
 * `$deferred` — no dot binary, no subprocess, no event loop.
 */
final class FakeLayoutEngine implements LayoutEngine
{
    public int $layoutCalls = 0;

    /** @var list<array{string, GraphExportFormat, string}> */
    public array $exports = [];

    public ?string $binary = '/fake/dot';

    /** The next layout() outcome: a layout, an error string, or null to answer nothing. */
    public GraphLayout|string|null $nextResult = null;

    public bool $deferred = false;

    /** @var ?\Closure(GraphLayout): void the held onDone when $deferred */
    public ?\Closure $pendingDone = null;

    /** @var ?\Closure(string): void the held onError when $deferred */
    public ?\Closure $pendingError = null;

    public function binary(): ?string
    {
        return $this->binary;
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

    public function export(string $dot, GraphExportFormat $format, string $outPath, \Closure $onDone): void
    {
        $this->exports[] = [$dot, $format, $outPath];
        $onDone(null);
    }
}
