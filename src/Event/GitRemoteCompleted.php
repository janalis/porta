<?php

declare(strict_types=1);

namespace Porta\Event;

/** A git remote operation (fetch / pull / push) finished — or was cancelled, as `cancelled`, not ok. */
final readonly class GitRemoteCompleted implements PluginObservable
{
    public function __construct(
        public string $operation,
        public bool $ok,
    ) {}
}
