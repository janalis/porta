<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Native ranked cards retain every authored field and relationship in their legend. Pictures
 * follow pinned `requirementBox`: the type and the bold name centered over a divider, then the
 * fields left-aligned in a fixed order, and the SysML markers on the relationships.
 */
final class RequirementLayout
{
    /** Pinned `RequirementType` display names. */
    private const array TYPES = [
        'requirement' => 'Requirement',
        'functionalrequirement' => 'Functional Requirement',
        'interfacerequirement' => 'Interface Requirement',
        'performancerequirement' => 'Performance Requirement',
        'physicalrequirement' => 'Physical Requirement',
        'designconstraint' => 'Design Constraint',
        'element' => 'Element',
    ];

    /** Pinned body rows in drawing order; risk and verification read capitalized. */
    private const array FIELDS = ['id' => 'ID', 'text' => 'Text', 'risk' => 'Risk', 'verifymethod' => 'Verification', 'type' => 'Type', 'docref' => 'Doc Ref'];

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(RequirementDiagram $source): \Generator
    {
        $graph = new MermaidGraph('requirement', styles: $source->styles);
        $graph->colorSlots = MermaidColorSlots::Nodes;
        $graph->direction = $source->direction;
        $graph->rankGap = 20.0;
        $runs = [];
        foreach ($source->nodes as $id => $node) {
            $card = self::card((string) $id, $node['type'], $node['fields']);
            yield from $card;
            [$label, $runs[(string) $id], $header] = $card->getReturn();
            $graph->node((string) $id, $label, new MermaidStatement('', $node['offset'], $node['origin'], column: 0), groups: []);
            $graph->shapes[(string) $id] = \count($runs[(string) $id]) > $header ? 'rq-card:'.$header : 'rect';
        }
        foreach ($source->relations as $relation) {
            $graph->edge(
                $relation['from'],
                $relation['to'],
                '<<'.$relation['kind'].'>>',
                'contains' === $relation['kind'] ? '--' : '-.->',
                new MermaidStatement('', offset: 0, line: $relation['origin'], column: 0),
            );
            yield null;
        }
        $layout = new RankedGraphLayout()->layout($graph);
        yield from $layout;
        $scene = $layout->getReturn();
        $nodes = [];
        $styled = [];
        foreach ($scene->graph->nodes as $node) {
            $nodes[] = $source->styles->decorate($node);
            $styled['node:'.$node->id] = $runs[$node->name];
            yield null;
        }
        $legend = [];
        foreach ($source->metadata as $metadata) {
            $legend[] = ['text' => $metadata['label'], 'origin' => $metadata['origin']];
        }

        return new MermaidScene(
            new GraphLayout($scene->graph->width, $scene->graph->height, $nodes, self::markers($scene->graph->edges, $source->relations), $scene->graph->clusters),
            $scene->origins,
            [...$legend, ...$scene->legend],
            $scene->elements,
            $scene->shapes,
            runs: $styled,
        );
    }

    /**
     * A card's text, its runs and the number of header lines: the type and the bold name, then
     * each authored field left-aligned by padding to the widest.
     *
     * @param array<string, string> $fields
     * @return \Generator<int, null, mixed, array{string, list<list<array{string, GraphFont}>>, int}>
     */
    private static function card(string $id, string $type, array $fields): \Generator
    {
        $header = MermaidWrap::text('<<'.(self::TYPES[$type] ?? $type).'>>', width: 48);
        yield from $header;
        $name = MermaidWrap::text($id, width: 48);
        yield from $name;
        $lines = [
            ...\array_map(static fn (string $line): array => [[$line, GraphFont::Regular]], \explode("\n", $header->getReturn())),
            ...\array_map(static fn (string $line): array => [[$line, GraphFont::Bold]], \explode("\n", $name->getReturn())),
        ];
        $count = \count($lines);
        $body = [];
        foreach (self::FIELDS as $key => $title) {
            $value = $fields[$key] ?? '';
            if ('' === $value) {
                continue;
            }
            $value = \in_array($key, ['risk', 'verifymethod'], strict: true) ? \ucfirst(\strtolower($value)) : $value;
            $wrap = MermaidWrap::text($title.': '.$value, width: 48);
            yield from $wrap;
            \array_push($body, ...\explode("\n", $wrap->getReturn()));
        }
        $width = \max([0, ...\array_map(AnsiUtils::visibleWidth(...), $body)]);
        foreach ($body as $line) {
            $lines[] = [[$line, GraphFont::Regular], [\str_repeat(' ', \max(0, $width - AnsiUtils::visibleWidth($line))), GraphFont::Regular]];
        }
        $text = \implode("\n", [$header->getReturn(), $name->getReturn(), ...$body]);

        return [$text, $lines, $count];
    }

    /**
     * Pinned SysML markers: a circled plus where `contains` leaves its container, and an open
     * arrowhead, two strokes, where every other relationship arrives. Ranked edges come one per
     * relationship, in order.
     *
     * @param list<GraphEdge> $edges
     * @param list<array{from: string, to: string, kind: string, origin: int}> $relations
     * @return list<GraphEdge>
     */
    private static function markers(array $edges, array $relations): array
    {
        $marked = [];
        $index = 0;
        foreach ($edges as $edge) {
            $kind = $relations[$index++]['kind'] ?? '';
            $last = \count($edge->points) - 1;
            $line = $edge->untipped();
            $marked[] = $line;
            \array_push($marked, ...'contains' === $kind ? self::plus($line->points[0], $line->points[1]) : self::open($line->points[$last], $line->points[$last - 1]));
        }

        return $marked;
    }

    /**
     * @param array{float, float} $tip
     * @param array{float, float} $before
     * @return list<GraphEdge>
     */
    private static function open(array $tip, array $before): array
    {
        [$ux, $uy] = self::unit($before, $tip);

        return \array_map(static fn (float $side): GraphEdge => self::decoration([[$tip[0] - (1.6 * $ux) - (0.8 * $side * $uy), $tip[1] - (1.6 * $uy) + (0.8 * $side * $ux)], $tip]), [1.0, -1.0]);
    }

    /**
     * @param array{float, float} $start
     * @param array{float, float} $next
     * @return list<GraphEdge>
     */
    private static function plus(array $start, array $next): array
    {
        [$ux, $uy] = self::unit($start, $next);
        $center = [$start[0] + (0.72 * $ux), $start[1] + (0.72 * $uy)];
        $circle = [];
        for ($step = 0; $step <= 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $circle[] = [$center[0] + (0.72 * \cos($angle)), $center[1] + (0.72 * \sin($angle))];
        }

        return [
            self::decoration($circle, ribbon: $circle),
            self::decoration($circle),
            self::decoration([[$center[0] - (0.72 * $ux), $center[1] - (0.72 * $uy)], [$center[0] + (0.72 * $ux), $center[1] + (0.72 * $uy)]]),
            self::decoration([[$center[0] - (0.72 * $uy), $center[1] + (0.72 * $ux)], [$center[0] + (0.72 * $uy), $center[1] - (0.72 * $ux)]]),
        ];
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function unit(array $from, array $to): array
    {
        $length = \max(1.0e-9, \hypot($to[0] - $from[0], $to[1] - $from[1]));

        return [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length];
    }

    /**
     * @param list<array{float, float}> $points
     * @param ?list<array{float, float}> $ribbon a backdrop in the page color, so the line does not show through
     */
    private static function decoration(array $points, ?array $ribbon = null): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return new GraphEdge(0, 0, $points, null, \min($xs), \min($ys), \max($xs), \max($ys), null === $ribbon ? null : '@background', null, null, dashed: false, ribbon: $ribbon, decoration: true);
    }
}
