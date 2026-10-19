<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Authored occurrences remain separate, including repeated filenames in different directories. */
final class TreeViewDiagram
{
    public int $origin = 0;
    /** @var list<array{label: string, parent: ?int, depth: int, origin: int, class: string, icon: ?string, description: string}> */
    public array $nodes = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
}
