<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Physical statements collect declarations before resolving role and namespace constraints. */
final class UsecaseParser
{
    private readonly UsecaseDiagram $diagram;
    private bool $header = false;
    private ?UsecaseElement $boundary = null;
    /** @var list<array{key: string, value: string|bool, statement: MermaidStatement}> */
    private array $boundaryMetadata = [];
    private int $count = 0;

    public function __construct()
    {
        $this->diagram = new UsecaseDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (++$this->count > 16_384) {
            throw $statement->error('Use-case declaration limit exceeded');
        }
        $text = $statement->text;
        if (!$this->header) {
            if ('usecase-beta' !== $text) {
                throw $statement->error('Expected usecase-beta header');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        if ('end' === $text) {
            $this->close($statement);

            return;
        }
        if (1 === \preg_match('/^(?:allowmixing|newpage|package|rectangle|skinparam)\b/i', $text)) {
            throw $statement->error('PlantUML statements are not use-case declarations');
        }
        $control = 1 === \preg_match('/^(?:direction|systemBoundary|note|json|classDef|class|style|accTitle|accDescr)\b/', $text);
        if (null !== $this->boundary && $control) {
            throw $statement->error('A use-case boundary may contain only actor and use-case declarations');
        }
        match (true) {
            1 === \preg_match('/^direction\s/', $text) => $this->direction($statement),
            1 === \preg_match('/^systemBoundary\s/', $text) => $this->open($statement),
            1 === \preg_match('/^note\s/', $text) => $this->note($statement),
            1 === \preg_match('/^json\s/', $text) => $this->json($statement),
            1 === \preg_match('/^(?:classDef|class|style)\b/', $text) => $this->style($statement),
            1 === \preg_match('/^(?:accTitle|accDescr)\b/', $text) => $this->accessibility($statement),
            default => $this->entity($statement),
        };
    }

    private function direction(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^direction\s+(TB|TD|BT|LR|RL)$/', $statement->text, $match)) {
            throw $statement->error('Expected a use-case direction TB, TD, BT, LR or RL');
        }
        $this->diagram->graph->direction = 'TD' === $match[1] ? 'TB' : $match[1];
    }

    private function open(MermaidStatement $statement): void
    {
        $cursor = new UsecaseCursor($statement->slice(14));
        $name = $cursor->name('boundary');
        $name['shaped'] = false;
        $name['shape'] = 'boundary';
        $metadata = $cursor->metadata();
        $classes = $cursor->classes();
        $cursor->finish();
        $this->boundary = $this->diagram->declare($name, 'boundary', metadata: [], stereotype: null, classes: $classes, parent: null, statement: $statement);
        $this->boundaryMetadata = $metadata;
    }

    private function close(MermaidStatement $statement): void
    {
        if (null === $this->boundary) {
            throw $statement->error('Unexpected use-case boundary end');
        }
        if ([] !== $this->boundaryMetadata) {
            $this->diagram->assignments[] = ['id' => $this->boundary->id, 'values' => $this->boundaryMetadata, 'statement' => $this->boundary->statement];
        }
        $this->boundary = null;
        $this->boundaryMetadata = [];
    }

    private function entity(MermaidStatement $statement): void
    {
        $actor = 1 === \preg_match('/^actor\s/', $statement->text);
        $cursor = new UsecaseCursor($actor ? $statement->slice(5) : $statement);
        $name = $cursor->name($actor ? 'actor' : 'usecase');
        if (!$actor && !$name['shaped'] && \str_starts_with($cursor->rest(), '@{')) {
            if (null !== $this->boundary) {
                throw $statement->error('Standalone metadata must stay outside use-case boundaries');
            }
            $values = $cursor->metadata();
            $cursor->finish();
            $this->diagram->assignments[] = ['id' => $name['id'], 'values' => $values, 'statement' => $statement];

            return;
        }
        $first = $this->element($cursor, $name, $actor ? 'actor' : 'usecase', $statement);
        if ($actor && $cursor->consume(',')) {
            do {
                $name = $cursor->name('actor');
                $this->element($cursor, $name, 'actor', $statement);
            } while ($cursor->consume(','));
            $cursor->finish();

            return;
        }
        $this->tail($cursor, $first, $statement);
    }

    private function tail(UsecaseCursor $cursor, UsecaseElement $first, MermaidStatement $statement): void
    {
        if ('' === $cursor->rest()) {
            return;
        }
        if (null !== $this->boundary) {
            throw $statement->error('Relationships must stay outside use-case boundaries');
        }
        $operator = UsecaseRelations::parse($cursor);
        $name = $cursor->name('usecase');
        $target = $this->element($cursor, $name, 'usecase', $statement, position: 'target');
        $cursor->finish();
        $this->diagram->relation(new UsecaseRelation($first->id, $target->id, $operator['arrow'], $operator['kind'], $operator['label'], $operator['id'], $statement, $operator['markdown']));
    }

    /** @param array{id: string, raw: string, label: string, markdown: bool, generated: bool, shaped: bool, shape: string} $name */
    private function element(UsecaseCursor $cursor, array $name, string $kind, MermaidStatement $statement, string $position = 'first'): UsecaseElement
    {
        $hadMetadata = \str_starts_with($cursor->rest(), '@{');
        $metadata = $cursor->metadata();
        $stereotype = $cursor->stereotype();
        $classes = $cursor->classes();
        $declared = 'actor' === $kind || $name['generated'] || $name['shaped'] || $hadMetadata || null !== $stereotype || null !== $this->boundary;
        if ($declared || ('target' !== $position && '' === $cursor->rest())) {
            return $this->diagram->declare($name, $kind, $metadata, $stereotype, $classes, $this->boundary?->id, $statement);
        }
        if ([] !== $classes) {
            throw $statement->error('A relationship endpoint needs a declaration before an inline class suffix');
        }

        return $this->diagram->reference($name['id'], $statement);
    }

    private function note(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^note\s+for\s+/', $statement->text, $match)) {
            throw $statement->error('Expected note for followed by an actor or use case');
        }
        $cursor = new UsecaseCursor($statement->slice(\strlen($match[0])));
        $target = $cursor->identifier();
        $label = $cursor->quoted();
        $cursor->finish();
        $this->diagram->notes[] = ['target' => $target, ...$label, 'statement' => $statement];
    }

    private function json(MermaidStatement $statement): void
    {
        $cursor = new UsecaseCursor($statement->slice(4));
        $name = $cursor->name('json');
        if ($name['generated'] || $name['shaped']) {
            throw $statement->error('A use-case JSON declaration requires a plain identifier');
        }
        $cursor->expect('@');
        $start = $cursor->position;
        $cursor->expect('{');
        $quote = new MermaidQuotedText('"');
        $depth = 1;
        $length = \strlen($cursor->statement->text);
        while ($cursor->position < $length && $depth > 0) {
            $char = $cursor->statement->text[$cursor->position++];
            $quote->advance($char);
            if (!$quote->quoted) {
                $depth += match ($char) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
            }
        }
        $value = UsecaseJson::decode($cursor->statement->slice($start, $cursor->position - $start));
        $classes = $cursor->classes();
        $cursor->finish();
        $name['shape'] = 'json';
        $element = $this->diagram->declare($name, 'json', metadata: [], stereotype: null, classes: $classes, parent: null, statement: $statement);
        $element->json = $value;
    }

    private function style(MermaidStatement $statement): void
    {
        if (1 !== \preg_match('/^(?:classDef|class|style)\s+\S/', $statement->text)) {
            throw $statement->error('Incomplete use-case style declaration');
        }
        if (\str_contains($statement->text, ';')) {
            throw $statement->error('Use-case CSS declarations use commas, not semicolons');
        }
        $this->diagram->styles[] = $statement;
    }

    private function accessibility(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(?:accTitle|accDescr)\s*:\s*(.*)$|^accDescr\s*\{(.*)\}$/s', $statement->text, $match)) {
            throw $statement->error('Invalid use-case accessibility metadata');
        }
        $this->diagram->legend[] = ['text' => \trim($match[2] ?? $match[1]), 'origin' => $statement->line];
    }

    public function finish(): UsecaseDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected a diagram header', offset: 0, sourceLine: 0, column: 0);
        }
        if (null !== $this->boundary) {
            throw $this->boundary->statement->error('Unclosed use-case boundary');
        }
        foreach ($this->diagram->assignments as $assignment) {
            $target = $this->diagram->elements[$assignment['id']] ?? null;
            if (null === $target) {
                throw $assignment['statement']->error('Unresolved use-case metadata target');
            }
            foreach ($assignment['values'] as $property) {
                UsecaseProperties::property($target, $property['key'], $property['value'], $property['statement']);
                $target->metadata[$property['key']] = $property['value'];
            }
        }
        foreach ($this->diagram->elements as $element) {
            UsecaseProperties::validate($element);
        }
        foreach ($this->diagram->relations as $relation) {
            UsecaseProperties::relationship($relation, $this->diagram->elements[$relation->from], $this->diagram->elements[$relation->to]);
        }
        foreach ($this->diagram->notes as $note) {
            $target = $this->diagram->elements[$note['target']] ?? null;
            if (null === $target || !\in_array($target->kind, ['actor', 'usecase'], strict: true)) {
                throw $note['statement']->error('A use-case note requires an actor or use-case target');
            }
        }

        return $this->diagram;
    }
}
