<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Agentflow's flow/global scopes and incremental metadata declarations. */
final class AgentflowParser
{
    private readonly AgentflowDiagram $diagram;
    private readonly FlowchartScopes $scopes;
    private bool $header = false;
    /** @var list<string> */
    private array $last = [];
    /** @var list<array{global: bool, opener: MermaidStatement, metadata: ?MermaidStatement}> */
    private array $blocks = [];
    /** @var array<array-key, true> */
    private array $globals = [];
    /** @var array<array-key, true> */
    private array $closed = [];
    private int $anonymous = 0;

    public function __construct()
    {
        $this->diagram = new AgentflowDiagram();
        $this->scopes = new FlowchartScopes($this->diagram->graph);
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = \trim($statement->text);
        if ('' === $text) {
            return;
        }
        if (!$this->header) {
            $match = [];
            if (1 !== \preg_match('/^agentflow-beta(?:\s+(TB|TD|BT|LR|RL))?$/', $text, $match)) {
                throw $statement->error('Expected agentflow-beta and an optional direction');
            }
            $this->diagram->graph->direction = 'TD' === ($match[1] ?? 'TB') ? 'TB' : $match[1] ?? 'TB';
            $this->header = true;

            return;
        }
        match (true) {
            'global' === $text => $this->openGlobal($statement),
            'flow' === $text || \str_starts_with($text, 'flow ') => $this->openFlow($statement),
            'end' === $text => $this->close($statement),
            \str_starts_with($text, '@{') => $this->metadata($statement),
            \str_starts_with($text, 'direction ') => $this->scopes->direction($statement),
            \str_starts_with($text, 'connector ') => $this->connector($statement),
            1 === \preg_match('/^(?:classDef|class|style)\b/', $text) => $this->diagram->graph->styles->accept($statement),
            \str_starts_with($text, 'linkStyle ') => $this->diagram->graph->edgeStyles->accept($statement, \count($this->diagram->graph->edges)),
            default => $this->path($statement),
        };
    }

    private function openGlobal(MermaidStatement $statement): void
    {
        $this->block($statement, global: true);
        $this->last = [];
    }

    private function openFlow(MermaidStatement $statement): void
    {
        $this->block($statement, global: false);
        $body = \trim(\substr(\trim($statement->text), offset: 4));
        if ('' === $body || '[' === $body[0]) {
            $body = '__agentflow_'.++$this->anonymous.$body;
        }
        $this->scopes->open(new MermaidStatement('subgraph '.$body, $statement->offset, $statement->line, $statement->column));
        $this->last = [];
    }

    private function block(MermaidStatement $statement, bool $global): void
    {
        if (\count($this->blocks) >= 128) {
            throw $statement->error('Agentflow nesting exceeds the depth limit');
        }
        $this->blocks[] = ['global' => $global, 'opener' => $statement, 'metadata' => null];
    }

    private function close(MermaidStatement $statement): void
    {
        if ([] === $this->blocks) {
            throw $statement->error('Unexpected end');
        }
        $block = \array_pop($this->blocks);
        $this->last = [];
        if ($block['global']) {
            return;
        }
        $id = $this->scopes->close($statement);
        $this->closed[$id] = true;
        $this->last = [$id];
        $this->globalIds([$id]);
        if (null !== $block['metadata']) {
            $this->apply($id, $block['metadata']);
        }
    }

    private function connector(MermaidStatement $statement): void
    {
        $this->path($statement->slice((int) \strpos($statement->text, needle: 'connector ') + 10));
        foreach ($this->last as $id) {
            $this->diagram->connectors[$id] = true;
            $this->diagram->graph->shapes[$id] = 'roundedRect';
        }
    }

    private function path(MermaidStatement $statement): void
    {
        $graph = $this->diagram->graph;
        $text = \trim($statement->text);
        if (\array_key_exists($text, $graph->edgeIds)) {
            $this->last = [$text];

            return;
        }
        $before = \count($graph->edges);
        $path = new FlowchartPath($graph, $this->scopes->groups());
        $this->last = 1 === \preg_match('/^\s*[<ox]?(?:--|==|-?\.|~~~)/', $statement->text)
            ? $path->continue($statement, $this->last)
            : $path->parse($statement);
        $touched = $this->last;
        foreach (\array_slice($graph->edges, $before) as $edge) {
            $touched[] = $edge['from'];
            $touched[] = $edge['to'];
        }
        $this->globalIds($touched);
    }

    /** @param list<string> $ids */
    private function globalIds(array $ids): void
    {
        if ([] === $this->blocks || !$this->blocks[\count($this->blocks) - 1]['global']) {
            return;
        }
        foreach ($ids as $id) {
            $this->globals[$id] = true;
        }
    }

    private function metadata(MermaidStatement $statement): void
    {
        if ([] === $this->last) {
            if ([] !== $this->blocks) {
                $index = \count($this->blocks) - 1;
                if (!$this->blocks[$index]['global']) {
                    $this->blocks[$index]['metadata'] = $statement;

                    return;
                }
            }
            throw $statement->error('Agentflow metadata requires a node, edge or flow');
        }
        foreach ($this->last as $id) {
            $this->apply($id, $statement);
        }
    }

    private function apply(string $id, MermaidStatement $statement): void
    {
        $values = AgentflowMetadata::parse($statement);
        if ('connectors' === $id && !\array_key_exists($id, $this->closed)) {
            return;
        }
        if (!\array_key_exists($id, $this->closed) && !\array_key_exists($id, $this->diagram->connectors) && \array_key_exists($id, $this->diagram->graph->edgeIds)) {
            $index = $this->diagram->graph->edgeIds[$id];
            $this->diagram->edgeMetadata[$index] = \array_replace($this->diagram->edgeMetadata[$index] ?? [], $values);

            return;
        }
        $this->diagram->metadata[$id] = \array_replace($this->diagram->metadata[$id] ?? [], $values);
        if (!\array_key_exists($id, $this->closed) && !\array_key_exists($id, $this->diagram->connectors)) {
            $this->nodeMetadata($id, $values, $statement);
        }
    }

    /** @param array<array-key, mixed> $values */
    private function nodeMetadata(string $id, array $values, MermaidStatement $statement): void
    {
        // @mago-expect analysis:mixed-assignment -- Metadata scalar fields are narrowed below.
        $label = $values['label'] ?? null;
        if (\is_string($label) && '' !== $label) {
            $this->diagram->graph->nodes[$id]['label'] = MermaidText::decode($label);
        }
        if (\array_key_exists('shape', $values)) {
            // @mago-expect analysis:mixed-assignment -- Shape must be a lower-case authored string.
            $shape = $values['shape'];
            if (!\is_string($shape) || 1 !== \preg_match('/^[a-z][a-z0-9-]*$/', $shape)) {
                throw $statement->error('Agentflow shape must be a lower-case shape name');
            }
            $this->diagram->graph->shapes[$id] = AgentflowShapes::resolve($shape, $statement);
        }
    }

    public function finish(): AgentflowDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected a diagram header', offset: 0, sourceLine: 0, column: 0);
        }
        if ([] !== $this->blocks) {
            throw $this->blocks[\count($this->blocks) - 1]['opener']->error('Unclosed agentflow block');
        }
        $this->scopes->finish();
        $graph = $this->diagram->graph;
        foreach ($graph->groups as $id => &$group) {
            if (!\array_key_exists($id, $this->globals)) {
                continue;
            }
            $group['parent'] = null;
        }
        unset($group);
        foreach ($graph->nodes as $id => &$node) {
            $graph->shapes[$id] ??= 'roundedRect';
            if (\array_key_exists($id, $this->globals)) {
                $node['groups'] = [];
                continue;
            }
            foreach ($node['groups'] as $index => $group) {
                if (!\array_key_exists($group, $this->globals)) {
                    continue;
                }
                $node['groups'] = \array_slice($node['groups'], $index);
            }
        }
        unset($node);

        return $this->diagram;
    }
}
