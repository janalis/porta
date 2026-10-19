<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A forest of occurrences: labels may repeat without merging separate authored items. */
final class TreemapDiagram
{
    public int $origin = 0;
    /** @var list<array{label: string, value: ?float, origin: int, parent: ?int, depth: int, class: string}> */
    public array $nodes = [];
    /** @var array<int, list<int>> parent id (-1 for the forest) => child ids */
    public array $children = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    /** @var array<string, array<string, float|string|bool>> */
    public array $classes = [];
}
