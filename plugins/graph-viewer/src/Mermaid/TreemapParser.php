<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Quoted section/leaf declarations with indentation-based parentage and forward class references. */
final class TreemapParser
{
    private readonly TreemapDiagram $diagram;
    private bool $header = false;
    /** @var list<array{indent: int, id: int}> */
    private array $parents = [];

    public function __construct()
    {
        $this->diagram = new TreemapDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^treemap(?:-beta)?$/i', $statement->text)) {
                throw $statement->error('Expected treemap or treemap-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $this->declaration($statement);
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (
            1 === \preg_match('/^(title)(?:[\t ]+(.*))?$/is', $statement->text, $match)
            || 1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/is', $statement->text, $match)
            || 1 === \preg_match('/^(accDescr)\s*\{([^}]*)\}$/i', $statement->text, $match)
        ) {
            $this->diagram->metadata[\strtolower($match[1])] = ['label' => MermaidText::decode(\trim($match[2] ?? '')), 'origin' => $statement->line];

            return;
        }
        if (1 === \preg_match('/^classDef\s+([a-zA-Z_]\w*)\s*(.*)$/', $statement->text, $match)) {
            if (\count($this->diagram->classes) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the treemap class limit');
            }
            $this->diagram->classes[$match[1]] = \array_replace($this->diagram->classes[$match[1]] ?? [], TreemapStyles::parse($match[2], $statement));

            return;
        }
        $this->item($statement);
    }

    private function item(MermaidStatement $statement): void
    {
        if (\count($this->diagram->nodes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the treemap node limit');
        }
        $match = [];
        if (1 !== \preg_match('/^("[^"]*"|\'[^\']*\')(?:\s*[:,]\s*([\d.,_]+))?(?:\s*:::\s*([a-zA-Z_]\w*))?\s*$/D', $statement->text, $match)) {
            throw $statement->error('Expected a quoted treemap section or numeric leaf');
        }
        $value = null;
        if ('' !== ($match[2] ?? '')) {
            $number = \str_replace(',', replace: '', subject: $match[2]);
            if (!\is_numeric($number) || 1 !== \preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/D', $number) || !\is_finite((float) $number)) {
                throw $statement->error('Treemap values must be complete finite non-negative decimals');
            }
            $value = (float) $number;
        }
        while ([] !== $this->parents && \array_last($this->parents)['indent'] >= $statement->column) {
            \array_pop($this->parents);
        }
        if (\count($this->parents) >= 128) {
            throw $statement->error('Treemap nesting exceeds the depth limit');
        }
        $parent = \array_last($this->parents)['id'] ?? null;
        $id = \count($this->diagram->nodes);
        $label = MermaidText::decode(\substr($match[1], offset: 1, length: -1));
        $this->diagram->nodes[] = ['label' => $label, 'value' => $value, 'origin' => $statement->line, 'parent' => $parent, 'depth' => \count($this->parents), 'class' => $match[3] ?? ''];
        $this->diagram->children[$parent ?? -1][] = $id;
        if (null === $value) {
            $this->parents[] = ['indent' => $statement->column, 'id' => $id];
        }
    }

    public function finish(): TreemapDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected treemap', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
