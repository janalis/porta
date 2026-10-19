<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Flow nodes inherit the indentation of their owning block node, not their column. */
final class SequenceYamlFlowContext
{
    public private(set) int $indent = 0;
    public private(set) int $nodeIndent = -1;
    public private(set) bool $mappingKey = false;
    private int $lineStart = 0;
    private ?int $rootIndent = null;
    private ?int $keyStart = null;
    private ?int $propertyStart = null;
    private int $propertyOwner = -1;
    private int $skipUntil = 0;
    /** @var list<int> */
    private array $parents = [];
    /** @var array<int, bool> */
    private array $mappings = [];

    public function __construct(
        private readonly string $yaml,
        private readonly SequenceYamlSlots $slots,
        private readonly MermaidConfigurationText $text,
    ) {}

    // @mago-expect lint:no-boolean-flag-parameter -- Structural eligibility is a fact from the shared lexical scanner.
    public function advance(int $offset, bool $structural, int $depth): void
    {
        $this->skip($offset);
        if (!$this->eligible($offset, $structural, $depth)) {
            return;
        }
        $char = $this->yaml[$offset];
        $column = $offset - $this->lineStart;
        $this->rootIndent ??= \strspn($this->yaml, characters: ' ', offset: $this->lineStart);
        $indicator = $structural && $this->slots->keyNode && \in_array($char, ['-', '?'], strict: true) && \ctype_space($this->yaml[$offset + 1] ?? "\n");
        $property = $this->text->opensAnchor || $this->text->opensTag;
        if (null === $this->propertyStart && ($this->slots->keyNode || $property)) {
            $this->mappingKey = $this->mappings[$column] ?? false;
        }
        match (true) {
            $indicator => $this->item($column, $char),
            $property => $this->property($offset, $column),
            default => $this->node($offset, $column, $structural, $depth),
        };
    }

    private function item(int $column, string $char): void
    {
        $this->parent($column);
        $this->mappings[$column] = '?' === $char;
        $this->keyStart = null;
        $this->propertyStart = null;
    }

    private function property(int $offset, int $column): void
    {
        $this->keyStart ??= $offset;
        if (null === $this->propertyStart) {
            $this->propertyStart = $offset;
            $this->propertyOwner = $this->owner($column);
            $this->nodeIndent = $this->propertyOwner;
        }
    }

    // @mago-expect lint:no-boolean-flag-parameter -- Structural eligibility is a lexical fact.
    private function node(int $offset, int $column, bool $structural, int $depth): void
    {
        $char = $this->yaml[$offset];
        $this->keyStart ??= $offset;
        if (null !== $this->propertyStart && $column <= $this->propertyOwner) {
            $this->propertyStart = null;
        }
        if ($structural && ':' === $char && (\ctype_space($this->yaml[$offset + 1] ?? "\n") || \in_array($this->yaml[$offset - 1] ?? '', ['"', "'"], strict: true))) {
            $keyColumn = $this->keyStart - $this->lineStart;
            $this->parent($keyColumn);
            $this->mappings[$keyColumn] = true;
            $this->propertyStart = null;

            return;
        }
        if ($this->slots->depth > $depth) {
            $start = $this->propertyStart ?? $offset;
            $line = $start;
            while ($line > 0 && "\n" !== $this->yaml[$line - 1]) {
                --$line;
            }
            $this->indent = $this->owner($start - $line) + 1;
        }
        $this->propertyStart = null;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- Structural eligibility is a lexical fact.
    private function eligible(int $offset, bool $structural, int $depth): bool
    {
        $char = $this->yaml[$offset];
        $property = $this->text->opensAnchor || $this->text->opensTag;
        if ($depth > 0 || $offset < $this->skipUntil || (!$structural && !$property && !$this->text->opensQuote && !$this->text->plain) || \ctype_space($char)) {
            return false;
        }
        $marker = $this->documentMarker($offset);
        if ($marker) {
            $this->skipUntil = $offset + 3;
        }

        return !$marker;
    }

    private function documentMarker(int $offset): bool
    {
        return (
            \ctype_space($this->yaml[$offset + 3] ?? "\n")
            && \in_array($this->yaml[$offset], ['-', '.'], strict: true)
            && \in_array(\substr($this->yaml, $offset, length: 3), ['---', '...'], strict: true)
            && $offset === ($this->lineStart + \strspn($this->yaml, characters: " \t", offset: $this->lineStart))
        );
    }

    /** Already-normalized scalar bytes still advance physical line positions. */
    public function skip(int $offset): void
    {
        if ("\n" === $this->yaml[$offset]) {
            $this->lineStart = $offset + 1;
            $this->keyStart = null;
        }
    }

    /** Advance physical line positions across a decoded scalar range. */
    public function skipRange(int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; ++$offset) {
            $this->skip($offset);
        }
    }

    private function parent(int $column): void
    {
        while ([] !== $this->parents && \array_last($this->parents) >= $column) {
            $parent = \array_pop($this->parents);
            unset($this->mappings[$parent]);
        }
        $this->parents[] = $column;
        $this->nodeIndent = $column;
    }

    private function owner(int $column): int
    {
        for ($index = \count($this->parents) - 1; $index >= 0; --$index) {
            if ($this->parents[$index] < $column) {
                return $this->parents[$index];
            }
        }

        return ($this->rootIndent ?? 0) - 1;
    }
}
