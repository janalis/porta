<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Image\Rasterizer;

/**
 * A scriptable {@see Rasterizer} for widget tests: answers with queued PNG bytes
 * (or an error) synchronously, or holds the callbacks for manual delivery when
 * `$deferred` — no converter binary, no subprocess, no event loop. The
 * {@see FakeLayoutEngine} of the image pane.
 */
final class FakeRasterizer implements Rasterizer
{
    /** @var list<?float> every scale rasterize() was asked for, in order (null = the document's own size) */
    public array $scales = [];

    public ?string $binary = '/fake/rsvg-convert';

    /** The next rasterize() outcome: PNG bytes, or null to answer nothing. */
    public ?string $nextPng = null;

    /** Whether $nextPng is an error message rather than bytes. */
    public bool $pngIsError = false;

    public bool $deferred = false;

    /** @var ?\Closure(string): void the held onPng when $deferred */
    public ?\Closure $pendingPng = null;

    /** @var ?\Closure(string): void the held onError when $deferred */
    public ?\Closure $pendingError = null;

    public function binary(): ?string
    {
        return $this->binary;
    }

    public function rasterize(string $svg, ?float $scale, \Closure $onPng, \Closure $onError): void
    {
        $this->scales[] = $scale;
        if ($this->deferred) {
            $this->pendingPng = $onPng;
            $this->pendingError = $onError;

            return;
        }
        if (null === $this->nextPng) {
            return;
        }
        $this->pngIsError ? $onError($this->nextPng) : $onPng($this->nextPng);
    }
}
