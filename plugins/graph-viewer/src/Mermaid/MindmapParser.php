<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A node is an occurrence, not its authored id: repeated words never merge branches. */
final class MindmapParser
{
    private readonly MermaidGraph $graph;
    private bool $header = false;
    /** @var list<array{indent: int, id: string}> */
    private array $parents = [];
    private ?string $last = null;

    public function __construct()
    {
        $this->graph = new MermaidGraph();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^mindmap\s*(?:%%[^\n]*)?$/i', $statement->text)) {
                throw $statement->error('Expected mindmap');
            }
            $this->header = true;

            return;
        }
        $text = $statement->text;
        if (1 === \preg_match('/^(?:::icon\([^)]+\)\s*(?:%%[^\n]*)?|:::.+)$/', $text)) {
            $this->decorate($statement);

            return;
        }
        if (\str_starts_with($text, '::')) {
            throw $statement->error('Invalid mindmap decoration');
        }
        [$label, $shape, $markdown] = MindmapLabel::parse($statement);
        while ([] !== $this->parents && $this->parents[\count($this->parents) - 1]['indent'] >= $statement->column) {
            \array_pop($this->parents);
        }
        if ([] === $this->parents && null !== $this->last) {
            throw $statement->error('A mindmap can have only one root');
        }
        if (\count($this->parents) >= 128) {
            throw $statement->error('Mindmap nesting exceeds the depth limit');
        }
        $id = 'mindmap-'.\count($this->graph->nodes);
        $this->graph->node($id, $label, $statement, groups: []);
        if (null !== $markdown) {
            $this->graph->markdown['node:'.$id] = $markdown;
        }
        $this->graph->shapes[$id] = $shape;
        if ([] !== $this->parents) {
            $this->graph->edge($this->parents[\count($this->parents) - 1]['id'], $id, label: '', arrow: '---', statement: $statement);
        }
        $this->parents[] = ['indent' => $statement->column, 'id' => $id];
        $this->last = $id;
    }

    private function decorate(MermaidStatement $statement): void
    {
        if (null === $this->last) {
            throw $statement->error('Mindmap decoration requires a preceding node');
        }

        // CSS classes and external icon packs have no native terminal equivalent yet.
    }

    public function finish(): MermaidGraph
    {
        if (!$this->header || null === $this->last) {
            throw new MermaidSyntaxError('Expected mindmap and a root node', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->graph;
    }
}
