<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Independent axis constraints preserve explicit rows/columns and separate repeated port peers. */
final class ArchitectureGrid
{
    /**
     * @param array<array-key, GraphNode> $items
     * @param list<array{from: string, to: string, left: string, right: string}> $edges
     * @param list<array{direction: string, members: list<string>}> $hints
     * @return \Generator<int, null, mixed, GraphLayout>
     */
    public static function place(array $items, array $edges, array $hints): \Generator
    {
        $keys = \array_map(static fn (string|int $id): string => (string) $id, \array_keys($items));
        $x = self::axis($keys, $edges, $hints, 'x');
        yield from $x;
        $y = self::axis($keys, $edges, $hints, 'y');
        yield from $y;
        $positions = [];
        $occupied = [];
        $columns = [];
        foreach ($edges as $edge) {
            if (\in_array($edge['left'], ['L', 'R'], strict: true)) {
                $columns[$edge['from']] = true;
            }
            if (\in_array($edge['right'], ['L', 'R'], strict: true)) {
                $columns[$edge['to']] = true;
            }
            yield null;
        }
        foreach ($hints as $hint) {
            if ('column' === $hint['direction']) {
                foreach ($hint['members'] as $id) {
                    $columns[$id] = true;
                }
            }
            if ('row' === $hint['direction']) {
                foreach ($hint['members'] as $id) {
                    unset($columns[$id]);
                }
            }
            yield null;
        }
        foreach ($keys as $id) {
            $cx = $x->getReturn()[$id];
            $cy = $y->getReturn()[$id];
            while (\array_key_exists($cx.':'.$cy, $occupied)) {
                if (\array_key_exists($id, $columns)) {
                    ++$cy;
                    yield null;
                    continue;
                }
                ++$cx;
                yield null;
            }
            $occupied[$cx.':'.$cy] = true;
            $positions[$id] = [$cx, $cy];
            yield null;
        }
        $widths = [];
        $heights = [];
        foreach ($positions as $id => [$cx, $cy]) {
            $widths[$cx] = \max($widths[$cx] ?? 0.0, $items[$id]->w);
            $heights[$cy] = \max($heights[$cy] ?? 0.0, $items[$id]->h);
            yield null;
        }
        [$xs, $width] = self::centers($widths);
        [$ys, $height] = self::centers($heights);
        $nodes = [];
        foreach ($positions as $id => [$cx, $cy]) {
            $node = $items[$id];
            $nodes[$node->id] = $node->at($xs[$cx], $ys[$cy]);
            yield null;
        }

        return new GraphLayout($width, $height, $nodes, [], []);
    }

    /**
     * @param list<string> $keys
     * @param list<array{from: string, to: string, left: string, right: string}> $edges
     * @param list<array{direction: string, members: list<string>}> $hints
     * @return \Generator<int, null, mixed, array<array-key, int>>
     */
    private static function axis(array $keys, array $edges, array $hints, string $axis): \Generator
    {
        $parents = \array_combine($keys, $keys);
        $opposites = 'x' === $axis ? ['TB', 'BT'] : ['LR', 'RL'];
        foreach ($edges as $edge) {
            if (\in_array($edge['left'].$edge['right'], $opposites, strict: true)) {
                $parents[self::root($parents, $edge['to'])] = self::root($parents, $edge['from']);
            }
            yield null;
        }
        foreach ($hints as $hint) {
            $fixed = 'x' === $axis ? 'column' : 'row';
            if ($fixed === $hint['direction']) {
                $root = self::root($parents, $hint['members'][0]);
                foreach ($hint['members'] as $id) {
                    $parents[self::root($parents, $id)] = $root;
                    yield null;
                }
            }
            yield null;
        }
        $owners = [];
        foreach ($keys as $key) {
            $owners[$key] = self::root($parents, $key);
            yield null;
        }
        $constraints = [];
        foreach ($edges as $edge) {
            $delta = self::delta($edge['left'], $axis) - self::delta($edge['right'], $axis);
            if (0 !== $delta) {
                $from = $delta > 0 ? $edge['from'] : $edge['to'];
                $to = $delta > 0 ? $edge['to'] : $edge['from'];
                self::constraint($constraints, $owners[$from], $owners[$to]);
            }
            yield null;
        }
        foreach ($hints as $hint) {
            $ordered = 'x' === $axis ? 'row' : 'column';
            if ($ordered === $hint['direction']) {
                $count = \count($hint['members']);
                for ($index = 1; $index < $count; ++$index) {
                    self::constraint($constraints, $owners[$hint['members'][$index - 1]], $owners[$hint['members'][$index]]);
                    yield null;
                }
            }
            yield null;
        }
        $work = MermaidRanks::resolve(\array_values(\array_unique($owners)), $constraints);
        yield from $work;
        $ranks = $work->getReturn();
        $result = [];
        foreach ($owners as $id => $owner) {
            $result[$id] = $ranks[$owner];
            yield null;
        }

        return $result;
    }

    /** @param array<array-key, string> $parents */
    private static function root(array $parents, string $id): string
    {
        while ($parents[$id] !== $id) {
            $id = $parents[$id];
        }

        return $id;
    }

    /** @param list<array{from: string, to: string, length: int}> $constraints */
    private static function constraint(array &$constraints, string $from, string $to): void
    {
        if ($from !== $to) {
            $constraints[] = ['from' => $from, 'to' => $to, 'length' => 1];
        }
    }

    private static function delta(string $direction, string $axis): int
    {
        return match ($axis.$direction) {
            'xR', 'yB' => 1,
            'xL', 'yT' => -1,
            default => 0,
        };
    }

    /** @param array<int, float> $sizes
     * @return array{array<int, float>, float}
     */
    private static function centers(array $sizes): array
    {
        \ksort($sizes);
        $position = 6.0;
        $centers = [];
        foreach ($sizes as $rank => $size) {
            $centers[$rank] = $position + ($size / 2);
            $position += $size + 12;
        }

        return [$centers, \max(12.0, $position - 6)];
    }
}
