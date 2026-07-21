<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * A sink that just records the answer, for the one place core needs a result
 * *within* the call: the incremental single-line re-highlight.
 *
 * That path splices one freshly-coloured line into the previous cache, so it
 * only ever runs against a backend that has already proved itself synchronous.
 * An asynchronous backend leaves {@see $markup} null and the caller falls back
 * to the raw line.
 */
final class CapturingHighlightSink implements HighlightSink
{
    public ?string $markup = null;

    public function deliver(string $documentId, int $version, string $markup): void
    {
        $this->markup = $markup;
    }

    public function fail(string $documentId, int $version, \Throwable $error): void
    {
        $this->markup = null;
    }
}
