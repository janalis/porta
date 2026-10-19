<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Ordered participants and chronological sequence events, independent of layout. */
final class SequenceDiagram
{
    /** @var array<array-key, array{label: string, origin: int}> */
    public array $participants = [];
    /** @var list<array{kind: string, from: string, to: string, label: string, arrow: string, placement: string, origin: int}> */
    public array $events = [];
    /** @var array<int, array{kind: string, label: string, start: int, end: int, depth: int, origin: int}> */
    public array $frames = [];
    /** @var list<array{actor: string, start: int, end: ?int, depth: int, origin: int}> */
    public array $activations = [];
    /** @var array<array-key, array{create: ?int, destroy: ?int}> */
    public array $lifetimes = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    /** @var list<array{label: string, actors: list<string>, origin: int}> */
    public array $groups = [];
    /** @var array<array-key, int> */
    public array $memberships = [];
    /** @var array<array-key, string> */
    public array $participantTypes = [];
    /** @var array<array-key, array<string, array{kind: string, label: string, value: string, origin: int}>> */
    public array $menus = [];

    public function event(string $from, string $to, string $label, string $arrow, string $placement, MermaidStatement $statement, string $kind = 'message'): int
    {
        if (\count($this->events) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the sequence event limit');
        }
        $index = \count($this->events);
        $this->events[] = ['kind' => $kind, 'from' => $from, 'to' => $to, 'label' => $label, 'arrow' => $arrow, 'placement' => $placement, 'origin' => $statement->line];

        return $index;
    }
}
