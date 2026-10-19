<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Indentation alone determines parentage; a file can also have children. */
final class TreeViewParser
{
    private readonly TreeViewDiagram $diagram;
    private bool $header = false;
    /** @var list<array{indent: int, id: int}> */
    private array $parents = [];

    public function __construct()
    {
        $this->diagram = new TreeViewDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if ('treeview-beta' !== \strtolower($statement->text)) {
                throw $statement->error('Expected treeView-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $match = [];
        if (1 === \preg_match('/^(title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])(.*)$/is', $statement->text, $match)) {
            if ([] !== $this->diagram->nodes) {
                throw $statement->error('Tree-view metadata must precede nodes');
            }
            $name = \strtolower(\rtrim($match[1], characters: " \t:{"));
            $body = $match[2];
            if (\str_ends_with($match[1], '{')) {
                if (!\str_ends_with($body, '}')) {
                    throw $statement->error('Unclosed accessibility description');
                }
                $body = \substr($body, offset: 0, length: -1);
            }
            $this->diagram->metadata[$name] = ['label' => \trim($body), 'origin' => $statement->line];

            return;
        }
        $this->item($statement);
    }

    private function item(MermaidStatement $statement): void
    {
        if (\count($this->diagram->nodes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the tree-view node limit');
        }
        while ([] !== $this->parents && \array_last($this->parents)['indent'] >= $statement->column) {
            \array_pop($this->parents);
        }
        if (\count($this->parents) >= 128) {
            throw $statement->error('Tree-view nesting exceeds the depth limit');
        }
        $parent = \array_last($this->parents)['id'] ?? null;
        $id = \count($this->diagram->nodes);
        $this->diagram->nodes[] = [...TreeViewLabels::parse($statement), 'parent' => $parent, 'depth' => \count($this->parents), 'origin' => $statement->line];
        $this->parents[] = ['indent' => $statement->column, 'id' => $id];
    }

    public function finish(): TreeViewDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected treeView-beta', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
