<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Sampled sectors share exactly the same outline for drawing and source hit testing. */
final class PieSymbols
{
    /** @return list<array{float, float}> */
    public static function points(GraphNode $node, string $shape): array
    {
        /** @var array{string, numeric-string, numeric-string, numeric-string, numeric-string, numeric-string, numeric-string} $parts Generated finite sector geometry. */
        $parts = \explode(':', $shape);
        $start = (float) $parts[1];
        $end = (float) $parts[2];
        $hole = (float) $parts[3];
        $cx = $node->x + (float) $parts[4];
        $cy = $node->y + (float) $parts[5];
        $radius = (float) $parts[6];
        $steps = \max(2, (int) \ceil(($end - $start) * 24));
        $points = [];
        for ($index = 0; $index <= $steps; ++$index) {
            $angle = $start + ((($end - $start) * $index) / $steps) - (\M_PI / 2);
            $points[] = [$cx + ($radius * \cos($angle)), $cy + ($radius * \sin($angle))];
        }
        if (0.0 === $hole) {
            if (($end - $start) < ((2 * \M_PI) - 0.000_001)) {
                $points[] = [$cx, $cy];
            }

            return $points;
        }
        for ($index = $steps; $index >= 0; --$index) {
            $angle = $start + ((($end - $start) * $index) / $steps) - (\M_PI / 2);
            $points[] = [$cx + ($radius * $hole * \cos($angle)), $cy + ($radius * $hole * \sin($angle))];
        }

        return $points;
    }
}
