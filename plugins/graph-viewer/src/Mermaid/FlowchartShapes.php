<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Pinned shape names and aliases remain separate from terminal-scaled outline geometry. */
final class FlowchartShapes
{
    /** @var array<string, string> Pinned grammar identifiers, not interface labels. */
    private const array CANONICAL = [
        'round' => 'rounded',
        'ellipse' => 'ellipse',
        'rect' => 'rect',
        'proc' => 'rect',
        'process' => 'rect',
        'rectangle' => 'rect',
        'squareRect' => 'rect',
        'rounded' => 'rounded',
        'event' => 'rounded',
        'roundedRect' => 'rounded',
        'stadium' => 'stadium',
        'terminal' => 'stadium',
        'pill' => 'stadium',
        'fr-rect' => 'subroutine',
        'subprocess' => 'subroutine',
        'subproc' => 'subroutine',
        'framed-rectangle' => 'subroutine',
        'subroutine' => 'subroutine',
        'cyl' => 'cylinder',
        'db' => 'cylinder',
        'database' => 'cylinder',
        'cylinder' => 'cylinder',
        'datastore' => 'datastore',
        'data-store' => 'datastore',
        'folder' => 'folder',
        'directory' => 'folder',
        'bucket' => 'bucket',
        'console' => 'console',
        'browser' => 'browser',
        'person' => 'person',
        'circle' => 'circle',
        'circ' => 'circle',
        'bang' => 'bang',
        'cloud' => 'cloud',
        'diam' => 'diamond',
        'decision' => 'diamond',
        'diamond' => 'diamond',
        'question' => 'diamond',
        'hex' => 'hexagon',
        'hexagon' => 'hexagon',
        'prepare' => 'hexagon',
        'lean-r' => 'lean-r',
        'lean-right' => 'lean-r',
        'in-out' => 'lean-r',
        'lean_right' => 'lean-r',
        'lean-l' => 'lean-l',
        'lean-left' => 'lean-l',
        'out-in' => 'lean-l',
        'lean_left' => 'lean-l',
        'trap-b' => 'trap-b',
        'priority' => 'trap-b',
        'trapezoid-bottom' => 'trap-b',
        'trapezoid' => 'trap-b',
        'trap-t' => 'trap-t',
        'manual' => 'trap-t',
        'trapezoid-top' => 'trap-t',
        'inv-trapezoid' => 'trap-t',
        'inv_trapezoid' => 'trap-t',
        'dbl-circ' => 'doublecircle',
        'double-circle' => 'doublecircle',
        'doublecircle' => 'doublecircle',
        'text' => 'text',
        'notch-rect' => 'notch-rect',
        'card' => 'notch-rect',
        'notched-rectangle' => 'notch-rect',
        'lin-rect' => 'lin-rect',
        'lined-rectangle' => 'lin-rect',
        'lined-process' => 'lin-rect',
        'lin-proc' => 'lin-rect',
        'shaded-process' => 'lin-rect',
        'sm-circ' => 'sm-circ',
        'start' => 'sm-circ',
        'small-circle' => 'sm-circ',
        'stateStart' => 'sm-circ',
        'fr-circ' => 'fr-circ',
        'stop' => 'fr-circ',
        'framed-circle' => 'fr-circ',
        'stateEnd' => 'fr-circ',
        'fork' => 'fork',
        'join' => 'fork',
        'forkJoin' => 'fork',
        'hourglass' => 'hourglass',
        'collate' => 'hourglass',
        'brace' => 'brace',
        'comment' => 'brace',
        'brace-l' => 'brace',
        'brace-r' => 'brace-r',
        'braces' => 'braces',
        'bolt' => 'bolt',
        'com-link' => 'bolt',
        'lightning-bolt' => 'bolt',
        'doc' => 'doc',
        'document' => 'doc',
        'delay' => 'delay',
        'half-rounded-rectangle' => 'delay',
        'h-cyl' => 'h-cyl',
        'das' => 'h-cyl',
        'horizontal-cylinder' => 'h-cyl',
        'lin-cyl' => 'lin-cyl',
        'disk' => 'lin-cyl',
        'lined-cylinder' => 'lin-cyl',
        'curv-trap' => 'curv-trap',
        'curved-trapezoid' => 'curv-trap',
        'display' => 'curv-trap',
        'div-rect' => 'div-rect',
        'div-proc' => 'div-rect',
        'divided-rectangle' => 'div-rect',
        'divided-process' => 'div-rect',
        'tri' => 'tri',
        'extract' => 'tri',
        'triangle' => 'tri',
        'win-pane' => 'win-pane',
        'internal-storage' => 'win-pane',
        'window-pane' => 'win-pane',
        'f-circ' => 'f-circ',
        'junction' => 'f-circ',
        'filled-circle' => 'f-circ',
        'notch-pent' => 'notch-pent',
        'loop-limit' => 'notch-pent',
        'notched-pentagon' => 'notch-pent',
        'flip-tri' => 'flip-tri',
        'manual-file' => 'flip-tri',
        'flipped-triangle' => 'flip-tri',
        'sl-rect' => 'sl-rect',
        'manual-input' => 'sl-rect',
        'sloped-rectangle' => 'sl-rect',
        'docs' => 'docs',
        'documents' => 'docs',
        'st-doc' => 'docs',
        'stacked-document' => 'docs',
        'st-rect' => 'st-rect',
        'procs' => 'st-rect',
        'processes' => 'st-rect',
        'stacked-rectangle' => 'st-rect',
        'bow-rect' => 'bow-rect',
        'stored-data' => 'bow-rect',
        'bow-tie-rectangle' => 'bow-rect',
        'cross-circ' => 'cross-circ',
        'summary' => 'cross-circ',
        'crossed-circle' => 'cross-circ',
        'tag-doc' => 'tag-doc',
        'tagged-document' => 'tag-doc',
        'tag-rect' => 'tag-rect',
        'tagged-rectangle' => 'tag-rect',
        'tag-proc' => 'tag-rect',
        'tagged-process' => 'tag-rect',
        'flag' => 'flag',
        'paper-tape' => 'flag',
        'odd' => 'asymmetric',
        'rect_left_inv_arrow' => 'asymmetric',
        'lin-doc' => 'lin-doc',
        'lined-document' => 'lin-doc',
    ];

    public static function canonical(string $shape): string
    {
        return self::CANONICAL[$shape] ?? $shape;
    }

    public static function custom(string $shape): bool
    {
        return \in_array(
            $shape,
            [
                'datastore',
                'folder',
                'bucket',
                'console',
                'browser',
                'person',
                'lin-rect',
                'sm-circ',
                'fr-circ',
                'fork',
                'hourglass',
                'brace',
                'brace-r',
                'braces',
                'bolt',
                'doc',
                'delay',
                'h-cyl',
                'lin-cyl',
                'curv-trap',
                'div-rect',
                'win-pane',
                'f-circ',
                'notch-pent',
                'docs',
                'st-rect',
                'bow-rect',
                'cross-circ',
                'tag-doc',
                'tag-rect',
                'flag',
            ],
            strict: true,
        );
    }

    public static function hidesLabel(string $shape): bool
    {
        // Kanban cards draw their own left-aligned text.
        return \in_array($shape, ['sm-circ', 'fr-circ', 'f-circ', 'cross-circ', 'fork', 'hourglass', 'bolt', 'kanban-card'], strict: true);
    }

    public static function known(string $shape): bool
    {
        return (
            \array_key_exists($shape, self::CANONICAL)
            || \in_array(
                $shape,
                [
                    'state',
                    'choice',
                    'note',
                    'composite',
                    'rectWithTitle',
                    'labelRect',
                    'block_arrow',
                    'collapsedGroup',
                    'iconSquare',
                    'iconCircle',
                    'icon',
                    'iconRounded',
                    'imageSquare',
                    'anchor',
                    'kanbanItem',
                    'mindmapCircle',
                    'defaultMindmapNode',
                    'erBox',
                    'classBox',
                    'requirementBox',
                ],
                strict: true,
            )
        );
    }

    /** @return array{float, float} */
    public static function labelOffset(string $shape, GraphNode $node): array
    {
        return match (true) {
            \str_starts_with($shape, 'fc-icon:'), \str_starts_with($shape, 'fc-image:') => [0.0, FlowchartMedia::labelOffset($shape, $node)],
            // A tree view icon stands before its label.
            \str_starts_with($shape, 'tv-icon:') => [(TreeViewIcons::SPAN / 2) / $node->w, 0.0],
            default => self::shapeOffset($shape, $node),
        };
    }

    /** @return array{float, float} */
    private static function shapeOffset(string $shape, GraphNode $node): array
    {
        return match ($shape) {
            'folder', 'bucket', 'browser', 'console' => [0.0, 0.12],
            'person' => [0.0, FlowchartShapeGeometry::headBand($node) / (2 * $node->h)],
            'lin-cyl' => [0.0, 0.2],
            'doc', 'tag-doc' => [0.0, -0.05],
            'st-rect', 'docs' => [-0.05, 0.05],
            'win-pane' => [0.05, 0.05],
            'lin-rect' => [0.04, 0.0],
            'div-rect' => [0.0, -0.08],
            'h-cyl' => [0.03, 0.0],
            'bow-rect' => [-0.03, 0.0],
            'brace' => [0.04, 0.0],
            'brace-r' => [-0.04, 0.0],
            // Sequence glyphs keep their label on the header's last line, beneath the glyph, and
            // message text sits on the last line of its box, just above the arrow.
            'seq-actor', 'seq-boundary', 'seq-control', 'seq-entity', 'seq-database', 'seq-message' => [
                0.0,
                ((0.5 * $node->h) - 0.5 - (\substr_count($node->drawLabel ?? $node->label, needle: "\n") + 1)) / $node->h,
            ],
            default => [0.0, 0.0],
        };
    }
}
