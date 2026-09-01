<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Container\AsIdeService;
use Porta\Editor\ChangeMarkKind;

/**
 * The working tree's per-file version-control status, as last published by
 * whichever plugin computes it. Plain held state: the tree reads it every frame
 * and the producer replaces it whole.
 */
#[AsIdeService]
final class FileStatuses implements FileStatusSink
{
    /** @var array<string, ChangeMarkKind> absolute path => kind */
    private array $statuses = [];

    #[\Override]
    public function applyFileStatuses(array $statuses): void
    {
        $this->statuses = $statuses;
    }

    /** @return array<string, ChangeMarkKind> */
    public function all(): array
    {
        return $this->statuses;
    }
}
