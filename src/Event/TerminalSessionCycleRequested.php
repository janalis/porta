<?php

declare(strict_types=1);

namespace Porta\Event;

/** Move to the next (+1) or previous (-1) terminal session. */
final readonly class TerminalSessionCycleRequested
{
    public function __construct(
        public int $delta,
    ) {}
}
