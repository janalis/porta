<?php

declare(strict_types=1);

namespace Porta\Event;

/** The edit key on a variable row: its parent scope, its name, and what it holds now. */
final readonly class DebugVariableEditRequested
{
    public function __construct(
        public int $parentReference,
        public string $name,
        public string $value,
    ) {}
}
