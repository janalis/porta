<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Nested block scopes own their first declaration; later references only update labels and shapes. */
final class BlockParser
{
    private readonly BlockDiagram $source;
    private bool $header = false;
    private bool $opening = false;
    /** @var list<string> */
    private array $scopes = [''];
    private ?string $last = null;
    private ?MermaidStatement $link = null;
    private int $count = 0;

    public function __construct()
    {
        $this->source = new BlockDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (++$this->count > 16_384) {
            throw $statement->error('Block declaration limit exceeded');
        }
        if (!$this->header) {
            if (!\in_array($text, ['block', 'block-beta'], strict: true)) {
                throw $statement->error('Expected block header');
            }
            $this->header = true;

            return;
        }
        $scope = $this->scopes[\count($this->scopes) - 1];
        match (true) {
            \str_starts_with($text, 'columns ') => $this->columns($statement, $scope),
            \in_array($text, ['block', 'block-beta', 'block:'], strict: true) => $this->open($statement),
            'end' === $text => $this->close($statement),
            1 === \preg_match('/^(?:classDef|class|style)\s/', $text) => $this->source->graph->styles->accept($statement),
            \str_starts_with($text, ':') => $this->span($statement),
            \str_starts_with($text, 'space') && 1 === \preg_match('/^space(?::\d+)?$/', $text) => $this->space($statement, $scope),
            1 === \preg_match('/^[xo<]?(?:--|==|-\.|\.\.|~~~)/', $text) => $this->link($statement),
            \str_starts_with($text, '<[') || \in_array($text[0], ['[', '(', '{', '>'], strict: true) => $this->shape($statement),
            default => $this->node($statement, $scope),
        };
    }

    private function columns(MermaidStatement $statement, string $scope): void
    {
        $text = \substr($statement->text, offset: 8);
        $columns = 'auto' === $text ? -1 : (int) $text;
        if ($columns > 256) {
            throw $statement->error('Block column limit exceeded');
        }
        $this->source->columns[$scope] = $columns;
    }

    private function open(MermaidStatement $statement): void
    {
        if ($this->opening || \count($this->scopes) > 128) {
            throw $statement->error('Block nesting limit exceeded');
        }
        if ('block:' === $statement->text) {
            $this->opening = true;

            return;
        }
        $this->opening = true;
        $id = '@block:'.$this->count;
        $this->node(new MermaidStatement($id, $statement->offset, $statement->line, $statement->column), $this->scopes[\count($this->scopes) - 1]);
        $this->source->graph->groups[$id]['label'] = '';
    }

    private function close(MermaidStatement $statement): void
    {
        if ($this->opening || null !== $this->link || \count($this->scopes) < 2) {
            throw $statement->error('Unexpected block end');
        }
        $scope = \array_pop($this->scopes);
        if ([] === $this->source->children[$scope]) {
            throw $statement->error('Empty block');
        }
        $this->last = $scope;
    }

    private function node(MermaidStatement $statement, string $scope): void
    {
        $id = $statement->text;
        if (!\array_key_exists($id, $this->source->spans)) {
            if (\count($this->source->spans) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Block item limit exceeded');
            }
            $this->source->children[$scope][] = $id;
            $this->source->spans[$id] = 1;
        }
        if ($this->opening) {
            if (\array_key_exists($id, $this->source->graph->groups) || \array_key_exists($id, $this->source->graph->nodes)) {
                throw $statement->error('Duplicate block boundary');
            }
            $this->source->graph->groups[$id] = [
                'label' => $id,
                'origin' => $statement->line,
                'offset' => $statement->offset,
                'parent' => '' === $scope ? null : $scope,
                'direction' => 'TB',
                'explicit' => false,
            ];
            $this->source->children[$id] = [];
            $this->scopes[] = $id;
            $this->opening = false;
        }
        if (!\array_key_exists($id, $this->source->graph->groups)) {
            $this->source->graph->node($id, null, $statement, groups: []);
        }
        if (null !== $this->link) {
            $label = [];
            \preg_match('/"([^"]*)"/', $this->link->text, $label);
            $arrow = \preg_replace('/\s*"[^"]*"\s*/', replacement: '', subject: $this->link->text) ?? $this->link->text;
            $this->source->graph->edge($this->last ?? throw $this->link->error('Block link requires a source'), $id, FlowchartPath::label($label[1] ?? ''), $arrow, $this->link);
            $this->link = null;
        }
        $this->last = $id;
    }

    private function shape(MermaidStatement $statement): void
    {
        if (null === $this->last) {
            throw $statement->error('Block shape requires an identifier');
        }
        [$label, $shape] = self::labelShape($statement);
        $this->source->graph->shapes[$this->last] = $shape;
        if (\array_key_exists($this->last, $this->source->graph->groups)) {
            $this->source->graph->groups[$this->last]['label'] = $label;

            return;
        }
        $this->source->graph->nodes[$this->last]['label'] = $label;
    }

    /** @return array{string, string} */
    private static function labelShape(MermaidStatement $statement): array
    {
        $text = $statement->text;
        if (\str_starts_with($text, '<[')) {
            $match = [];
            \preg_match('/^<\["(.*)"\]>\s*\((.*)\)$/s', $text, $match);

            return [FlowchartPath::label('"'.$match[1].'"'), 'block-arrow:'.(\preg_replace('/\s/', replacement: '', subject: $match[2]) ?? '')];
        }
        $temporary = new MermaidGraph('block');
        new FlowchartPath($temporary, groups: [])->parse(new MermaidStatement('node'.$text, $statement->offset, $statement->line, $statement->column));

        return [$temporary->nodes['node']['label'], $temporary->shapes['node']];
    }

    private function span(MermaidStatement $statement): void
    {
        $span = (int) \substr($statement->text, offset: 1);
        if (null === $this->last || $span < 1 || $span > 256) {
            throw $statement->error('Block span requires an item and a width between 1 and 256');
        }
        $this->source->spans[$this->last] = $span;
    }

    private function space(MermaidStatement $statement, string $scope): void
    {
        $count = \str_contains($statement->text, ':') ? (int) \substr($statement->text, offset: 6) : 1;
        if ($count > 256) {
            throw $statement->error('Block space limit exceeded');
        }
        if ((\count($this->source->spans) + $count) > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Block item limit exceeded');
        }
        for ($i = 0; $i < $count; ++$i) {
            $id = '@space:'.$this->count.':'.$i;
            $this->source->children[$scope][] = $id;
            $this->source->spans[$id] = 1;
            $this->source->spaces[$id] = true;
        }
        $this->last = null;
    }

    private function link(MermaidStatement $statement): void
    {
        if (null === $this->last || null !== $this->link) {
            throw $statement->error('Block link requires two endpoints');
        }
        $this->link = $statement;
    }

    public function finish(): BlockDiagram
    {
        if (!$this->header || $this->opening || null !== $this->link || [] === $this->source->children[''] || 1 !== \count($this->scopes)) {
            throw new MermaidSyntaxError('Incomplete block diagram', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->source;
    }
}
