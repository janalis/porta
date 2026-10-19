<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Mermaid 12.1 timeline grammar, with located errors instead of orphan-event crashes. */
final class TimelineParser
{
    private readonly TimelineDiagram $diagram;
    private bool $header = false;
    private ?int $section = null;
    private int $events = 0;

    public function __construct()
    {
        $this->diagram = new TimelineDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if (1 !== \preg_match('/^timeline(?:[ \t]+(LR|TD))?$/i', $statement->text, $match)) {
                throw $statement->error('Expected timeline with optional LR or TD direction');
            }
            $this->diagram->direction = \strtoupper($match[1] ?? 'LR');
            $this->diagram->origin = $statement->line;
            $this->header = true;

            return;
        }
        match (true) {
            1 === \preg_match('/^title\s([^\n]+)$/i', $statement->text, $match) => $this->metadata('title', $match[1], $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match) => $this->metadata(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->metadata('accdescr', $match[1], $statement),
            1 === \preg_match('/^section\s([^:\n]+)$/i', $statement->text, $match) => $this->section($match[1], $statement),
            1 === \preg_match('/^:\s((?:[^:\n]|:(?!\s))+)$/', $statement->text, $match) => $this->event($match[1], $statement),
            default => $this->period($statement),
        };
    }

    private function metadata(string $kind, string $text, MermaidStatement $statement): void
    {
        $this->diagram->metadata[$kind] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    private function section(string $text, MermaidStatement $statement): void
    {
        if (\count($this->diagram->sections) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the timeline section limit');
        }
        $this->section = \count($this->diagram->sections);
        $this->diagram->sections[] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    private function period(MermaidStatement $statement): void
    {
        if (1 !== \preg_match('/^[^#:\n]+$/', $statement->text) || '' === \trim($statement->text)) {
            throw $statement->error('Expected timeline period or colon-space event');
        }
        if (\count($this->diagram->periods) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the timeline period limit');
        }
        $this->diagram->periods[] = ['label' => MermaidText::decode(\trim($statement->text)), 'origin' => $statement->line, 'section' => $this->section, 'events' => []];
    }

    private function event(string $text, MermaidStatement $statement): void
    {
        if ([] === $this->diagram->periods) {
            throw $statement->error('Timeline event requires a preceding period');
        }
        if ($this->events >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the timeline event limit');
        }
        ++$this->events;
        $this->diagram->periods[\count($this->diagram->periods) - 1]['events'][] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    public function finish(): TimelineDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected timeline', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
