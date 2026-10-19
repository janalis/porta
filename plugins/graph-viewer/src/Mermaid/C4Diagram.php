<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Element and relationship identity follows C4's insertion-ordered alias maps. */
final class C4Diagram
{
    /** @var array<array-key, array{macro: string, attributes: array<string, string>, parent: ?string, origin: int, statement: MermaidStatement}> */
    public array $nodes = [];
    /** @var array<array-key, array{macro: string, attributes: array<string, string>, parent: ?string, origin: int, statement: MermaidStatement}> */
    public array $groups = [];
    /** @var array<string, array{macro: string, attributes: array<string, string>, origin: int, statement: MermaidStatement}> */
    public array $edges = [];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];
    public string $family = 'C4Context';
    public ?MermaidStatement $title = null;
    public int $origin = 0;
    public int $columns = 4;
    public int $groupColumns = 2;
}
