<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;

/**
 * Participant headers and lifelines share creation/destruction message coordinates: as pinned,
 * a created participant's header and a destroyed one's footer center on the message that
 * creates or destroys it, and the lifeline runs solid between them.
 */
final class SequenceParticipants
{
    /**
     * @param array<array-key, float> $positions
     * @param array<int, array{top: float, bottom: float, arrow: float, received: float}> $rows
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, list<GraphEdge>}>
     */
    public function layout(SequenceDiagram $source, array $positions, array $rows, float $headerTop, float $headerHeight, float $bottom, int $next): \Generator
    {
        $nodes = [];
        $edges = [];
        foreach ($source->participants as $actor => $participant) {
            $created = $source->lifetimes[$actor]['create'] ?? null;
            $destroyed = $source->lifetimes[$actor]['destroy'] ?? null;
            $top = null === $created ? $headerTop : $rows[$created]['received'] - ($headerHeight / 2);
            $end = null === $destroyed ? $bottom : self::endpoint($source, $rows, $destroyed, $actor) - ($headerHeight / 2);
            // A participant created and destroyed by one message keeps both headers, stacked.
            $end = \max($end, null === $destroyed ? $end : $top + $headerHeight + 2);
            if (null !== $created) {
                $id = $next + \count($nodes);
                $nodes[$id] = self::header($id, (string) $actor, $participant['label'], $positions[$actor], $top, $headerHeight);
            }
            if ($end > ($top + $headerHeight)) {
                $edges[] = new GraphEdge(
                    0,
                    0,
                    [[$positions[$actor], $top + $headerHeight], [$positions[$actor], $end]],
                    null,
                    $positions[$actor],
                    $top + $headerHeight,
                    $positions[$actor],
                    $end,
                    '@actorLineColor',
                    null,
                    null,
                    false,
                );
            }
            if (null !== $destroyed) {
                $id = $next + \count($nodes);
                $nodes[$id] = self::header($id, (string) $actor, $participant['label'], $positions[$actor], $end, $headerHeight);
            }
            yield null;
        }

        return [$nodes, $edges];
    }

    public static function header(int $id, string $actor, string $label, float $x, float $top, float $height): GraphNode
    {
        [$w, $h] = MermaidText::box($label);

        return new GraphNode($id, $actor, $label, $x, $top + ($height / 2), $w, $height, null, null, dashed: false);
    }

    /**
     * @param array<int, array{top: float, bottom: float, arrow: float, received: float}> $rows
     */
    public static function endpoint(SequenceDiagram $source, array $rows, int $index, int|string $actor): float
    {
        return $rows[$index][$source->events[$index]['to'] === (string) $actor ? 'received' : 'arrow'];
    }
}
