<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** The pinned agentflow vocabulary is deliberately narrower than flowcharts. */
final class AgentflowShapes
{
    public static function resolve(string $shape, MermaidStatement $statement): string
    {
        return match ($shape) {
            'tool', 'subroutine', 'subprocess', 'subproc', 'framed-rectangle' => 'subroutine',
            'input', 'lean-right' => 'lean-right',
            'decision', 'diamond' => 'diamond',
            'action', 'hexagon', 'hex' => 'hexagon',
            'refdoc', 'lin-doc', 'lined-document' => 'lin-doc',
            'task',
            'round',
            'rect',
            'connector',
            'doc',
            'stadium',
            'terminal',
            'circle',
            'trapezoid',
            'inv-trapezoid',
            'doublecircle',
            'double-circle',
            'procs',
            'lean-left',
            'in-out',
            'cylinder',
            'ellipse',
            'odd',
            'tag-rect',
            'tagged-rectangle',
            'delay',
            'half-rounded-rectangle',
            'lin-rect',
            'lined-rectangle',
            'win-pane',
            'window-pane',
            'curv-trap',
            'curved-trapezoid',
                => 'roundedRect',
            default => throw $statement->error('Unknown agentflow shape '.$shape),
        };
    }
}
