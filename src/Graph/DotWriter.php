<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * Emits the architecture graph as Graphviz DOT: one subgraph cluster per
 * composer root, box nodes labeled with their short segment, deduplicated
 * directed edges. Purely deterministic over its (sorted) inputs — the
 * scanner's stable ordering makes regenerated output byte-identical for an
 * unchanged project, which is what keeps the viewer from re-laying-out.
 */
final class DotWriter
{
    /**
     * @param array<string, array<string, string>> $clusters cluster label => (node id => node label)
     * @param list<array{string, string}>          $edges    [from id, to id]
     */
    public static function write(array $clusters, array $edges): string
    {
        $lines = ['digraph architecture {', '    rankdir=LR;', '    node [shape=box];'];
        $index = 0;
        foreach ($clusters as $label => $nodes) {
            $lines[] = \sprintf('    subgraph cluster_%d {', $index);
            $lines[] = \sprintf('        label=%s;', self::quote($label));
            foreach ($nodes as $id => $nodeLabel) {
                $lines[] = \sprintf('        %s [label=%s];', self::quote($id), self::quote($nodeLabel));
            }
            $lines[] = '    }';
            ++$index;
        }
        foreach ($edges as [$from, $to]) {
            $lines[] = \sprintf('    %s -> %s;', self::quote($from), self::quote($to));
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
