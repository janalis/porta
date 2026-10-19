<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned journey declarations with located errors for invalid scores and task data. */
final class JourneyParser
{
    private readonly JourneyDiagram $diagram;
    private bool $header = false;
    private ?int $section = null;

    public function __construct()
    {
        $this->diagram = new JourneyDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if ('journey' !== \strtolower($statement->text)) {
                throw $statement->error('Expected journey');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $match = [];
        match (true) {
            1 === \preg_match('/^title\s([^#\n;]+)$/i', $statement->text, $match) => $this->metadata('title', $match[1], $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match) => $this->metadata(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->metadata('accdescr', $match[1], $statement),
            1 === \preg_match('/^section\s([^#:\n;]+)$/i', $statement->text, $match) => $this->section($match[1], $statement),
            default => $this->task($statement),
        };
    }

    private function metadata(string $kind, string $text, MermaidStatement $statement): void
    {
        $this->diagram->metadata[$kind] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    private function section(string $text, MermaidStatement $statement): void
    {
        if (\count($this->diagram->sections) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the journey section limit');
        }
        $this->section = \count($this->diagram->sections);
        $this->diagram->sections[] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];
    }

    private function task(MermaidStatement $statement): void
    {
        $parts = \explode(':', $statement->text, limit: 3);
        $scoreText = \trim($parts[1] ?? '');
        $name = \trim($parts[0]);
        if ('' === $name || !\is_numeric($scoreText) || (\array_key_exists(2, $parts) && \str_contains($parts[2], ':')) || 1 !== \preg_match('/^[^#:\n;]+:[^#\n;]+$/', $statement->text)) {
            throw $statement->error('Expected journey task name, score and optional actor list');
        }
        $score = (float) $scoreText;
        if (!\is_finite($score) || $score < 1.0 || $score > 5.0) {
            throw $statement->error('Journey score must be between 1 and 5');
        }
        if (\count($this->diagram->tasks) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the journey task limit');
        }
        $actors = \array_key_exists(2, $parts) ? \array_map(static fn (string $actor): string => MermaidText::decode(\trim($actor)), \explode(',', $parts[2])) : [];
        if (\count($actors) > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the journey actor limit');
        }
        $this->diagram->tasks[] = [
            'label' => MermaidText::decode(\trim($parts[0])),
            'score' => $score,
            'scoreText' => $scoreText,
            'actors' => $actors,
            'section' => $this->section,
            'origin' => $statement->line,
        ];
    }

    public function finish(): JourneyDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected journey', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
