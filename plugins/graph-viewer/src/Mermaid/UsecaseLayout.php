<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Role glyphs, package tabs and UML endpoints decorate one shared compound ranked scene. */
final readonly class UsecaseLayout
{
    public function __construct(
        private UsecaseConfiguration $configuration = new UsecaseConfiguration(),
        private ?MermaidOptions $options = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(UsecaseDiagram $source): \Generator
    {
        $build = new UsecasePreparation($source, $this->configuration)->build();
        yield from $build;
        $prepared = $build->getReturn();
        $work = new RankedGraphLayout()->layout($prepared->graph);
        yield from $work;
        $scene = $work->getReturn();
        $nodes = [];
        $slots = $this->slots($source);
        foreach ($scene->graph->nodes as $id => $node) {
            $element = $source->elements[$node->name] ?? null;
            // Pinned use-case role colors; these rules outrank the neo node border on purpose.
            $defaults = match ($element?->kind) {
                'actor' => ['fill' => '@usecaseActorBkg|actorBkg|mainBkg', 'stroke' => '@usecaseActorBorder|actorBorder|primaryColor', 'color' => '@actorTextColor|primaryTextColor'],
                'json' => ['fill' => '@mainBkg', 'stroke' => '@nodeBorder|primaryColor', 'color' => '@primaryTextColor'],
                null => ['fill' => '@noteBkgColor', 'stroke' => '@noteBorderColor', 'color' => '@noteTextColor'],
                default => ['fill' => '@usecaseBkg|mainBkg', 'stroke' => '@usecaseBorder|nodeBorder|primaryColor', 'color' => '@primaryTextColor'],
            };
            // Pinned `colorScheme: rotate` gives actors and use cases a color theme's slots.
            $slot = $slots[$node->name] ?? null;
            $defaults = null === $slot
                ? $defaults
                : ['fill' => '@slot:fill:'.$slot.'|'.\substr($defaults['fill'], offset: 1), 'stroke' => '@slot:stroke:'.$slot.'|'.\substr($defaults['stroke'], offset: 1)] + $defaults;
            $node = $prepared->graph->styles->decorate($node, $defaults);
            $nodes[$id] = new GraphNode(
                $node->id,
                $node->name,
                $node->label,
                $node->x,
                $node->y,
                $node->w,
                $node->h,
                $node->color,
                $node->fillColor,
                $node->dashed,
                background: $node->background,
                strokeWidth: $node->strokeWidth,
                drawLabel: $prepared->drawLabels[$node->name] ?? $node->drawLabel,
                textColor: $node->textColor,
                font: $node->font,
                fontScale: $node->fontScale,
                dashes: $node->dashes,
            );
            yield null;
        }
        $clusters = [];
        $boundaries = \array_flip(\array_map(\strval(...), \array_keys(\array_filter($source->elements, static fn (UsecaseElement $element): bool => 'boundary' === $element->kind))));
        $elements = $scene->elements;
        $shapes = $scene->shapes;
        $next = -1;
        foreach ($scene->graph->clusters as $index => $cluster) {
            $element = $source->elements[$cluster->name];
            $package = 'package' === ($element->metadata['type'] ?? 'rect');
            [$width, $height] = $prepared->graph->styles->measureCluster($element->id, $element->label);
            // Boundaries take a color theme's slot in declaration order, else the boundary roles.
            $slot = $boundaries[$cluster->name] ?? $index;
            $fill = $cluster->fillColor ?? '@slot:fill:'.$slot.'|usecaseBoundaryBkg|clusterBkg';
            $stroke = $cluster->color ?? '@slot:stroke:'.$slot.'|usecaseBoundaryBorder|clusterBorder';
            $clusters[] = new GraphCluster(
                $cluster->name,
                $package ? '' : $cluster->label,
                $cluster->x0,
                $cluster->y0 + ($package ? $height : 0),
                $cluster->x1,
                $cluster->y1,
                $stroke,
                $cluster->labelPos,
                $fill,
                $cluster->textColor,
                $cluster->strokeWidth,
                $cluster->dashed,
                $cluster->font,
                $cluster->fontScale,
                dashes: $cluster->dashes,
            );
            $x = $package ? $cluster->x0 + ($width / 2) : ($cluster->x0 + $cluster->x1) / 2;
            $y = $cluster->y0 + ($height / 2);
            $nodes[$next] = new GraphNode(
                $next,
                $element->id,
                $element->label,
                $x,
                $y,
                $width,
                $height,
                $stroke,
                $package ? $fill : null,
                $cluster->dashed,
                strokeWidth: $cluster->strokeWidth,
                drawLabel: $package ? null : '',
                textColor: $cluster->textColor,
                font: $cluster->font,
                fontScale: $cluster->fontScale,
                dashes: $cluster->dashes,
            );
            $elements[$next] = $element->statement->line;
            $shapes[$next] = $package ? 'uc-package' : 'text';
            --$next;
            yield null;
        }
        $base = new GraphLayout($scene->graph->width, $scene->graph->height, $nodes, $scene->graph->edges, $clusters);
        $markers = $this->markers($source, $base, $prepared->graph);
        yield from $markers;

        return MermaidMarkdownLabels::apply(
            new MermaidScene($markers->getReturn(), $scene->origins, [...$source->legend, ...$prepared->legend, ...$scene->legend], $elements, $shapes),
            $prepared->graph,
        );
    }

    /** @return \Generator<int, null, mixed, GraphLayout> */
    private function markers(UsecaseDiagram $source, GraphLayout $layout, MermaidGraph $graph): \Generator
    {
        $edges = [];
        foreach ($layout->edges as $index => $edge) {
            $kind = \array_key_exists($index, $source->relations) ? $source->relations[$index]->kind : 'note';
            $color = $edge->color ?? match ($kind) {
                'include' => '@usecaseIncludeLine|lineColor',
                'extend' => '@usecaseExtendLine|lineColor',
                default => null,
            };
            $edge = new GraphEdge(
                $edge->tail,
                $edge->head,
                $edge->points,
                $edge->arrowTip,
                $edge->minX,
                $edge->minY,
                $edge->maxX,
                $edge->maxY,
                $color,
                $edge->label,
                $edge->labelPos,
                $edge->dashed,
                ribbon: $edge->ribbon,
                strokeWidth: $edge->strokeWidth,
                textColor: $edge->textColor,
                decoration: $edge->decoration,
                font: $edge->font,
                fontScale: $edge->fontScale,
                dashes: $edge->dashes,
            );
            if ('generalization' === $kind) {
                $edges[] = ClassMarkers::shaft($edge, left: '', right: '|>');
                \array_push($edges, ...ClassMarkers::forEdge($edge, left: '', right: '|>'));
                yield null;
                continue;
            }
            $marked = MermaidLinkMarkers::decorate(new GraphLayout($layout->width, $layout->height, $layout->nodes, [$edge], $layout->clusters), [$graph->edges[$index]['arrow']]);
            yield from $marked;
            \array_push($edges, ...$marked->getReturn()->edges);
            yield null;
        }

        return new GraphLayout($layout->width, $layout->height, $layout->nodes, $edges, $layout->clusters);
    }

    /**
     * Pinned rotate slots: every actor in declaration order, then every use case.
     *
     * @return array<string, int> slot by element id
     */
    private function slots(UsecaseDiagram $source): array
    {
        if ('rotate' !== ($this->options ?? MermaidOptions::defaults())->text('usecase', 'colorScheme')) {
            return [];
        }
        $ordered = [
            ...\array_filter($source->elements, static fn (UsecaseElement $element): bool => 'actor' === $element->kind),
            ...\array_filter($source->elements, static fn (UsecaseElement $element): bool => 'usecase' === $element->kind),
        ];

        return \array_flip(\array_map(static fn (UsecaseElement $element): string => $element->id, \array_values($ordered)));
    }
}
