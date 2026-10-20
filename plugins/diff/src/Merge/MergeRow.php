<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Merge;

/** One row of a merge side: its text, what it is, and the conflict it belongs to (0-based), if any. */
final readonly class MergeRow
{
    public function __construct(
        public string $text,
        public MergeRowKind $kind,
        public ?int $region = null,
    ) {}
}
