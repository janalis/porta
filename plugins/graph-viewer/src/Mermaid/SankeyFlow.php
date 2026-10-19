<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Acyclic ranks and normalized weights avoid overflowing even when finite authored totals would overflow. */
final readonly class SankeyFlow
{
    /** @param array<int, list<int>> $layers
     * @param list<float> $weights
     * @param list<float> $values
     * @param list<list<int>> $incoming
     * @param list<list<int>> $outgoing
     */
    public function __construct(
        public array $layers,
        public array $weights,
        public array $values,
        public array $incoming,
        public array $outgoing,
    ) {}

    /**
     * @param string $alignment Pinned `sankey.nodeAlignment`: d3-sankey's left, right, center or justify.
     * @return \Generator<int, null, mixed, self>
     */
    public static function build(SankeyDiagram $source, string $alignment = 'justify'): \Generator
    {
        $count = \count($source->nodes);
        $incoming = \array_fill(0, $count, value: []);
        $outgoing = $incoming;
        $indegree = \array_fill(0, $count, value: 0);
        $totalsIn = \array_fill(0, $count, value: 0.0);
        $totalsOut = $totalsIn;
        $maximum = \max(\array_column($source->links, 'value'));
        $maximum = $maximum > 0 ? $maximum : 1.0;
        $values = [];
        foreach ($source->links as $index => $link) {
            $value = $link['value'] / $maximum;
            $values[] = $value;
            $incoming[$link['target']][] = $index;
            $outgoing[$link['source']][] = $index;
            ++$indegree[$link['target']];
            $totalsIn[$link['target']] += $value;
            $totalsOut[$link['source']] += $value;
            yield null;
        }
        $ranks = self::ranks($source, $indegree, $outgoing);
        yield from $ranks;
        [$levels, $heights] = $ranks->getReturn();
        $last = \max($levels);
        $layers = [];
        $weights = [];
        foreach ($levels as $id => $level) {
            $layer = match ($alignment) {
                'left' => $level,
                'right' => $last - $heights[$id],
                'center' => self::center($source, $id, $levels, $incoming[$id], $outgoing[$id]),
                default => [] === $outgoing[$id] ? $last : $level,
            };
            $layers[$layer][] = $id;
            $weights[] = \max($totalsIn[$id], $totalsOut[$id]);
            yield null;
        }
        \ksort($layers);

        return new self($layers, $weights, $values, $incoming, $outgoing);
    }

    /** @param list<int> $indegree
     * @param list<list<int>> $outgoing
     * @return \Generator<int, null, mixed, array{list<int>, list<int>}> each node's depth from a
     *         source and height above a sink
     */
    private static function ranks(SankeyDiagram $source, array $indegree, array $outgoing): \Generator
    {
        $queue = \array_keys(\array_filter($indegree, static fn (int $degree): bool => 0 === $degree));
        $levels = \array_fill(0, count: \count($indegree), value: 0);
        for ($cursor = 0; $cursor < \count($queue); ++$cursor) {
            $id = $queue[$cursor];
            foreach ($outgoing[$id] as $index) {
                $target = $source->links[$index]['target'];
                $levels[$target] = \max($levels[$target], $levels[$id] + 1);
                --$indegree[$target];
                if (0 === $indegree[$target]) {
                    $queue[] = $target;
                }
                yield null;
            }
            yield null;
        }
        if (\count($queue) !== \count($indegree)) {
            throw new MermaidSyntaxError('Circular Sankey flows cannot be laid out', offset: 0, sourceLine: $source->origin, column: 0);
        }
        $heights = \array_fill(0, count: \count($indegree), value: 0);
        foreach (\array_reverse($queue) as $id) {
            foreach ($outgoing[$id] as $index) {
                $heights[$id] = \max($heights[$id], $heights[$source->links[$index]['target']] + 1);
            }
        }

        return [$levels, $heights];
    }

    /**
     * d3-sankey `sankeyCenter`: a node with inputs keeps its depth; a source sits just before its
     * nearest target.
     *
     * @param list<int> $levels
     * @param list<int> $incoming
     * @param list<int> $outgoing
     */
    private static function center(SankeyDiagram $source, int $id, array $levels, array $incoming, array $outgoing): int
    {
        $targets = \array_map(static fn (int $index): int => $levels[$source->links[$index]['target']], $outgoing);

        return match (true) {
            [] !== $incoming => $levels[$id],
            [] !== $targets => \min($targets) - 1,
            default => 0,
        };
    }
}
