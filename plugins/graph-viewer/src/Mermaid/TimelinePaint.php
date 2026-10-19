<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/**
 * Pinned timeline colors: section N and its periods take `cScale` N (each period its own N when
 * there are no sections), events the same color at 120% brightness; redux themes fill with their
 * palette slot borders or the node background, outlined and labeled in the node border.
 */
final readonly class TimelinePaint
{
    private const int SECTIONS = 12;

    public function __construct(
        private MermaidPalette $palette,
    ) {}

    /** @param array<string, int> $sections color index by node name */
    public function paint(GraphLayout $graph, array $sections): GraphLayout
    {
        $nodes = [];
        foreach ($graph->nodes as $id => $node) {
            $index = $sections[$node->name] ?? null;
            $nodes[$id] = null === $index ? $node->painted($node->color ?? 'none', $node->fillColor ?? 'none', $node->textColor ?? '@textColor') : $this->node($node, $index);
        }
        // Each pinned section rule restates `.lineWrapper line`, so the last label color wins.
        $line = \str_contains($this->palette->appearance->theme, 'redux') ? '@nodeBorder' : '@cScaleLabel'.(self::SECTIONS - 1);
        $edges = \array_map(static fn (GraphEdge $edge): GraphEdge => $edge->painted($edge->color ?? $line, $edge->textColor), $graph->edges);

        return new GraphLayout($graph->width, $graph->height, $nodes, $edges, $graph->clusters);
    }

    private function node(GraphNode $node, int $index): GraphNode
    {
        $theme = $this->palette->appearance->theme;
        $index %= self::SECTIONS;
        if (\str_contains($theme, 'redux')) {
            // The pinned redux sections fill with the slot's border color; dark ones keep the node background.
            $border = $this->palette->resolve('@slot:stroke:'.$index);
            $fill = \str_contains($theme, 'dark') ? $this->palette->color('mainBkg') : $border ?? $this->palette->color('mainBkg');

            return $node->painted($node->color ?? $border ?? '@nodeBorder', $node->fillColor ?? self::bright($fill, $node->name), $node->textColor ?? '@nodeBorder');
        }

        return $node->painted($node->color ?? 'none', $node->fillColor ?? self::bright($this->palette->color('cScale'.$index), $node->name), $node->textColor ?? '@cScaleLabel'.$index);
    }

    /** Pinned `.eventWrapper { filter: brightness(120%); }` scales each channel of an event's color. */
    private static function bright(?string $color, string $name): ?string
    {
        if (null === $color || !\str_contains($name, '-event-')) {
            return $color;
        }
        $channels = \array_map(static fn (string $hex): int => (int) \min(255, \round(\hexdec($hex) * 1.2)), \str_split(\substr($color, offset: 1, length: 6), length: 2));

        return \vsprintf('#%02x%02x%02x', $channels).\substr($color, offset: 7);
    }
}
