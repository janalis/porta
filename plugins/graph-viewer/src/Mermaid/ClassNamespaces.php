<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Dotted paths and lexical nesting resolve to the same namespace tree. */
final class ClassNamespaces
{
    /** @var list<string> */
    private array $stack = [];
    /** @var array<string, true> Namespaces a statement names, as opposed to the paths leading to them. */
    public array $declared = [];

    public function __construct(
        private readonly MermaidGraph $graph,
    ) {}

    public function accept(MermaidStatement $statement): void
    {
        if ('}' === $statement->text) {
            if ([] === $this->stack) {
                throw $statement->error('Unexpected namespace closing brace');
            }
            \array_pop($this->stack);

            return;
        }
        $match = [];
        \preg_match('!^namespace\s+('.ClassLexer::NAME.')(?:\s*\[\s*"([^"]*)"\s*\])?\s*\{$!u', $statement->text, $match);
        $prefix = $this->stack[\count($this->stack) - 1] ?? '';
        $name = ('' === $prefix ? '' : $prefix.'.').\str_replace('`', replace: '', subject: $match[1]);
        $parts = \explode('.', $name);
        if (\count($parts) > 128) {
            throw $statement->error('Diagram exceeds the class namespace limit');
        }
        $parent = null;
        $path = '';
        foreach ($parts as $part) {
            $path .= '' === $path ? $part : '.'.$part;
            $id = 'namespace:'.$path;
            if (!\array_key_exists($id, $this->graph->groups) && \count($this->graph->groups) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the class namespace limit');
            }
            $this->graph->groups[$id] ??= [
                'label' => $part,
                'origin' => $statement->line,
                'offset' => $statement->offset,
                'parent' => $parent,
                'direction' => $this->graph->direction,
                'explicit' => false,
            ];
            $parent = $id;
        }
        if ('' !== ($match[2] ?? '')) {
            $this->graph->groups['namespace:'.$name]['label'] = MermaidText::decode($match[2]);
        }
        $this->declared['namespace:'.$name] = true;
        $this->stack[] = $name;
    }

    /** @return list<string> */
    public function groups(): array
    {
        $current = $this->stack[\count($this->stack) - 1] ?? '';
        if ('' === $current) {
            return [];
        }
        $groups = [];
        $path = '';
        foreach (\explode('.', $current) as $part) {
            $path .= '' === $path ? $part : '.'.$part;
            $groups[] = 'namespace:'.$path;
        }

        return $groups;
    }

    public function finish(): void
    {
        if ([] !== $this->stack) {
            throw new MermaidSyntaxError('Unclosed class namespace', offset: 0, sourceLine: $this->graph->groups['namespace:'.$this->stack[\count($this->stack) - 1]]['origin'], column: 0);
        }
    }
}
