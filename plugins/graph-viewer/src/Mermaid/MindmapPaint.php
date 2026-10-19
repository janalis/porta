<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/**
 * Pinned mindmap sections: each child of the root opens a section (index modulo 11) that its
 * subtree inherits; sections paint from `cScale`, the root from `git0`, and edges thin with depth.
 */
final readonly class MindmapPaint
{
    private const int SECTIONS = 11;

    public function __construct(
        private MermaidPalette $palette,
    ) {}

    public function paint(MermaidGraph $source, MermaidScene $scene): MermaidScene
    {
        [$sections, $levels] = self::sections($source);
        $theme = $this->palette->appearance->theme;
        $neo = 'neo' === $this->palette->appearance->look;
        $redux = \str_contains($theme, 'redux');
        $names = [];
        $nodes = [];
        foreach ($scene->graph->nodes as $id => $node) {
            $names[$id] = $node->name;
            $nodes[$id] = $this->node($node, $sections[$node->name] ?? null, $scene->shapes[$id] ?? 'rect');
        }
        $edges = [];
        foreach ($scene->graph->edges as $edge) {
            $child = $names[$edge->head] ?? '';
            $section = $sections[$child] ?? null;
            $depth = ($levels[$names[$edge->tail] ?? ''] ?? 0) + 1;
            $index = null === $section ? 0 : $section + 1;
            $stroke = $neo && ($redux || 'neo-dark' === $theme) ? '@nodeBorder' : '@cScale'.$index;
            $width = $neo ? \max(10 - ($depth * 2), 2) : 17 - (3 * ($depth + 1));
            $edges[] = $edge->painted($edge->color ?? $stroke, $edge->textColor)->stroked($width / 10);
        }

        return new MermaidScene(
            new GraphLayout($scene->graph->width, $scene->graph->height, $nodes, $edges, $scene->graph->clusters),
            $scene->origins,
            $scene->legend,
            $scene->elements,
            $scene->shapes,
            $scene->background,
            $scene->titleColor,
            $scene->titleScale,
            $scene->palette,
            $scene->runs,
        );
    }

    private function node(GraphNode $node, ?int $section, string $shape): GraphNode
    {
        return null === $section ? $this->root($node) : $this->branch($node, $section + 1, $shape);
    }

    /** The root: `git0` with its branch label color, or the redux node colors under neo. */
    private function root(GraphNode $node): GraphNode
    {
        $theme = $this->palette->appearance->theme;
        $redux = \str_contains($theme, 'redux');
        $neo = 'neo' === $this->palette->appearance->look;
        $fill = $neo && $redux ? '@mainBkg' : '@git0';
        $label = $redux ? '@nodeBorder' : '@cScaleLabel'.('neutral' === $theme ? 1 : 0);
        $text = $neo ? $label : '@gitBranchLabel0';

        return $node->painted($node->color ?? 'none', $node->fillColor ?? $fill, $node->textColor ?? $text);
    }

    private function branch(GraphNode $node, int $index, string $shape): GraphNode
    {
        $theme = $this->palette->appearance->theme;
        $redux = \in_array($theme, ['redux', 'redux-dark'], strict: true);
        $label = $redux ? '@nodeBorder' : '@cScaleLabel'.('neutral' === $theme ? 1 : $index);
        [$stroke, $fill, $text] = 'neo' === $this->palette->appearance->look
            ? [$redux ? '@nodeBorder' : '@cScale'.$index, $redux || 'neutral' === $theme ? '@mainBkg' : '@cScale'.$index, $label]
            : ['mindmap-plain' === $shape ? '@cScaleInv'.$index : 'none', '@cScale'.$index, '@cScaleLabel'.$index];

        return $node->painted($node->color ?? $stroke, $node->fillColor ?? $fill, $node->textColor ?? $text);
    }

    /**
     * @return array{array<string, int>, array<string, int>} section by node, and tree level by node
     */
    private static function sections(MermaidGraph $source): array
    {
        $root = \array_key_first($source->nodes);
        $children = [];
        foreach ($source->edges as $edge) {
            $children[$edge['from']][] = $edge['to'];
        }
        $sections = [];
        $levels = null === $root ? [] : [(string) $root => 0];
        $pending = [];
        foreach ($children[(string) $root] ?? [] as $index => $child) {
            $pending[] = [$child, $index % self::SECTIONS, 1];
        }
        while ([] !== $pending) {
            [$node, $section, $level] = \array_shift($pending);
            $sections[$node] = $section;
            $levels[$node] = $level;
            foreach ($children[$node] ?? [] as $child) {
                $pending[] = [$child, $section, $level + 1];
            }
        }

        return [$sections, $levels];
    }
}
