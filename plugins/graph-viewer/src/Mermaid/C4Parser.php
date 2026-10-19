<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Calls update existing aliases in place; a style call never affects a future declaration. */
final class C4Parser
{
    private const array ELEMENTS = [
        'Person',
        'Person_Ext',
        'System',
        'SystemDb',
        'SystemQueue',
        'System_Ext',
        'SystemDb_Ext',
        'SystemQueue_Ext',
        'Container',
        'ContainerDb',
        'ContainerQueue',
        'Container_Ext',
        'ContainerDb_Ext',
        'ContainerQueue_Ext',
        'Component',
        'ComponentDb',
        'ComponentQueue',
        'Component_Ext',
        'ComponentDb_Ext',
        'ComponentQueue_Ext',
    ];
    private const array GROUPS = ['Boundary', 'Enterprise_Boundary', 'System_Boundary', 'Container_Boundary', 'Deployment_Node', 'Node', 'Node_L', 'Node_R'];
    private const array RELATIONS = ['Rel', 'BiRel', 'Rel_U', 'Rel_Up', 'Rel_D', 'Rel_Down', 'Rel_L', 'Rel_Left', 'Rel_R', 'Rel_Right', 'Rel_Back', 'RelIndex'];

    private readonly C4Diagram $diagram;
    private ?MermaidStatement $header = null;
    /** @var list<string> */
    private array $stack = [];
    private ?string $pending = null;
    private int $count = 0;
    private ?MermaidStatement $previous = null;
    private bool $lineAfterBrace = false;
    /** @var list<bool> */
    private array $contents = [];

    public function __construct()
    {
        $this->diagram = new C4Diagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (null === $this->header) {
            if (1 !== \preg_match('/^C4(?:Context|Container|Component|Dynamic|Deployment)$/', $statement->text)) {
                throw $statement->error('Expected a C4 diagram header');
            }
            $this->header = $statement;
            $this->previous = $statement;
            $this->diagram->family = $statement->text;
            $this->diagram->origin = $statement->line;

            return;
        }
        if ($statement->line === $this->header->line) {
            throw $statement->error('C4 declarations must follow the header newline');
        }
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('C4 diagram exceeds its declaration limit');
        }
        if ($this->needsNewline($statement)) {
            throw $statement->error('C4 declarations require a newline between calls');
        }
        $this->structure($statement);
        $this->previous = $statement;
    }

    private function needsNewline(MermaidStatement $statement): bool
    {
        $last = $this->previous;
        if (null === $last || null !== $this->pending) {
            return false;
        }
        $sameLine = $statement->line === ($last->line + \substr_count($last->text, needle: "\n"));
        $braceOnNewLine = '{' === $last->text && !$this->lineAfterBrace;
        $closingBoundary = '}' === $statement->text && '{' !== $last->text;

        return $sameLine && !$braceOnNewLine && !$closingBoundary;
    }

    private function structure(MermaidStatement $statement): void
    {
        if (null !== $this->pending) {
            if ('{' !== $statement->text) {
                throw $statement->error('C4 boundary requires an opening brace');
            }
            $previous = $this->previous;
            $this->lineAfterBrace = null !== $previous && $statement->line === ($previous->line + \substr_count($previous->text, needle: "\n"));
            $this->stack[] = $this->pending;
            $this->contents[] = false;
            $this->pending = null;

            return;
        }
        if ('}' === $statement->text) {
            if ([] === $this->stack) {
                throw $statement->error('Unexpected C4 boundary closing brace');
            }
            if (false === \array_last($this->contents)) {
                throw $statement->error('C4 boundary requires a diagram declaration');
            }
            \array_pop($this->stack);
            \array_pop($this->contents);

            return;
        }
        $this->declaration($statement);
    }

    private function declaration(MermaidStatement $statement): void
    {
        if ([] !== $this->stack && false === \array_last($this->contents) && 1 === \preg_match('/^(?:title\b|accTitle\s*:|accDescr(?:iption)?\s*[:{ ]?)/', $statement->text)) {
            throw $statement->error('C4 boundary must start with a diagram declaration');
        }
        $match = [];
        if (1 === \preg_match('/^(title\s+|accTitle\s*:\s*)(.*)$/s', $statement->text, $match)) {
            $this->diagram->title = new MermaidStatement(MermaidText::decode(\trim($match[2])), $statement->offset, $statement->line, $statement->column);

            return;
        }
        if (1 === \preg_match('/^(?:accDescription\s|accDescr\s*[:{])/', $statement->text)) {
            $this->diagram->metadata[] = ['text' => MermaidText::decode($statement->text), 'origin' => $statement->line];

            return;
        }
        if (1 !== \preg_match('/^([A-Za-z_]+)\s*\(/', $statement->text, $match)) {
            throw $statement->error('Expected a C4 call');
        }
        $macro = $match[1];
        $arguments = C4Arguments::parse($statement);
        match (true) {
            \in_array($macro, self::ELEMENTS, strict: true) => $this->element($macro, $arguments, $statement),
            \in_array($macro, self::GROUPS, strict: true) => $this->group($macro, $arguments, $statement),
            \in_array($macro, self::RELATIONS, strict: true) => $this->relation($macro, $arguments, $statement),
            'UpdateElementStyle' === $macro => $this->elementStyle($arguments, $statement),
            'UpdateRelStyle' === $macro => $this->relationStyle($arguments, $statement),
            'UpdateLayoutConfig' === $macro => $this->rows($arguments, $statement),
            default => throw $statement->error('Unsupported C4 call '.$macro),
        };
        if ([] !== $this->contents) {
            $this->contents[\array_key_last($this->contents)] = true;
        }
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function element(string $macro, array $arguments, MermaidStatement $statement): void
    {
        $technology = \str_starts_with($macro, 'Container') || \str_starts_with($macro, 'Component');
        $fields = $technology ? ['alias', 'label', 'techn', 'descr', 'sprite', 'tags', 'link'] : ['alias', 'label', 'descr', 'sprite', 'tags', 'link'];
        $values = C4Arguments::bind($arguments, $fields);
        $alias = $values['alias'] ?? '';
        $values['label'] ??= '';
        $this->diagram->nodes[$alias] = [
            'macro' => $macro,
            'attributes' => $values + ($this->diagram->nodes[$alias]['attributes'] ?? []),
            'parent' => \array_last($this->stack),
            'origin' => $statement->line,
            'statement' => $statement,
        ];
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function group(string $macro, array $arguments, MermaidStatement $statement): void
    {
        if (\count($this->stack) >= 128) {
            throw $statement->error('C4 boundaries exceed the nesting limit');
        }
        $kind = match ($macro) {
            'Enterprise_Boundary' => 'Enterprise',
            'System_Boundary' => 'System',
            'Container_Boundary' => 'Container',
            default => null,
        };
        if (null !== $kind) {
            \array_splice($arguments, offset: 2, length: 0, replacement: [['name' => null, 'value' => $kind]]);
        }
        $fields = \in_array($macro, ['Deployment_Node', 'Node', 'Node_L', 'Node_R'], strict: true)
            ? ['alias', 'label', 'type', 'descr', 'sprite', 'tags', 'link']
            : ['alias', 'label', 'type', 'tags', 'link'];
        $values = C4Arguments::bind($arguments, $fields);
        $alias = $values['alias'] ?? '';
        if (\in_array($alias, $this->stack, strict: true)) {
            throw $statement->error('C4 boundary cannot contain itself');
        }
        $values['label'] ??= '';
        $values['type'] ??= 'System';
        $this->diagram->groups[$alias] = [
            'macro' => $macro,
            'attributes' => $values + ($this->diagram->groups[$alias]['attributes'] ?? []),
            'parent' => \array_last($this->stack),
            'origin' => $statement->line,
            'statement' => $statement,
        ];
        $this->pending = $alias;
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function relation(string $macro, array $arguments, MermaidStatement $statement): void
    {
        if ('RelIndex' === $macro) {
            \array_shift($arguments);
        }
        if (\count($arguments) < 3) {
            return;
        }
        $values = C4Arguments::bind($arguments, ['from', 'to', 'label', 'techn', 'descr', 'sprite', 'tags', 'link']);
        $from = $values['from'] ?? '';
        $to = $values['to'] ?? '';
        $key = $from."\0".$to;
        $this->diagram->edges[$key] = ['macro' => $macro, 'attributes' => $values + ($this->diagram->edges[$key]['attributes'] ?? []), 'origin' => $statement->line, 'statement' => $statement];
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function elementStyle(array $arguments, MermaidStatement $statement): void
    {
        $values = C4Arguments::bind($arguments, ['elementName', 'bgColor', 'fontColor', 'borderColor', 'shadowing', 'shape', 'sprite', 'techn', 'legendText', 'legendSprite']);
        $alias = $values['elementName'] ?? '';
        if (\array_key_exists($alias, $this->diagram->nodes)) {
            $this->diagram->nodes[$alias]['attributes'] = $values + $this->diagram->nodes[$alias]['attributes'];
            $this->diagram->nodes[$alias]['statement'] = $statement;

            return;
        }
        if (\array_key_exists($alias, $this->diagram->groups)) {
            $this->diagram->groups[$alias]['attributes'] = $values + $this->diagram->groups[$alias]['attributes'];
            $this->diagram->groups[$alias]['statement'] = $statement;
        }
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function relationStyle(array $arguments, MermaidStatement $statement): void
    {
        $values = C4Arguments::bind($arguments, ['from', 'to', 'textColor', 'lineColor', 'offsetX', 'offsetY']);
        $key = ($values['from'] ?? '')."\0".($values['to'] ?? '');
        if (\array_key_exists($key, $this->diagram->edges)) {
            $this->diagram->edges[$key]['attributes'] = $values + $this->diagram->edges[$key]['attributes'];
            $this->diagram->edges[$key]['statement'] = $statement;
        }
    }

    /** @param list<array{name: ?string, value: string}> $arguments */
    private function rows(array $arguments, MermaidStatement $statement): void
    {
        foreach (['columns', 'groupColumns'] as $index => $property) {
            $match = [];
            if (1 !== \preg_match('/^[\t ]*([+-]?\d+)/', $arguments[$index]['value'] ?? '', $match)) {
                continue;
            }
            $number = (int) $match[1];
            if ($number < 1) {
                continue;
            }
            if ($number > 64) {
                throw $statement->error('Native C4 row limit is 64 elements');
            }
            $this->diagram->{$property} = $number;
        }
    }

    public function finish(): C4Diagram
    {
        if (null === $this->header) {
            throw new MermaidSyntaxError('Expected a C4 diagram header', offset: 0, sourceLine: 0, column: 0);
        }
        $unclosed = $this->pending;
        if (null === $unclosed && [] !== $this->stack) {
            $unclosed = \array_last($this->stack);
        }
        if (null !== $unclosed) {
            throw $this->diagram->groups[$unclosed]['statement']->error('Unclosed C4 boundary');
        }
        if (0 === $this->count) {
            throw $this->header->error('C4 diagram requires a declaration');
        }
        foreach ($this->diagram->edges as $edge) {
            foreach (['from', 'to'] as $side) {
                $name = $edge['attributes'][$side] ?? '';
                if (!\array_key_exists($name, $this->diagram->nodes) && !\array_key_exists($name, $this->diagram->groups)) {
                    throw $edge['statement']->error('C4 relationship references an unknown element or boundary');
                }
            }
        }

        return $this->diagram;
    }
}
