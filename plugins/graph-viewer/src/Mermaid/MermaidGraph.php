<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Semantic graph, independent of CLI pixels and terminal layout. */
final class MermaidGraph
{
    public const int MAX_ITEMS = 4096;

    public readonly MermaidNodeStyles $styles;
    public readonly FlowchartEdgeStyles $edgeStyles;

    public function __construct(
        string $family = 'flowchart',
        public readonly ?FlowchartConfiguration $flowchart = null,
        ?MermaidNodeStyles $styles = null,
    ) {
        // A family that keeps its own styles shares them, so layout measures what decoration draws.
        $this->styles = $styles ?? new MermaidNodeStyles($family);
        $this->edgeStyles = new FlowchartEdgeStyles($this->styles);
    }

    /** @var array<array-key, array{label: string, origin: int, offset: int, groups: list<string>}> insertion-ordered nodes */
    public array $nodes = [];
    /** @var list<array{from: string, to: string, label: string, arrow: string, origin: int}> */
    public array $edges = [];
    /** @var array<array-key, int> authored link identifiers resolve to declaration indices */
    public array $edgeIds = [];
    /** @var array<array-key, string> explicitly declared node shapes */
    public array $shapes = [];
    /** @var array<array-key, array{float, float}> family-specific node dimensions */
    public array $sizes = [];
    /** @var array<array-key, array{label: string, origin: int, offset: int, parent: ?string, direction: string, explicit: bool}> */
    public array $groups = [];
    /** @var array<array-key, array<array-key, true>> */
    private array $members = [];
    /** @var array<array-key, true> */
    private array $owned = [];
    public string $direction = 'TB';
    public float $rankGap = 8.0;
    public float $nodeGap = 6.0;
    public float $padding = 4.0;
    public bool $inheritDirectionsAcrossLinks = true;
    /** @var array<string, MermaidMarkdown> Markdown string labels by `node:ID`, `edge:INDEX` or `group:ID` */
    public array $markdown = [];
    /** Which elements a color theme paints from its palette slots, in declaration order. */
    public MermaidColorSlots $colorSlots = MermaidColorSlots::None;

    /** @param list<string> $groups */
    public function node(string $id, ?string $label, MermaidStatement $statement, array $groups): void
    {
        foreach ($groups as $group) {
            $this->members[$group][$id] = true;
        }
        if (!\array_key_exists($id, $this->nodes)) {
            if (\count($this->nodes) >= self::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the node limit');
            }
            $this->nodes[$id] = ['label' => $label ?? $id, 'origin' => $statement->line, 'offset' => $statement->offset, 'groups' => []];

            return;
        }
        if (null !== $label) {
            $this->nodes[$id]['label'] = $label;
            $this->nodes[$id]['origin'] = $statement->line;
        }
    }

    /** First closure owns repeated nodes, including inner groups before their parents.
     * @param list<string> $ancestors the closing group is last
     */
    public function closeGroup(string $id, array $ancestors): void
    {
        foreach ($this->members[$id] ?? [] as $node => $member) {
            if (\array_key_exists($node, $this->owned)) {
                continue;
            }
            $this->nodes[$node]['groups'] = $ancestors;
            $this->owned[$node] = true;
        }
        unset($this->members[$id]);
    }

    public function edge(string $from, string $to, string $label, string $arrow, MermaidStatement $statement): void
    {
        if (\count($this->edges) >= self::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the edge limit');
        }
        $this->edges[] = ['from' => $from, 'to' => $to, 'label' => $label, 'arrow' => $arrow, 'origin' => $statement->line];
    }
}
