<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The editor is now showing a DIFFERENT file than it was.
 *
 * The deduped counterpart of {@see BufferSwitched}, which fires on EVERY show —
 * config re-apply, pane swaps, focus churn — because core listeners rely on
 * re-firing. Observers that only care when the answer to "which file am I
 * looking at" changes listen here instead, and get one event per real change.
 *
 * A path, not a `Buffer`: this is the shape a third party may observe, and a
 * `Buffer` is a mutable bag of core editor state.
 */
final readonly class ActiveBufferChanged implements PluginObservable
{
    public function __construct(
        public string $path,
    ) {}
}
