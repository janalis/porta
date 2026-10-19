<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Authored values and first-occurrence node identities, independent of normalized display geometry. */
final class SankeyDiagram
{
    public int $origin = 0;
    /** @var list<array{label: string, origin: int}> */
    public array $nodes = [];
    /** @var list<array{source: int, target: int, value: float, origin: int}> */
    public array $links = [];
}
