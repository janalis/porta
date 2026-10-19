<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** State cards use readable compartments while pseudostates retain their UML geometry. */
final class StateLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(StateDiagram $source): \Generator
    {
        $graph = $source->graph;
        $legend = $source->metadata;
        foreach ($source->descriptions as $id => $descriptions) {
            \array_push($legend, ...$descriptions);
            $label = $descriptions[0]['text'];
            $body = \array_slice($descriptions, offset: 1);
            if (\array_key_exists($id, $graph->groups)) {
                $graph->groups[$id]['label'] = \implode("\n", \array_column($descriptions, 'text'));
                yield null;
                continue;
            }
            $card = ErCard::label($label, [] === $body ? [] : [['text' => \implode("\n", \array_column($body, 'text')), 'origin' => $descriptions[0]['origin']]]);
            yield from $card;
            $graph->nodes[$id]['label'] = $card->getReturn()['label'];
            $graph->shapes[$id] = [] === $body ? 'rounded' : \str_replace('er-card:', replace: 'rd-card:', subject: $card->getReturn()['shape']);
        }
        foreach ($graph->nodes as $id => $node) {
            $kind = $source->kinds[$id];
            if ('note' === $kind) {
                $wrap = MermaidWrap::text($node['label'], width: 48);
                yield from $wrap;
                $graph->nodes[$id]['label'] = $wrap->getReturn();
            }
            $graph->shapes[$id] ??= match ($kind) {
                'choice' => 'diamond',
                'start' => 'circle',
                'end' => 'state-end',
                'fork', 'join' => 'rect',
                'note' => 'notch-rect',
                default => 'rounded',
            };
            if (\in_array($kind, ['start', 'end', 'fork', 'join', 'choice'], strict: true)) {
                $direction = $graph->direction;
                foreach ($node['groups'] as $group) {
                    if (!$graph->groups[$group]['explicit']) {
                        continue;
                    }
                    $direction = $graph->groups[$group]['direction'];
                }
                $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
                $graph->sizes[$id] = match ($kind) {
                    'start' => [3.0, 3.0],
                    'end' => [4.0, 4.0],
                    'choice' => [6.0, 6.0],
                    default => $horizontal ? [1.5, 12.0] : [12.0, 1.5],
                };
                $graph->nodes[$id]['label'] = \ucfirst($kind);
            }
            yield null;
        }
        $graph->rankGap = 14.0;
        $work = new RankedGraphLayout()->layout($graph);
        yield from $work;
        $scene = $work->getReturn();
        $nodes = [];
        foreach ($scene->graph->nodes as $id => $node) {
            $kind = $source->kinds[$node->name];
            $hidden = \in_array($kind, ['start', 'end', 'fork', 'join', 'choice'], strict: true);
            // Pinned state styles: start and fork/join marks in the special state color, notes in
            // the note colors; states, choices and end rings take the family's node roles.
            $fill = match (true) {
                'end' === $kind || 'choice' === $kind => null,
                $hidden => '@specialStateColor',
                'note' === $kind => '@noteBkgColor',
                default => null,
            };
            $nodes[$id] = new GraphNode(
                $id,
                $node->name,
                $node->label,
                $node->x,
                $node->y,
                $node->w,
                $node->h,
                $node->color,
                $node->fillColor ?? $fill,
                $node->dashed,
                strokeWidth: $node->strokeWidth,
                drawLabel: $hidden ? '' : $node->drawLabel,
                textColor: $node->textColor ?? ('note' === $kind ? '@noteTextColor' : null),
                font: $node->font,
                fontScale: $node->fontScale,
                dashes: $node->dashes,
            );
            yield null;
        }

        $elements = $scene->elements;
        $shapes = $scene->shapes;
        $groupIds = \array_flip(\array_keys($graph->groups));
        $frames = [];
        foreach ($scene->graph->clusters as $cluster) {
            if (\str_starts_with($cluster->name, '@region:')) {
                continue;
            }
            $id = $groupIds[$cluster->name] - \count($graph->groups);
            $header = $graph->styles->measureCluster($cluster->name, $cluster->label)[1];
            $nodes[$id] = new GraphNode(
                $id,
                $cluster->name,
                $cluster->label,
                ($cluster->x0 + $cluster->x1) / 2,
                $cluster->y0 + ($header / 2),
                $cluster->x1 - $cluster->x0,
                $header,
                null,
                null,
                dashed: false,
                drawLabel: '',
            );
            $elements[$id] = $graph->groups[$cluster->name]['origin'];
            $shapes[$id] = 'text';
            $frames[$id] = $cluster->name;
            yield null;
        }

        $routes = MermaidFrameRoutes::layout(new GraphLayout($scene->graph->width, $scene->graph->height, $nodes, $scene->graph->edges, $scene->graph->clusters), $frames);
        yield from $routes;
        $regions = StateRegions::layout($routes->getReturn());
        yield from $regions;

        $groupNames = \array_keys($graph->groups);
        foreach ($scene->legend as $index => $entry) {
            if ($index < \count($groupNames) && \str_starts_with((string) $groupNames[$index], '@region:')) {
                continue;
            }
            $legend[] = $entry;
            yield null;
        }

        $notes = StateNotes::layout(new MermaidScene(
            $regions->getReturn(),
            $scene->origins,
            $legend,
            $elements,
            $shapes,
        ), $source->notes);
        yield from $notes;

        return $notes->getReturn();
    }
}
