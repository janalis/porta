<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Upstream swimlanes reuse flowchart syntax and replace its placement algorithm. */
final class SwimlaneParser
{
    private readonly FlowchartParser $parser;
    private bool $header = false;

    public function __construct()
    {
        $this->parser = new FlowchartParser();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            $match = [];
            if (1 !== \preg_match('/^swimlane-beta(?:\s+(TB|TD|BT|LR|RL))?$/', $statement->text, $match)) {
                throw $statement->error('Expected swimlane-beta and an optional direction');
            }
            $statement = new MermaidStatement('flowchart '.($match[1] ?? 'TB'), $statement->offset, $statement->line, $statement->column);
            $this->header = true;
        }
        $this->parser->accept($statement);
    }

    public function finish(): MermaidGraph
    {
        return $this->parser->finish();
    }
}
