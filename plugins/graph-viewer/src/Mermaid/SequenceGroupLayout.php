<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;

/** Group members occupy consecutive columns; group frames span the full chronology. */
final class SequenceGroupLayout
{
    /** @return \Generator<int, null, mixed, array<array-key, array{label: string, origin: int}>> */
    public static function ordered(SequenceDiagram $source): \Generator
    {
        $ordered = [];
        $seen = [];
        foreach ($source->participants as $id => $participant) {
            $group = $source->memberships[$id] ?? null;
            if (null === $group) {
                $ordered[$id] = $participant;
                yield null;
                continue;
            }
            if (!\array_key_exists($group, $seen)) {
                $seen[$group] = true;
                foreach ($source->groups[$group]['actors'] as $actor) {
                    $ordered[$actor] = $source->participants[$actor];
                    yield null;
                }
            }
            yield null;
        }

        return $ordered;
    }

    /**
     * @param array<array-key, float> $positions
     * @return \Generator<int, null, mixed, list<GraphCluster>>
     */
    public static function frames(SequenceDiagram $source, array $positions, float $spacing, float $top, float $bottom): \Generator
    {
        $frames = [];
        foreach ($source->groups as $index => $group) {
            $left = null;
            $right = null;
            foreach ($group['actors'] as $actor) {
                $left = null === $left ? $positions[$actor] : \min($left, $positions[$actor]);
                $right = null === $right ? $positions[$actor] : \max($right, $positions[$actor]);
                yield null;
            }
            if (null !== $left && null !== $right) {
                $frames[] = new GraphCluster('box'.$index, $group['label'], $left - ($spacing / 2) + 1, $top - 2, $right + ($spacing / 2) - 1, $bottom, null, null);
            }
            yield null;
        }

        return $frames;
    }
}
