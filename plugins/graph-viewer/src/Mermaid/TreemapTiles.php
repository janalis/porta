<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Iterative weighted binary tiling, with bounded nesting and no overflowing authored sums. */
final class TreemapTiles
{
    /** @return \Generator<int, null, mixed, array{array<int, array{x: float, y: float, w: float, h: float}>, float, float}> */
    public function rectangles(TreemapDiagram $source, float $top): \Generator
    {
        $measure = self::weights($source);
        yield from $measure;
        $weights = $measure->getReturn();
        $depth = \max([0, ...\array_column($source->nodes, 'depth')]);
        $width = \max(220.0, ($depth * 12.0) + 80);
        $height = \max(140.0, ($depth * 12.0) + 80);
        $rectangles = [];
        $stack = [['ids' => $source->children[-1] ?? [], 'box' => ['x' => 2.0, 'y' => $top, 'w' => $width, 'h' => $height]]];
        while ([] !== $stack) {
            $next = \array_pop($stack);
            $split = self::split($next['ids'], $weights, $next['box']);
            yield from $split;
            foreach ($split->getReturn() as $id => $box) {
                $rectangles[$id] = $box;
                if ([] !== ($source->children[$id] ?? [])) {
                    $stack[] = [
                        'ids' => $source->children[$id],
                        'box' => ['x' => $box['x'] + \min(2.0, $box['w'] / 2), 'y' => $box['y'] + \min(8.0, $box['h']), 'w' => \max(0.0, $box['w'] - 4), 'h' => \max(0.0, $box['h'] - 10)],
                    ];
                }
                yield null;
            }
        }
        \ksort($rectangles);

        return [$rectangles, $width + 4, $height + $top + 2];
    }

    /** @return \Generator<int, null, mixed, list<float>> */
    private static function weights(TreemapDiagram $source): \Generator
    {
        $maximum = 0.0;
        foreach ($source->nodes as $item) {
            $maximum = \max($maximum, $item['value'] ?? 0.0);
            yield null;
        }
        $maximum = $maximum > 0 ? $maximum : 1.0;
        $weights = \array_fill(0, count: \count($source->nodes), value: 0.0);
        for ($id = \count($source->nodes) - 1; $id >= 0; --$id) {
            $item = $source->nodes[$id];
            if (null !== $item['value']) {
                $weights[$id] = $item['value'] / $maximum;
            }
            if (null !== $item['parent']) {
                $weights[$item['parent']] += $weights[$id];
            }
            yield null;
        }

        return $weights;
    }

    /** @param list<int> $ids
     * @param list<float> $weights
     * @param array{x: float, y: float, w: float, h: float} $box
     * @return \Generator<int, null, mixed, array<int, array{x: float, y: float, w: float, h: float}>>
     */
    private static function split(array $ids, array $weights, array $box): \Generator
    {
        $rectangles = [];
        $stack = [[$ids, $box]];
        while ([] !== $stack) {
            [$ids, $box] = \array_pop($stack);
            $count = \count($ids);
            if ($count < 2) {
                if (1 === $count) {
                    $rectangles[$ids[0]] = $box;
                }
                yield null;
                continue;
            }
            $total = 0.0;
            foreach ($ids as $id) {
                $total += $weights[$id];
                yield null;
            }
            $cut = 1;
            $sum = $weights[$ids[0]];
            while ($cut < ($count - 1) && $sum < ($total / 2)) {
                $sum += $weights[$ids[$cut]];
                ++$cut;
                yield null;
            }
            $ratio = $total > 0 ? $sum / $total : $cut / $count;
            $axis = $box['w'] >= $box['h'] ? 'w' : 'h';
            $coordinate = 'w' === $axis ? 'x' : 'y';
            $first = $box;
            $second = $box;
            $first[$axis] *= $ratio;
            $second[$coordinate] += $first[$axis];
            $second[$axis] -= $first[$axis];
            $stack[] = [\array_slice($ids, $cut), $second];
            $stack[] = [\array_slice($ids, offset: 0, length: $cut), $first];
            yield null;
        }

        return $rectangles;
    }
}
