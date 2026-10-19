<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** One symbol namespace covers nodes, boundaries, JSON declarations and explicit relationships. */
final class UsecaseDiagram
{
    public readonly MermaidGraph $graph;
    /** @var array<array-key, UsecaseElement> */
    public array $elements = [];
    /** @var list<UsecaseRelation> */
    public array $relations = [];
    /** @var list<array{id: string, values: list<array{key: string, value: string|bool, statement: MermaidStatement}>, statement: MermaidStatement}> */
    public array $assignments = [];
    /** @var list<MermaidStatement> */
    public array $styles = [];
    /** @var list<array{target: string, raw: string, label: string, markdown: bool, statement: MermaidStatement}> */
    public array $notes = [];
    /** @var list<array{text: string, origin: int}> */
    public array $legend = [];
    public int $origin = 0;

    public function __construct()
    {
        $this->graph = new MermaidGraph('use case');
        $this->graph->direction = 'LR';
    }

    public function reference(string $id, MermaidStatement $statement): UsecaseElement
    {
        if (!\array_key_exists($id, $this->elements)) {
            if (\count($this->elements) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Use-case diagram exceeds the symbol limit');
            }
            $this->elements[$id] = new UsecaseElement($id, $statement);
        }

        return $this->elements[$id];
    }

    /** @param array{id: string, raw: string, label: string, markdown: bool, generated: bool, shaped: bool, shape: string} $name
     * @param list<array{key: string, value: string|bool, statement: MermaidStatement}> $metadata
     * @param list<string> $classes
     */
    public function declare(array $name, string $kind, array $metadata, ?string $stereotype, array $classes, ?string $parent, MermaidStatement $statement): UsecaseElement
    {
        $element = $this->reference($name['id'], $statement);
        if ($element->declared && 'json' === $kind) {
            throw $statement->error('Duplicate use-case JSON identifier');
        }
        if ($element->declared) {
            $this->repeat($element, $name, $kind, $stereotype, $parent, $statement);
        }
        $element->kind = $kind;
        $element->raw = $name['raw'];
        $element->label = $name['label'];
        $element->markdown = $name['markdown'];
        $element->generated = $name['generated'];
        if (!$element->declared) {
            $element->statement = $statement;
        }
        $element->declared = true;
        if ($name['shaped'] || !$element->shaped) {
            $element->shape = $name['shape'];
        }
        $element->shaped = $element->shaped || $name['shaped'];
        $element->parent ??= $parent;
        $element->stereotype ??= $stereotype;
        $element->classes = \array_values(\array_unique([...$element->classes, ...$classes]));
        foreach ($metadata as $property) {
            $key = $property['key'];
            UsecaseProperties::property($element, $key, $property['value'], $property['statement']);
            if (\array_key_exists($key, $element->metadata) && $element->metadata[$key] !== $property['value']) {
                throw $property['statement']->error('Conflicting use-case declaration metadata');
            }
            $element->metadata[$key] = $property['value'];
        }

        return $element;
    }

    /** @param array{id: string, raw: string, label: string, markdown: bool, generated: bool, shaped: bool, shape: string} $name */
    private function repeat(UsecaseElement $element, array $name, string $kind, ?string $stereotype, ?string $parent, MermaidStatement $statement): void
    {
        $message = match (true) {
            $element->kind !== $kind => 'A use-case identifier cannot declare more than one kind',
            $element->generated || $name['generated'] => 'Generated use-case identifier collides with another declaration',
            $element->raw !== $name['raw'] || $element->markdown !== $name['markdown'] => 'Conflicting use-case labels',
            $name['shaped'] && $element->shaped && $element->shape !== $name['shape'] => 'Conflicting use-case shapes',
            null !== $parent && null !== $element->parent && $parent !== $element->parent => 'A use-case element cannot belong to two boundaries',
            null !== $stereotype && null !== $element->stereotype && $stereotype !== $element->stereotype => 'Conflicting use-case stereotypes',
            default => null,
        };
        if (null !== $message) {
            throw $statement->error($message);
        }
    }

    public function relation(UsecaseRelation $relation): void
    {
        if (\count($this->relations) >= MermaidGraph::MAX_ITEMS) {
            throw $relation->statement->error('Use-case diagram exceeds the relationship limit');
        }
        $this->relations[] = $relation;
        if (null !== $relation->id) {
            $symbol = $this->reference($relation->id, $relation->statement);
            if ($symbol->declared) {
                throw $relation->statement->error('Use-case edge identifier collides with another declaration');
            }
            $symbol->declared = true;
            $symbol->kind = 'edge';
        }
    }
}
