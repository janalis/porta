<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Mermaid 12.1 flowchart syntax; node styles share the native scene styling model. */
final class FlowchartParser
{
    private readonly MermaidGraph $graph;
    private readonly FlowchartScopes $scopes;
    private bool $header = false;
    /** @var list<string> */
    private array $last = [];

    public function __construct(?FlowchartConfiguration $configuration = null)
    {
        $this->graph = new MermaidGraph(flowchart: $configuration);
        $this->graph->colorSlots = MermaidColorSlots::Clusters;
        $this->graph->rankGap = $configuration?->spacing('rankSpacing', default: 8.0) ?? 8.0;
        $this->graph->nodeGap = $configuration?->spacing('nodeSpacing', default: 6.0) ?? 6.0;
        $this->graph->padding = $configuration?->spacing('diagramPadding', default: 4.0) ?? 4.0;
        $this->scopes = new FlowchartScopes($this->graph);
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = $statement->text;
        match (true) {
            !$this->header => $this->header($statement),
            \str_starts_with($text, 'subgraph ') => $this->scopes->open($statement),
            'end' === $text => $this->scopes->close($statement),
            \str_starts_with($text, 'direction ') => $this->scopes->direction($statement),
            1 === \preg_match('/^(?:classDef|class|style|linkStyle|click)\b/', $text) => $this->decoration($statement),
            default => $this->path($statement),
        };
    }

    private function path(MermaidStatement $statement): void
    {
        $path = new FlowchartPath($this->graph, $this->scopes->groups());
        $this->last = 1 === \preg_match('/^[<ox]?(?:--|==|-?\.|~~~)/', $statement->text)
            ? $path->continue($statement, $this->last)
            : $path->parse($statement);
    }

    public function finish(): MermaidGraph
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected a diagram header', offset: 0, sourceLine: 0, column: 0);
        }

        $this->scopes->finish();

        return $this->graph;
    }

    private function header(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(?:flowchart(?:-elk)?|graph)(?:\s+(TB|TD|BT|LR|RL))?(?:\s+(.*))?$/s', $statement->text, $match)) {
            throw $statement->error('Expected flowchart or graph with optional direction TB, TD, BT, LR or RL');
        }
        $this->header = true;
        $direction = $match[1] ?? '';
        $this->graph->direction = '' === $direction || 'TD' === $direction ? 'TB' : $direction;
        if ('' !== ($match[2] ?? '')) {
            $relative = (int) \strpos($statement->text, $match[2]);
            $this->last = new FlowchartPath($this->graph, $this->scopes->groups())->parse(
                $statement->slice($relative),
            );
        }
    }

    private function decoration(MermaidStatement $statement): void
    {
        if (1 === \preg_match('/^(?:classDef|class|style)\b/', $statement->text)) {
            $this->graph->styles->accept($statement);

            return;
        }
        if (\str_starts_with($statement->text, 'linkStyle')) {
            $this->graph->edgeStyles->accept($statement, \count($this->graph->edges));

            return;
        }
        $valid = 1 === \preg_match(
            '/^click\s+[\w.-]+\s+(?:(?:href\s+)?"[^"]+"|(?:call\s+)?[\w.]+(?:\([^)]*\))?)(?:\s+"[^"]*")?(?:\s+_(?:blank|self|parent|top))?$/u',
            $statement->text,
        );
        if (!$valid) {
            throw $statement->error('Invalid style, class or click declaration');
        }
    }
}
