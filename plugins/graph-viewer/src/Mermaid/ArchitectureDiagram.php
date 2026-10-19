<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Declaration order is semantic: parents, edge endpoints and alignment members must already exist. */
final class ArchitectureDiagram
{
    /** @var array<array-key, array{id: string, kind: string, icon: ?string, iconText: ?string, title: ?string, in: ?string, statement: MermaidStatement}> */
    public array $items = [];
    /** @var list<array{lhsId: string, lhsDir: string, lhsInto: bool, lhsGroup: bool, rhsId: string, rhsDir: string, rhsInto: bool, rhsGroup: bool, title: ?string, statement: MermaidStatement}> */
    public array $edges = [];
    /** @var list<array{direction: string, members: list<string>, statement: MermaidStatement}> */
    public array $alignments = [];
    /** @var list<array{text: string, origin: int}> */
    public array $legend = [];
    /** @var array<string, string> */
    public array $metadata = [];
    public int $origin = 0;

    public function add(string $id, string $kind, ?string $icon, ?string $iconText, ?string $title, ?string $parent, MermaidStatement $statement): void
    {
        if (\array_key_exists($id, $this->items)) {
            throw $statement->error('Architecture identifier already declared: '.$id);
        }
        if (\count($this->items) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Architecture symbol limit exceeded');
        }
        if (null !== $parent) {
            if ($id === $parent || !\array_key_exists($parent, $this->items) || 'group' !== $this->items[$parent]['kind']) {
                throw $statement->error('Architecture parents must be previously declared groups');
            }
            $depth = 0;
            $ancestor = $parent;
            while (null !== $ancestor) {
                if (++$depth > 128) {
                    throw $statement->error('Architecture group depth limit exceeded');
                }
                $ancestor = $this->items[$ancestor]['in'];
            }
        }
        $this->items[$id] = ['id' => $id, 'kind' => $kind, 'icon' => $icon, 'iconText' => $iconText, 'title' => $title, 'in' => $parent, 'statement' => $statement];
    }

    /** @param array{lhsId: string, lhsDir: string, lhsInto: bool, lhsGroup: bool, rhsId: string, rhsDir: string, rhsInto: bool, rhsGroup: bool, title: ?string, statement: MermaidStatement} $edge */
    public function edge(array $edge): void
    {
        if (\count($this->edges) >= MermaidGraph::MAX_ITEMS) {
            throw $edge['statement']->error('Architecture edge limit exceeded');
        }
        foreach (['lhsId', 'rhsId'] as $key) {
            $id = $edge[$key];
            if (!\array_key_exists($id, $this->items) || 'group' === $this->items[$id]['kind']) {
                throw $edge['statement']->error('Architecture edges require previously declared services or junctions');
            }
        }
        $left = $this->items[$edge['lhsId']]['in'];
        $right = $this->items[$edge['rhsId']]['in'];
        if (($edge['lhsGroup'] || $edge['rhsGroup']) && null !== $left && $left === $right) {
            throw $edge['statement']->error('Architecture group traversal requires different parent groups');
        }
        $this->edges[] = $edge;
    }

    /** @param list<string> $members */
    public function align(string $direction, array $members, MermaidStatement $statement): void
    {
        if (\count($this->alignments) >= MermaidGraph::MAX_ITEMS || \count($members) < 2 || \count(\array_unique($members)) !== \count($members)) {
            throw $statement->error('Architecture alignment needs at least two distinct members within the declaration limit');
        }
        foreach ($members as $id) {
            if (!\array_key_exists($id, $this->items) || 'group' === $this->items[$id]['kind']) {
                throw $statement->error('Architecture alignment members must be previously declared services or junctions');
            }
        }
        $this->alignments[] = ['direction' => $direction, 'members' => $members, 'statement' => $statement];
    }
}
