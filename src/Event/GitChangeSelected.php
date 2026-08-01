<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Git\ChangeEntry;

/** The changes list moved to an entry — fires on every arrow key, so the diff pane follows. */
final readonly class GitChangeSelected
{
    public function __construct(
        public ChangeEntry $entry,
    ) {}
}
