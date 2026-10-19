<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Occurrences retain their identity even when authored ids or labels are repeated. */
final class KanbanDiagram
{
    public int $origin = 0;
    /** @var list<array{id: string, label: string, markdown: MermaidMarkdown, origin: int, column: int, group: bool, metadata: array<string, string>}> */
    public array $nodes = [];
}
