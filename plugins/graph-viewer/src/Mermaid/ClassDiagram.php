<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Class identities remain separate from display labels and member signatures. */
final class ClassDiagram
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, list<array{text: string, origin: int}>> */
    public array $members = [];
    /** @var array<array-key, list<string>> */
    public array $annotations = [];
    /** @var array<array-key, string> */
    public array $types = [];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];
    /** @var list<array{left: string, right: string}> */
    public array $markers = [];
    /** @var list<array{for: string, text: string, origin: int}> notes, attached to a class or free (`for` empty) */
    public array $notes = [];
    /** @var array<string, true> namespace groups a statement declares by name */
    public array $declared = [];

    public function __construct()
    {
        $this->graph = new MermaidGraph();
        $this->graph->colorSlots = MermaidColorSlots::Nodes;
    }
}
