<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Text, outline fitting and family-specific dimensions use the same intrinsic measurement. */
final class MermaidNodeMetrics
{
    /** @return array{float, float} */
    public static function measure(MermaidGraph $source, string $id): array
    {
        [$width, $height] = $source->styles->measure($id, $source->nodes[$id]['label']);
        $shape = MermaidOutline::canonical($source->shapes[$id] ?? 'rect');
        if (FlowchartShapes::hidesLabel($shape)) {
            return (
                $source->sizes[$id] ?? match ($shape) {
                    'sm-circ', 'f-circ' => [2.0, 2.0],
                    'fr-circ' => [4.0, 4.0],
                    'fork' => \in_array($source->direction, ['LR', 'RL'], strict: true) ? [1.5, 12.0] : [12.0, 1.5],
                    'bolt' => [5.0, 8.0],
                    default => [6.0, 6.0],
                }
            );
        }
        $padding = $source->flowchart?->spacing('padding', default: 2.0) ?? 2.0;
        $minimum = $source->flowchart?->spacing('minNodeWidth', default: 0.0) ?? 0.0;
        $width = \max($width - 4, $minimum) + (2 * $padding);
        $height = $height - 4 + (2 * $padding);
        if (\in_array($shape, ['circle', 'doublecircle'], strict: true)) {
            $width = \max($width, $height);
            $height = $width;
        }
        if (\in_array($shape, ['tri', 'flip-tri'], strict: true)) {
            // Pinned `triangle`: a square box, the padded label width plus the label height.
            $width += $height - (2 * $padding);
            $height = $width;
        }
        [$width, $height] = match ($shape) {
            'delay', 'curv-trap', 'trap-b', 'trap-t' => [$width + $height, $height],
            // The slanted sides and the hexagon's points widen the shape instead of narrowing its label.
            'lean-r', 'lean-l' => [$width + ($height / 2), $height],
            'hexagon' => [$width + (($height * 4) / 7), $height],
            'h-cyl', 'bow-rect' => [$width + 6, $height],
            'lin-cyl' => [$width + 4, (2 * $height) + 4],
            'person' => [\max(10.0, $width + 4), $height + (1.73 * FlowchartShapeGeometry::personHead(\max(10.0, $width + 4)))],
            'folder', 'bucket', 'browser', 'console' => [$width + 4, $height + 6],
            'brace', 'brace-r', 'braces' => [$width + 4, $height],
            'doc', 'tag-doc', 'flag', 'div-rect' => [$width + 2, $height + 2],
            'st-rect', 'docs', 'win-pane', 'tag-rect', 'lin-rect' => [$width + 4, $height + 4],
            default => [$width, $height],
        };
        [$width, $height] = $source->sizes[$id] ?? [$width, $height];

        return [$width, $height];
    }
}
