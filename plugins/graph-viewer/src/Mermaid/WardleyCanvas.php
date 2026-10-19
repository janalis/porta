<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Request-local scene assembly keeps projected coordinates separate from text extents. */
final class WardleyCanvas
{
    /** @var array<int, GraphNode> */
    public array $nodes = [];
    /** @var list<GraphEdge> */
    public array $edges = [];
    /** @var list<GraphCluster> */
    public array $clusters = [];
    /** @var array<int, string> */
    public array $shapes = [];
    /** @var array<int, int> */
    public array $elements = [];
    /** @var list<array{text: string, origin: int}> */
    public array $legend = [];
    /** @var array<array-key, int> */
    public array $labels = [];
    /** @var array<int, int> */
    private array $origins = [];

    public function __construct(
        public readonly float $width,
        public readonly float $height,
    ) {}

    /** @return array{float, float} */
    public function position(float $visibility, float $evolution): array
    {
        return [24.0 + ($evolution * $this->width), 14.0 + ((1 - $visibility) * $this->height)];
    }

    public function point(GraphNode $node, string $shape, int $origin): int
    {
        $this->nodes[$node->id] = $node;
        $this->shapes[$node->id] = $shape;
        $this->elements[$node->id] = $origin;
        $this->origins[$node->id] = $origin;

        return $node->id;
    }

    /** @return \Generator<int, null, mixed, int> */
    public function text(string $name, string $label, float $x, float $y, int $origin, GraphFont $font = GraphFont::Regular): \Generator
    {
        $wrap = MermaidWrap::text(MermaidText::decode($label), width: 36);
        yield from $wrap;
        $text = $wrap->getReturn();
        [$width, $height] = MermaidText::box($text);
        $id = \count($this->nodes);
        // The pinned renderer's own `themeVariables.wardley` reads, with its literal fallbacks:
        // axis and stage captions, else component labels.
        $axis = \in_array($name, ['@visibility', '@evolution'], strict: true) || \str_starts_with($name, '@stage:');
        $color = $axis ? '@!wardley.axisTextColor|primaryTextColor|=#222222' : '@!wardley.componentLabelColor|primaryTextColor|=#222222';
        $this->nodes[$id] = new GraphNode($id, $name, $text, $x, $y, $width, $height, color: null, fillColor: null, dashed: false, textColor: $color, font: $font);
        $this->shapes[$id] = 'text';
        $this->origins[$id] = $origin;

        return $id;
    }

    /** @return \Generator<int, null, mixed, int> */
    public function label(string $name, string $label, float $x, float $y, int $origin): \Generator
    {
        $work = $this->text($name, $label, $x, $y, $origin);
        yield from $work;
        $id = $work->getReturn();
        $node = $this->nodes[$id];
        $this->nodes[$id] = $node->at($x + ($node->w / 2), $node->y);

        return $id;
    }

    /** @param non-empty-list<array{float, float}> $points
     * @param ?array{float, float} $tip
     */
    public function line(
        int $from,
        int $to,
        array $points,
        string $color = '@!wardley.linkStroke|=#000000',
        bool $dashed = false,
        bool $decoration = true,
        ?string $label = null,
        ?array $tip = null,
    ): void {
        $this->edges[] = new GraphEdge(
            $from,
            $to,
            $points,
            $tip,
            \min(\array_column($points, 0)),
            \min(\array_column($points, 1)),
            \max(\array_column($points, 0)),
            \max(\array_column($points, 1)),
            $color,
            $label,
            null === $label ? null : [($points[0][0] + \array_last($points)[0]) / 2, ($points[0][1] + \array_last($points)[1]) / 2],
            $dashed,
            decoration: $decoration,
        );
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function scene(int $origin): \Generator
    {
        $left = 0.0;
        $top = 0.0;
        $right = 30.0 + $this->width;
        $bottom = 28.0 + $this->height;
        foreach ($this->nodes as $node) {
            $left = \min($left, $node->x - ($node->w / 2) - 4);
            $top = \min($top, $node->y - ($node->h / 2) - 4);
            $right = \max($right, $node->x + ($node->w / 2) + 4);
            $bottom = \max($bottom, $node->y + ($node->h / 2) + 4);
            yield null;
        }
        $graph = new GraphLayout($right, $bottom, $this->nodes, $this->edges, $this->clusters);
        $translation = MermaidTranslation::graph($graph, -$left, -$top);
        yield from $translation;
        $graph = $translation->getReturn();
        $rows = MermaidOrigins::rows($graph->height, $origin);
        foreach ($graph->nodes as $id => $node) {
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $this->origins[$id];
                yield null;
            }
        }

        // The pinned title takes the axis text color too.
        return new MermaidScene($graph, $rows, $this->legend, $this->elements, $this->shapes, titleColor: '@!wardley.axisTextColor|primaryTextColor|=#222222');
    }
}
