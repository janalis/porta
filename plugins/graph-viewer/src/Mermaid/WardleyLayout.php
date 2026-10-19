<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;

/** Projected value chains keep visibility vertical and evolution horizontal. */
final class WardleyLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(WardleyDiagram $source): \Generator
    {
        $canvas = new WardleyCanvas(\max(60.0, $source->width), \max(40.0, $source->height));
        $canvas->legend = $source->metadata;
        yield from $this->axes($canvas, $source);
        $placement = $this->points($canvas, $source);
        yield from $placement;
        $ids = $placement->getReturn();
        yield from $this->pipelines($canvas, $source, $ids);
        yield from $this->links($canvas, $source, $ids);
        yield from $this->annotations($canvas, $source);
        $work = $canvas->scene($source->origin);
        yield from $work;
        $scene = $work->getReturn();
        foreach ($source->metadata as $metadata) {
            if (!\str_starts_with($metadata['text'], 'title ')) {
                continue;
            }
            $title = MermaidTitle::apply($scene, new MermaidStatement(\substr($metadata['text'], offset: 6), offset: 0, line: $metadata['origin'], column: 0));
            yield from $title;
            $scene = $title->getReturn();
        }

        return $scene;
    }

    /** @return \Generator<int, null, mixed, void> */
    private function axes(WardleyCanvas $canvas, WardleyDiagram $source): \Generator
    {
        [$left, $bottom] = $canvas->position(visibility: 0.0, evolution: 0.0);
        [$right, $top] = $canvas->position(visibility: 1.0, evolution: 1.0);
        $canvas->clusters[] = new GraphCluster('@wardley-map', '', $left, $top, $right, $bottom, '@!wardley.axisColor|=#000000', null, fillColor: '@!wardley.backgroundColor|background|=#ffffff');
        yield from $canvas->text('@visibility', 'Visibility ↑', x: 10.0, y: $top + ($canvas->height / 2), origin: $source->origin);
        yield from $canvas->text('@evolution', 'Evolution →', ($left + $right) / 2, $bottom + 9, $source->origin);
        $stages = $source->stages;
        if ([] === $stages) {
            foreach (['Genesis', 'Custom built', 'Product', 'Commodity'] as $label) {
                $stages[] = ['label' => $label, 'boundary' => null];
            }
        }
        $allBoundaries = !\in_array(null, \array_column($stages, 'boundary'), strict: true);
        $previous = 0.0;
        $count = \count($stages);
        foreach ($stages as $index => $stage) {
            $end = $allBoundaries ? $stage['boundary'] ?? 1.0 : ($index + 1) / $count;
            $x = $left + ((($previous + $end) / 2) * $canvas->width);
            yield from $canvas->text('@stage:'.$index, $stage['label'], $x, $bottom + 3, $source->origin);
            if (($index + 1) < $count) {
                $boundary = $left + ($end * $canvas->width);
                // Pinned stage dividers are literal black at 80% opacity.
                $canvas->line(0, 0, [[$boundary, $top], [$boundary, $bottom]], color: '#000000cc', dashed: true);
            }
            $previous = $end;
            yield null;
        }
    }

    /** @return \Generator<int, null, mixed, array<array-key, int>> */
    private function points(WardleyCanvas $canvas, WardleyDiagram $source): \Generator
    {
        $ids = [];
        foreach ($source->nodes as $name => $point) {
            [$x, $y] = $canvas->position($point['visibility'], $point['evolution']);
            $id = \count($canvas->nodes);
            $size = '' === $point['strategy'] ? 2.0 : 4.0;
            $shape = 'wardley:'.$point['strategy'].':'.($point['inertia'] ? 'inertia' : '');
            // Pinned sourcing overlays are literal grays; `build` alone keeps a black outline.
            $fill = match ($point['strategy']) {
                'outsource' => '#666666',
                'buy' => '#cccccc',
                'build' => '#eeeeee',
                default => '@!wardley.componentFill|=#ffffff',
            };
            if ('anchor' === $point['kind']) {
                $text = $canvas->text((string) $name, $point['label'], $x, $y, $point['origin'], GraphFont::Bold);
                yield from $text;
                $id = $text->getReturn();
                $canvas->elements[$id] = $point['origin'];
            }
            if ('anchor' !== $point['kind']) {
                $canvas->point(
                    new GraphNode(
                        $id,
                        (string) $name,
                        MermaidText::decode($point['label']),
                        $x,
                        $y,
                        $size,
                        $size,
                        'build' === $point['strategy'] ? '#000000' : '@!wardley.componentStroke|=#000000',
                        $fill,
                        dashed: false,
                        drawLabel: '',
                    ),
                    $shape,
                    $point['origin'],
                );
                $label = $canvas->label('@label:'.$name, $point['label'], $x + $point['dx'], $y + $point['dy'], $point['origin']);
                yield from $label;
                $canvas->labels[$name] = $label->getReturn();
            }
            $ids[$name] = $id;
            $suffix = '' === $point['strategy'] ? '' : ' ('.$point['strategy'].')';
            $canvas->legend[] = [
                'text' => $point['kind'].': '.$point['label'].$suffix.($point['inertia'] ? ' (inertia)' : '').' ['.$point['visibility'].', '.$point['evolution'].']',
                'origin' => $point['origin'],
            ];
            yield null;
        }

        return $ids;
    }

    /** @param array<array-key, int> $ids
     * @return \Generator<int, null, mixed, void>
     */
    private function pipelines(WardleyCanvas $canvas, WardleyDiagram $source, array $ids): \Generator
    {
        foreach ($source->pipelines as $name => $members) {
            $points = \array_map(static fn (string $member): GraphNode => $canvas->nodes[$ids[$member]], $members);
            $left = \min(\array_map(static fn (GraphNode $node): float => $node->x, $points)) - 2;
            $right = \max(\array_map(static fn (GraphNode $node): float => $node->x, $points)) + 2;
            $y = $points[0]->y;
            $canvas->clusters[] = new GraphCluster('@pipeline:'.$name, '', $left, $y - 2, $right, $y + 2, '@!wardley.componentStroke|=#000000', null, fillColor: 'none');
            $id = $ids[$name];
            $parent = $canvas->nodes[$id];
            if (!\array_key_exists($name, $canvas->labels)) {
                $caption = $canvas->text('@label:'.$name, $parent->label, $parent->x, $parent->y, $canvas->elements[$id], $parent->font);
                yield from $caption;
                $canvas->labels[$name] = $caption->getReturn();
            }
            $canvas->nodes[$id] = new GraphNode(
                $id,
                $parent->name,
                $parent->label,
                ($left + $right) / 2,
                $y - 3,
                w: 2.0,
                h: 2.0,
                color: '@!wardley.componentStroke|=#000000',
                fillColor: '@!wardley.componentFill|=#ffffff',
                dashed: false,
                drawLabel: '',
            );
            $labelId = $canvas->labels[$name];
            $label = $canvas->nodes[$labelId];
            $canvas->nodes[$labelId] = $label->at($label->x + $canvas->nodes[$id]->x - $parent->x, $label->y - 3);
            $canvas->shapes[$id] = 'rect';
            yield null;
        }
    }

    /** @param array<array-key, int> $ids
     * @return \Generator<int, null, mixed, void>
     */
    private function links(WardleyCanvas $canvas, WardleyDiagram $source, array $ids): \Generator
    {
        $aliases = [];
        foreach ($source->nodes as $name => $point) {
            $aliases[$point['label']] ??= (string) $name;
        }
        foreach ($source->links as $link) {
            $from = \array_key_exists($link['from'], $ids) ? $link['from'] : $aliases[$link['from']] ?? $link['from'];
            $to = \array_key_exists($link['to'], $ids) ? $link['to'] : $aliases[$link['to']] ?? $link['to'];
            $canvas->legend[] = ['text' => $link['from'].' → '.$link['to'].('' === $link['label'] ? '' : ' : '.$link['label']), 'origin' => $link['origin']];
            if (!\array_key_exists($from, $ids) || !\array_key_exists($to, $ids) || \in_array($from, $source->pipelines[$to] ?? [], strict: true)) {
                yield null;
                continue;
            }
            $tail = $canvas->nodes[$ids[$from]];
            $head = $canvas->nodes[$ids[$to]];
            $start = MermaidOutline::boundary($tail, $canvas->shapes[$tail->id], $head->x, $head->y);
            $end = MermaidOutline::boundary($head, $canvas->shapes[$head->id], $tail->x, $tail->y);
            if ('backward' === $link['flow']) {
                [$start, $end] = [$end, $start];
            }
            $wrap = MermaidWrap::text(MermaidText::decode($link['label']), width: 32);
            yield from $wrap;
            $label = '' === $link['label'] ? null : $wrap->getReturn();
            $canvas->line($tail->id, $head->id, [$start, $end], dashed: $link['dashed'], decoration: false, label: $label, tip: 'none' === $link['flow'] ? null : $end);
            if ('both' === $link['flow']) {
                $canvas->line($head->id, $tail->id, [$end, $start], dashed: $link['dashed'], decoration: true, tip: $start);
            }
            yield null;
        }
        foreach ($source->trends as $trend) {
            if (!\array_key_exists($trend['name'], $ids)) {
                continue;
            }
            $node = $canvas->nodes[$ids[$trend['name']]];
            $end = $canvas->position($source->nodes[$trend['name']]['visibility'], $trend['target']);
            $canvas->line($node->id, $node->id, [[$node->x, $node->y], $end], color: '@!wardley.evolutionStroke|=#dc3545', dashed: true, tip: $end);
            $canvas->legend[] = ['text' => 'evolve '.$trend['name'].' → '.$trend['target'], 'origin' => $trend['origin']];
            yield null;
        }
    }

    /** @return \Generator<int, null, mixed, void> */
    private function annotations(WardleyCanvas $canvas, WardleyDiagram $source): \Generator
    {
        $numbered = [];
        $box = null;
        foreach ($source->annotations as $index => $annotation) {
            [$x, $y] = $canvas->position($annotation['visibility'], $annotation['evolution']);
            if ('annotations' === $annotation['kind']) {
                $box ??= [$x, $y];
                continue;
            }
            $label = $annotation['label'];
            if ('annotation' === $annotation['kind']) {
                $numbered[] = ['text' => $label, 'origin' => $annotation['origin'], 'number' => (int) \explode('.', $label, limit: 2)[0]];
                $label = \explode('.', $label, limit: 2)[0];
            }
            $text = $canvas->text('@'.$annotation['kind'].':'.$index, $label, $x, $y, $annotation['origin']);
            yield from $text;
            $id = $text->getReturn();
            $canvas->elements[$id] = $annotation['origin'];
            $canvas->shapes[$id] = match ($annotation['kind']) {
                'annotation' => 'circle',
                'accelerator' => 'wardley:accelerator:',
                'deaccelerator' => 'wardley:deaccelerator:',
                default => 'rect',
            };
            $canvas->legend[] = ['text' => $annotation['kind'].': '.$annotation['label'], 'origin' => $annotation['origin']];
            yield null;
        }
        if (null !== $box && [] !== $numbered) {
            \usort($numbered, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
            $text = \implode("\n", \array_column($numbered, 'text'));
            yield from $canvas->text('@annotations-box', $text, $box[0], $box[1], $numbered[0]['origin']);
        }
    }
}
