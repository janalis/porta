<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Participant-only box scopes never consume chronological message rows. */
final class SequenceGroups
{
    private ?MermaidStatement $open = null;
    private int $index = 0;

    public function __construct(
        private readonly SequenceDiagram $diagram,
        private readonly MermaidConfiguration $configuration = new MermaidConfiguration(),
    ) {}

    public function start(string $label, MermaidStatement $statement): void
    {
        if (null !== $this->open) {
            throw $statement->error('Sequence participant boxes cannot nest');
        }
        if (\count($this->diagram->groups) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the participant box limit');
        }
        $this->index = \count($this->diagram->groups);
        $this->diagram->groups[] = ['label' => SequenceText::parse($label, $this->configuration), 'actors' => [], 'origin' => $statement->line];
        $this->open = $statement;
    }

    public function active(): bool
    {
        return null !== $this->open;
    }

    public function accept(MermaidStatement $statement): void
    {
        if ('end' !== \strtolower($statement->text) && 1 !== \preg_match('/^(?:participant|actor|destroy|box)(?:\s+|$)/i', $statement->text)) {
            throw $statement->error('Sequence participant box accepts only participant declarations');
        }
    }

    public function participant(string $id, MermaidStatement $statement): void
    {
        if (null === $this->open) {
            return;
        }
        $previous = $this->diagram->memberships[$id] ?? $this->index;
        if ($previous !== $this->index) {
            throw $statement->error('Sequence participant belongs to another box');
        }
        $this->diagram->memberships[$id] = $this->index;
        if (!\in_array($id, $this->diagram->groups[$this->index]['actors'], strict: true)) {
            $this->diagram->groups[$this->index]['actors'][] = $id;
        }
    }

    public function end(): void
    {
        $this->open = null;
    }

    public function finish(): void
    {
        if (null !== $this->open) {
            throw $this->open->error('Unclosed sequence participant box');
        }
    }
}
