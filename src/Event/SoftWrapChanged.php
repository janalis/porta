<?php

declare(strict_types=1);

namespace Porta\Event;

/** The soft-wrap setting was switched live (the editor already re-wraps). */
final readonly class SoftWrapChanged implements PluginObservable
{
    public function __construct(
        public bool $on,
    ) {}
}
