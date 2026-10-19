<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Stable directed ranks break each stalled cycle once, without recursive graph traversal. */
final class MermaidRanks
{
    /**
     * @param list<array-key> $keys
     * @param list<array{from: string, to: string, length?: int, index?: int}> $edges
     * @return \Generator<int, null, mixed, array<array-key, int>>
     */
    public static function resolve(array $keys, array $edges): \Generator
    {
        $incoming = \array_fill_keys($keys, value: 0);
        $outgoing = [];
        foreach ($edges as $edge) {
            ++$incoming[$edge['to']];
            $outgoing[$edge['from']][] = $edge;
            yield null;
        }
        $queue = \array_keys(\array_filter($incoming, static fn (int $count): bool => 0 === $count));
        $fallback = 0;
        $cursor = 0;
        $visited = [];
        $ranks = \array_fill_keys($keys, value: 0);
        while (\count($visited) < \count($keys)) {
            if (!\array_key_exists($cursor, $queue)) {
                while (\array_key_exists($keys[$fallback], $visited)) {
                    ++$fallback;
                }
                $queue[] = $keys[$fallback];
            }
            $id = (string) $queue[$cursor++];
            $visited[$id] = true;
            foreach ($outgoing[$id] ?? [] as $edge) {
                $target = $edge['to'];
                if (\array_key_exists($target, $visited)) {
                    continue;
                }
                $ranks[$target] = \max($ranks[$target], $ranks[$id] + ($edge['length'] ?? 1));
                if (0 === --$incoming[$target]) {
                    $queue[] = $target;
                }
            }
            yield null;
        }

        return $ranks;
    }
}
