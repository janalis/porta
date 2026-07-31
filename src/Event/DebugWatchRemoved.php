<?php

declare(strict_types=1);

namespace Porta\Event;

/** Delete on a watch row, by its index in the watch list. */
final readonly class DebugWatchRemoved
{
    public function __construct(
        public int $index,
    ) {}
}
