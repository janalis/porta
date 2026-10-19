<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** First indentation establishes columns; every deeper declaration is a card in the current column. */
final class KanbanParser
{
    private readonly KanbanDiagram $diagram;
    private bool $header = false;
    private ?int $indent = null;
    private int $column = -1;

    public function __construct()
    {
        $this->diagram = new KanbanDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^kanban\s*(?:%%[^\n]*)?$/i', $statement->text)) {
                throw $statement->error('Expected kanban');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        if (\str_starts_with($statement->text, '::')) {
            $this->decorate($statement);

            return;
        }
        if (\count($this->diagram->nodes) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Kanban node limit');
        }
        $this->indent ??= $statement->column;
        if ($statement->column < $this->indent) {
            throw $statement->error('Kanban item precedes the column indentation');
        }
        $group = $statement->column === $this->indent;
        if ($group) {
            ++$this->column;
        }
        $offset = XyValues::find($statement->text, '@{');
        $text = null === $offset ? $statement->text : \rtrim(\substr($statement->text, offset: 0, length: $offset));
        $metadata = null === $offset ? [] : KanbanMetadata::parse(\substr($statement->text, $offset), $statement);
        [$label, , $markdown] = MindmapLabel::parse(new MermaidStatement($text, $statement->offset, $statement->line, $statement->column));
        // Pinned kanban labels are Markdown whether or not they are backtick strings.
        $markdown = \array_key_exists('label', $metadata) ? MermaidMarkdown::parse($metadata['label']) : $markdown ?? MermaidMarkdown::parse($label);
        $id = \trim(\preg_split('/[\[(){}]/', $text, limit: 2)[0] ?? '');
        $this->diagram->nodes[] = [
            'id' => $id,
            'label' => $markdown->plain(),
            'markdown' => $markdown,
            'origin' => $statement->line,
            'column' => $this->column,
            'group' => $group,
            'metadata' => $metadata,
        ];
    }

    private function decorate(MermaidStatement $statement): void
    {
        $last = \array_key_last($this->diagram->nodes);
        if (null === $last) {
            throw $statement->error('Kanban decoration requires a preceding node');
        }
        $match = [];
        if (1 === \preg_match('/^::icon\(([^)]+)\)\s*(?:%%[^\n]*)?$/', $statement->text, $match)) {
            $this->diagram->nodes[$last]['metadata']['icon'] = $match[1];

            return;
        }
        if (1 !== \preg_match('/^:::(.+)$/', $statement->text, $match)) {
            throw $statement->error('Invalid Kanban decoration');
        }
        $this->diagram->nodes[$last]['metadata']['class'] = \trim($match[1]);
    }

    public function finish(): KanbanDiagram
    {
        if (!$this->header || [] === $this->diagram->nodes) {
            throw new MermaidSyntaxError('Expected kanban and a column', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
