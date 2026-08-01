<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Git\LogEntry;

/** The history list moved to a commit; its file list follows. */
final readonly class GitCommitSelected
{
    public function __construct(
        public LogEntry $commit,
    ) {}
}
