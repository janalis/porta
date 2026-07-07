<?php

declare(strict_types=1);

namespace Porta\Git;

/** One commit in the history list. */
final readonly class LogEntry
{
    public function __construct(
        public string $sha,
        public string $shortSha,
        public string $author,
        public int $timestamp,
        public string $subject,
    ) {}
}
