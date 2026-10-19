<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Per-participant activation stacks; open intervals remain valid through diagram end. */
final class SequenceActivities
{
    /** @var array<array-key, list<int>> */
    private array $open = [];
    /** @var array<int, MermaidStatement> */
    private array $statements = [];

    public function __construct(
        private readonly SequenceDiagram $diagram,
    ) {}

    public function change(string $actor, string $action, MermaidStatement $statement, ?int $anchor = null): void
    {
        $depth = \count($this->open[$actor] ?? []);
        if ('deactivate' === $action && 0 === $depth) {
            throw $statement->error('Cannot deactivate an inactive sequence participant');
        }
        if ('activate' === $action && $depth >= 128) {
            throw $statement->error('Sequence activation nesting exceeds the depth limit');
        }
        $index = $this->diagram->event($actor, $actor, '', '', null === $anchor ? '' : 'shortcut', $statement, kind: $action);
        if ('activate' === $action) {
            $id = \count($this->diagram->activations);
            $this->diagram->activations[] = ['actor' => $actor, 'start' => $anchor ?? $index, 'end' => null, 'depth' => $depth, 'origin' => $statement->line];
            $this->statements[$id] = $statement;
            $this->open[$actor][] = $id;

            return;
        }
        // The inactive-participant check proves this stack contains an integer.
        /** @var int $id */
        $id = \array_pop($this->open[$actor]);
        $this->diagram->activations[$id]['end'] = $anchor ?? $index;
    }

    public function finish(): void
    {
        foreach ($this->diagram->activations as $id => $activation) {
            if (!\array_key_exists($activation['actor'], $this->diagram->participants)) {
                throw $this->statements[$id]->error('Unknown sequence activation participant');
            }
        }
    }
}
