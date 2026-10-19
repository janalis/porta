<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Self-relation routes and captions occupy layout space without enlarging their visible cards. */
final class MermaidLoopSpace
{
    /** @return \Generator<int, null, mixed, array<array-key, array{float, float}>> */
    public static function measure(MermaidGraph $source): \Generator
    {
        $padding = [];
        foreach ($source->edges as $index => $edge) {
            if ($edge['from'] === $edge['to']) {
                [$width, $height] = $source->edgeStyles->measure($index, $edge['label']);
                $previous = $padding[$edge['from']] ?? [0.0, 0.0];
                $padding[$edge['from']] = [\max($previous[0], ($width / 2) + 10), \max($previous[1], $height + 10)];
            }
            yield null;
        }

        return $padding;
    }
}
