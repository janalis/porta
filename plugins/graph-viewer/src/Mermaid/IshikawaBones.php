<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Alternating horizontal/diagonal bones, with iterative preorder/postorder vertical allocation. */
final class IshikawaBones
{
    /** @param array<int, float> $heights
     * @return \Generator<int, null, mixed, array<int, array{from: array{float, float}, to: array{float, float}}>>
     */
    public function branch(IshikawaDiagram $source, int $cause, float $startX, int $direction, array $heights): \Generator
    {
        $order = [];
        $stack = [[$cause, false]];
        while ([] !== $stack) {
            [$id, $visited] = \array_pop($stack);
            $pre = 0 === ($source->nodes[$id]['depth'] % 2);
            if ($visited || $pre) {
                $order[] = $id;
            }
            if (!$visited) {
                if (!$pre) {
                    $stack[] = [$id, true];
                }
                $children = $source->children[$id] ?? [];
                if ($direction > 0) {
                    $children = \array_reverse($children);
                }
                foreach ($children as $child) {
                    $stack[] = [$child, false];
                }
            }
            yield null;
        }
        $ys = [];
        $extent = 8.0;
        foreach ($order as $id) {
            $extent += ($heights[$id] / 2) + 3;
            $ys[$id] = $extent * $direction;
            $extent += ($heights[$id] / 2) + 3;
            yield null;
        }
        $bones = [$cause => ['from' => [$startX, 0.0], 'to' => [$startX - ($extent * 0.14), $ys[$cause]]]];
        $pending = \array_reverse($source->children[$cause] ?? []);
        $counts = [];
        while ([] !== $pending) {
            $id = \array_pop($pending);
            $parent = $source->nodes[$id]['parent'] ?? $cause;
            $bones[$id] = self::attach($source, $id, $parent, $bones[$parent], $ys[$id], $counts);
            foreach (\array_reverse($source->children[$id] ?? []) as $child) {
                $pending[] = $child;
            }
            yield null;
        }

        return $bones;
    }

    /** @param array{from: array{float, float}, to: array{float, float}} $bone
     * @param array<int, int> $counts
     * @return array{from: array{float, float}, to: array{float, float}}
     */
    private static function attach(IshikawaDiagram $source, int $id, int $parent, array $bone, float $y, array &$counts): array
    {
        if (0 === ($source->nodes[$id]['depth'] % 2)) {
            $span = $bone['to'][1] - $bone['from'][1];
            $ratio = 0.0 === $span ? 0.5 : ($y - $bone['from'][1]) / $span;
            $x = $bone['from'][0] + (($bone['to'][0] - $bone['from'][0]) * $ratio);
            $length = 8.0 + (3 * \count($source->children[$id] ?? []));

            return ['from' => [$x, $y], 'to' => [$x - $length, $y]];
        }
        $count = \count($source->children[$parent]);
        $drawn = $counts[$parent] ?? 0;
        $counts[$parent] = $drawn + 1;
        $ratio = ($count - $drawn) / ($count + 1);
        $x = $bone['from'][0] + (($bone['to'][0] - $bone['from'][0]) * $ratio);
        $fromY = $bone['from'][1];

        return ['from' => [$x, $fromY], 'to' => [$x - (0.14 * \abs($y - $fromY)), $y]];
    }
}
