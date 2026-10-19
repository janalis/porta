<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Each scope keeps insertion order and independent column/span settings. */
final class BlockDiagram
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, list<string>> */
    public array $children = ['' => []];
    /** @var array<array-key, int> */
    public array $columns = [];
    /** @var array<array-key, int> */
    public array $spans = [];
    /** @var array<array-key, true> */
    public array $spaces = [];

    public function __construct()
    {
        $this->graph = new MermaidGraph('block');
    }
}
