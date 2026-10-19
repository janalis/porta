<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Native diagnostics name the compatibility target, never an installed MermaidJS version. */
final class InfoParser
{
    public const string LABEL = "Porta native Mermaid\nCompatibility target: v12.1.0";

    private ?MermaidStatement $header = null;
    private bool $showInfo = false;
    private bool $metadata = false;

    public function accept(MermaidStatement $statement): void
    {
        if (null === $this->header) {
            if ('info' !== $statement->text) {
                throw $statement->error('Expected info');
            }
            $this->header = $statement;

            return;
        }
        if ('showInfo' === $statement->text) {
            if ($this->showInfo || $this->metadata) {
                throw $statement->error('showInfo must occur once before metadata');
            }
            $this->showInfo = true;

            return;
        }
        // Upstream validates these declarations but its info renderer intentionally ignores their values.
        if (1 !== \preg_match('/^(?:title(?:[\t ]|$)|accTitle[\t ]*:|accDescr\s*[:{])/', $statement->text)) {
            throw $statement->error('Expected info title or accessibility metadata');
        }
        $this->metadata = true;
    }

    public function finish(): MermaidGraph
    {
        if (null === $this->header) {
            throw new MermaidSyntaxError('Expected info', offset: 0, sourceLine: 0, column: 0);
        }
        $graph = new MermaidGraph();
        $graph->node('info', self::LABEL, $this->header, groups: []);
        $graph->shapes['info'] = 'text';

        return $graph;
    }
}
