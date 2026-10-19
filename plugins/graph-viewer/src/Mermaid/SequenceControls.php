<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** An iterative control stack validates branch ownership and records complete frame spans. */
final class SequenceControls
{
    /** @var list<array{kind: string, label: string, start: int, statement: MermaidStatement}> */
    private array $open = [];

    public function __construct(
        private readonly SequenceDiagram $diagram,
    ) {}

    public function start(string $kind, string $label, MermaidStatement $statement): void
    {
        if (\count($this->open) >= 128) {
            throw $statement->error('Sequence control nesting exceeds the depth limit');
        }
        $start = $this->diagram->event('', '', $label, '', $kind, $statement, kind: 'control');
        $this->open[] = ['kind' => $kind, 'label' => $label, 'start' => $start, 'statement' => $statement];
    }

    public function branch(string $kind, string $label, MermaidStatement $statement): void
    {
        $parent = \array_last($this->open)['kind'] ?? '';
        $allowed = match ($kind) {
            'else' => ['alt'],
            'and' => ['par', 'par_over'],
            default => ['critical'],
        };
        if (!\in_array($parent, $allowed, strict: true)) {
            throw $statement->error('Sequence branch does not belong to its enclosing block');
        }
        $this->diagram->event('', '', $label, '', $kind, $statement, kind: 'control');
    }

    public function end(MermaidStatement $statement): void
    {
        $open = \array_pop($this->open);
        if (null === $open) {
            throw $statement->error('Unexpected sequence end');
        }
        $end = $this->diagram->event('', '', '', '', 'end', $statement, kind: 'control');
        $this->diagram->frames[$open['start']] = [
            'kind' => $open['kind'],
            'label' => $open['label'],
            'start' => $open['start'],
            'end' => $end,
            'depth' => \count($this->open),
            'origin' => $open['statement']->line,
        ];
    }

    public function finish(): void
    {
        $open = \array_last($this->open);
        if (null !== $open) {
            throw $open['statement']->error('Unclosed sequence control block');
        }
        \ksort($this->diagram->frames);
    }
}
