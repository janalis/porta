<?php

declare(strict_types=1);

namespace Porta\Event;

/** A run in the terminal panel ended on its own, with the exit code it ended on. */
final readonly class RunFinished
{
    public function __construct(
        public string $title,
        public int $code,
    ) {}
}
