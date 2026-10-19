<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Shared flowchart container ownership and first-closed declaration semantics. */
final class FlowchartScopes
{
    /** @var list<string> */
    private array $groups = [];
    /** @var list<MermaidStatement> */
    private array $openers = [];
    /** @var list<array{label: string, origin: int, offset: int, parent: ?string, direction: string, explicit: bool}> */
    private array $frames = [];
    /** @var array<array-key, true> */
    private array $closed = [];

    public function __construct(
        private readonly MermaidGraph $graph,
    ) {}

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function close(MermaidStatement $statement): string
    {
        if ([] === $this->groups) {
            throw $statement->error('Unexpected end');
        }
        $index = \count($this->groups) - 1;
        $id = $this->groups[$index];
        $frame = $this->frames[$index];
        \array_pop($this->groups);
        \array_pop($this->frames);
        \array_pop($this->openers);
        if (!\array_key_exists($id, $this->closed)) {
            $this->graph->groups[$id] = $frame;
            $this->closed[$id] = true;
        }
        $ancestors = [$id];
        $parent = $this->graph->groups[$id]['parent'];
        while (null !== $parent) {
            \array_unshift($ancestors, $parent);
            $parent = $this->graph->groups[$parent]['parent'];
        }
        $this->graph->closeGroup($id, $ancestors);

        return $id;
    }

    public function open(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^subgraph\s+([^\[\]]+?)(?:\s*\[(.*)\])?$/s', $statement->text, $match)) {
            throw $statement->error('Invalid subgraph declaration');
        }
        $id = \trim($match[1]);
        if (\count($this->groups) >= 128) {
            throw $statement->error('Subgraph nesting exceeds the depth limit');
        }
        if (!\array_key_exists($id, $this->graph->groups) && \count($this->graph->groups) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Duplicate subgraph or group limit exceeded');
        }
        $parent = [] === $this->groups ? null : $this->groups[\count($this->groups) - 1];
        $frame = [
            'label' => FlowchartPath::label($match[2] ?? $id),
            'origin' => $statement->line,
            'offset' => $statement->offset,
            'parent' => \in_array($id, $this->groups, strict: true) ? $this->graph->groups[$id]['parent'] : $parent,
            'direction' => $this->graph->direction,
            'explicit' => false,
        ];
        $markdown = FlowchartPath::markdown($match[2] ?? '');
        if (null !== $markdown && !\array_key_exists($id, $this->graph->groups)) {
            $this->graph->markdown['group:'.$id] = $markdown;
        }
        $this->graph->groups[$id] ??= $frame;
        $this->frames[] = $frame;
        $this->openers[] = $statement;
        $this->groups[] = $id;
    }

    public function direction(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^direction\s+(TB|TD|BT|LR|RL)$/', $statement->text, $match)) {
            throw $statement->error('Expected direction TB, TD, BT, LR or RL');
        }
        if ([] === $this->groups) {
            $this->graph->direction = 'TD' === $match[1] ? 'TB' : $match[1];

            return;
        }
        $index = \count($this->frames) - 1;
        $this->frames[$index]['explicit'] = true;
        $this->frames[$index]['direction'] = 'TD' === $match[1] ? 'TB' : $match[1];
        $id = $this->groups[$index];
        if (!\array_key_exists($id, $this->closed)) {
            $this->graph->groups[$id] = $this->frames[$index];
        }
    }

    public function finish(): void
    {
        if ([] !== $this->groups) {
            throw $this->openers[\count($this->openers) - 1]->error('Unclosed subgraph');
        }
        foreach ($this->graph->groups as $id => $group) {
            unset($this->graph->nodes[$id]);
        }
    }
}
