<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Composite scopes give pseudostates separate identities and partition concurrent regions. */
final class StateScopes
{
    /** @var list<array{id: string, region: ?string, count: int}> */
    private array $stack = [];

    public function __construct(
        private readonly MermaidGraph $graph,
    ) {}

    /** @return list<string> */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->stack as $frame) {
            $groups[] = $frame['id'];
            if (null !== $frame['region']) {
                $groups[] = $frame['region'];
            }
        }

        return $groups;
    }

    public function current(): string
    {
        $groups = $this->groups();

        return $groups[\count($groups) - 1] ?? '';
    }

    public function open(string $id, MermaidStatement $statement): void
    {
        if (\array_key_exists($id, $this->graph->groups) || \count($this->stack) >= 128) {
            throw $statement->error('Duplicate composite state or excessive state nesting');
        }
        $label = $this->graph->nodes[$id]['label'];
        unset($this->graph->nodes[$id]);
        $this->group($id, $label, $this->current(), $statement);
        $this->stack[] = ['id' => $id, 'region' => null, 'count' => 0];
    }

    public function close(MermaidStatement $statement): void
    {
        if ([] === $this->stack) {
            throw $statement->error('Unexpected composite state closing brace');
        }
        \array_pop($this->stack);
    }

    public function direction(string $direction): void
    {
        $current = $this->current();
        if ('' === $current) {
            $this->graph->direction = $direction;

            return;
        }
        $this->graph->groups[$current]['direction'] = $direction;
        $this->graph->groups[$current]['explicit'] = true;
    }

    public function divide(MermaidStatement $statement): void
    {
        if ([] === $this->stack) {
            throw $statement->error('Concurrent regions require a composite state');
        }
        $index = \count($this->stack) - 1;
        $frame = $this->stack[$index];
        $parent = $frame['id'];
        if (null === $frame['region']) {
            $first = '@region:'.$parent.':0';
            $this->group($first, '', $parent, $statement);
            foreach ($this->graph->nodes as $id => $node) {
                $position = \array_search($parent, $node['groups'], strict: true);
                if (false === $position) {
                    continue;
                }
                \array_splice($this->graph->nodes[$id]['groups'], $position + 1, length: 0, replacement: [$first]);
            }
            foreach ($this->graph->groups as $id => $group) {
                if ($id === $first || $group['parent'] !== $parent) {
                    continue;
                }
                $this->graph->groups[$id]['parent'] = $first;
            }
        }
        $region = '@region:'.$parent.':'.($frame['count'] + 1);
        $this->group($region, '', $parent, $statement);
        $this->stack[$index]['region'] = $region;
        ++$this->stack[$index]['count'];
    }

    private function group(string $id, string $label, string $parent, MermaidStatement $statement): void
    {
        if (\count($this->graph->groups) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the state scope limit');
        }
        $this->graph->groups[$id] = [
            'label' => $label,
            'origin' => $statement->line,
            'offset' => $statement->offset,
            'parent' => '' === $parent ? null : $parent,
            'direction' => $this->graph->direction,
            'explicit' => false,
        ];
    }

    public function finish(): void
    {
        if ([] !== $this->stack) {
            $id = $this->stack[\count($this->stack) - 1]['id'];
            throw new MermaidSyntaxError('Unclosed composite state', $this->graph->groups[$id]['offset'], $this->graph->groups[$id]['origin'], column: 0);
        }
        // Concurrent regions can introduce parents after their descendants.
        $pending = $this->graph->groups;
        $ordered = [];
        while ([] !== $pending) {
            foreach ($pending as $id => $group) {
                if (null !== $group['parent'] && !\array_key_exists($group['parent'], $ordered)) {
                    continue;
                }
                $ordered[$id] = $group;
                unset($pending[$id]);
            }
        }
        $this->graph->groups = $ordered;
    }
}
