<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Cause levels are relative to the first cause, independently of the effect's indentation. */
final class IshikawaParser
{
    private readonly IshikawaDiagram $diagram;
    private bool $header = false;
    private ?int $base = null;
    /** @var list<array{level: int, id: int}> */
    private array $parents = [];

    public function __construct()
    {
        $this->diagram = new IshikawaDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^ishikawa(?:-beta)?$/i', $statement->text)) {
                throw $statement->error('Expected ishikawa or ishikawa-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $this->item($statement);
    }

    private function item(MermaidStatement $statement): void
    {
        if (\count($this->diagram->nodes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Ishikawa node limit');
        }
        $id = \count($this->diagram->nodes);
        $level = 0;
        if (0 !== $id) {
            $this->base ??= $statement->column;
            $level = \max(1, $statement->column - $this->base + 1);
        }
        while (\count($this->parents) > 1 && \array_last($this->parents)['level'] >= $level) {
            \array_pop($this->parents);
        }
        if (\count($this->parents) >= 128) {
            throw $statement->error('Ishikawa nesting exceeds the depth limit');
        }
        $parent = \array_last($this->parents)['id'] ?? null;
        $this->diagram->nodes[] = ['label' => MermaidText::decode($statement->text), 'parent' => $parent, 'depth' => \count($this->parents), 'origin' => $statement->line];
        if (null !== $parent) {
            $this->diagram->children[$parent][] = $id;
        }
        $this->parents[] = ['level' => $level, 'id' => $id];
    }

    public function finish(): IshikawaDiagram
    {
        if (!$this->header || [] === $this->diagram->nodes) {
            throw new MermaidSyntaxError('Ishikawa requires a header and effect label', offset: 0, sourceLine: $this->diagram->origin, column: 0);
        }

        return $this->diagram;
    }
}
