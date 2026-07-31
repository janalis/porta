<?php

declare(strict_types=1);

namespace Porta\Event;

/** A stack frame was picked in the debug panel: show where it is. */
final readonly class DebugFrameSelected
{
    public function __construct(
        public int $frameId,
        public string $path,
        public int $line,
    ) {}
}
