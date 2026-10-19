<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Alternating cause pairs extend left from the effect, with source-linked labels at each bone. */
final class IshikawaLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(IshikawaDiagram $source): \Generator
    {
        $labels = [];
        $sizes = [];
        $heights = [];
        foreach ($source->nodes as $id => $item) {
            $wrap = MermaidWrap::text($item['label'], width: 24);
            yield from $wrap;
            $labels[$id] = $wrap->getReturn();
            $sizes[$id] = MermaidText::box($labels[$id]);
            $heights[$id] = $sizes[$id][1];
            yield null;
        }
        $positions = self::positions($source, $sizes, $heights);
        yield from $positions;
        [$boxes, $bones, $minimum, $offsetX, $offsetY] = $positions->getReturn();
        $nodes = [];
        $elements = [];
        $shapes = [];
        $legend = [];
        $width = 0.0;
        $height = 0.0;
        foreach ($source->nodes as $id => $item) {
            $box = $boxes[$id];
            [$w, $h] = $sizes[$id];
            $shape = match (true) {
                0 === $id => 'fishhead',
                1 === $item['depth'] => 'rect',
                default => 'text',
            };
            $nodes[$id] = new GraphNode(
                $id,
                'ishikawa-'.$id,
                $item['label'],
                $box['x'] + $offsetX,
                $box['y'] + $offsetY,
                $w,
                $h,
                '@lineColor',
                'text' === $shape ? null : '@mainBkg',
                dashed: false,
                textColor: '@textColor',
                drawLabel: $labels[$id],
            );
            $shapes[$id] = $shape;
            $elements[$id] = $item['origin'];
            $legend[] = ['text' => $item['label'], 'origin' => $item['origin']];
            $width = \max($width, $nodes[$id]->x + ($w / 2) + 4);
            $height = \max($height, $nodes[$id]->y + ($h / 2) + 4);
            yield null;
        }
        $edges = [];
        if ([] !== $bones) {
            $edges[] = self::edge(0, 0, [$minimum + $offsetX, $offsetY], [8.0 + $offsetX, $offsetY]);
        }
        foreach ($bones as $id => $bone) {
            $from = [$bone['to'][0] + $offsetX, $bone['to'][1] + $offsetY];
            $to = [$bone['from'][0] + $offsetX, $bone['from'][1] + $offsetY];
            $edges[] = self::edge($id, $source->nodes[$id]['parent'] ?? 0, $from, $to);
            yield null;
        }
        $origins = MermaidOrigins::rows($height + 2, $source->origin);
        foreach ($nodes as $id => $node) {
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $elements[$id];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, clusters: []), $origins, $legend, $elements, $shapes);
    }

    /** @param array<int, array{float, float}> $sizes
     * @param array<int, float> $heights
     * @return \Generator<int, null, mixed, array{array<int, array{x: float, y: float}>, array<int, array{from: array{float, float}, to: array{float, float}}>, float, float, float}>
     */
    private static function positions(IshikawaDiagram $source, array $sizes, array $heights): \Generator
    {
        $boxes = [0 => ['x' => 8.0 + ($sizes[0][0] / 2), 'y' => 0.0]];
        $bones = [];
        $cursor = 0.0;
        $minimum = 0.0;
        $causes = $source->children[0] ?? [];
        foreach (\array_chunk($causes, length: 2) as $pair) {
            $left = $cursor;
            foreach ($pair as $side => $cause) {
                $direction = 0 === $side ? -1 : 1;
                $work = new IshikawaBones()->branch($source, $cause, $cursor - 16, $direction, $heights);
                yield from $work;
                foreach ($work->getReturn() as $id => $bone) {
                    $bones[$id] = $bone;
                    $main = $id === $cause;
                    $diagonal = 1 === ($source->nodes[$id]['depth'] % 2);
                    $x = $bone['to'][0] - ($main ? 0.0 : ($sizes[$id][0] / 2) + 1);
                    $y = $bone['to'][1] + ($diagonal ? $direction * (($sizes[$id][1] / 2) + 1) : 0.0);
                    $boxes[$id] = ['x' => $x, 'y' => $y];
                    $left = \min($left, $x - ($sizes[$id][0] / 2));
                    yield null;
                }
            }
            $cursor = $left - 8;
            $minimum = $left;
        }
        $top = 0.0;
        foreach ($boxes as $id => $box) {
            $top = \min($top, $box['y'] - ($sizes[$id][1] / 2));
            yield null;
        }
        $offsetX = 4.0 - $minimum;
        $offsetY = 4.0 - $top;

        return [$boxes, $bones, $minimum, $offsetX, $offsetY];
    }

    /** @param array{float, float} $from
     * @param array{float, float} $to
     */
    private static function edge(int $tail, int $head, array $from, array $to): GraphEdge
    {
        return new GraphEdge($tail, $head, [$from, $to], $to, \min($from[0], $to[0]), \min($from[1], $to[1]), \max($from[0], $to[0]), \max($from[1], $to[1]), '@lineColor', null, null, dashed: false);
    }
}
