<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Random\Randomizer;

/** Nested groups are opaque during parent placement, then expand without overlapping their siblings. */
final readonly class ArchitecturePlacement
{
    public function __construct(
        private ArchitectureConfiguration $configuration = new ArchitectureConfiguration(),
        private Randomizer $randomizer = new Randomizer(),
    ) {}

    /** @return \Generator<int, null, mixed, GraphLayout> */
    public function place(ArchitectureDiagram $source): \Generator
    {
        $numbers = [];
        $scopes = [''];
        foreach ($source->items as $id => $item) {
            $numbers[$id] = \count($numbers);
            if ('group' === $item['kind']) {
                $scopes[] = (string) $id;
            }
            yield null;
        }
        $layouts = [];
        foreach (\array_reverse($scopes) as $scope) {
            $items = [];
            foreach ($source->items as $id => $item) {
                if (($item['in'] ?? '') !== $scope) {
                    continue;
                }
                $label = MermaidText::decode($item['title'] ?? $item['id']);
                $wrap = MermaidWrap::text($label, width: 40);
                yield from $wrap;
                $label = $wrap->getReturn();
                [$baseWidth, $baseHeight] = MermaidText::box($label);
                $width = \max(8.0, $baseWidth);
                $height = $baseHeight + 8;
                if ('group' === $item['kind']) {
                    $width = $baseWidth;
                    $height = $baseHeight;
                    $child = $layouts[$id];
                    if (null !== $item['icon']) {
                        $width += 8;
                        $height = \max(8.0, $height);
                    }
                    $width = \max($width + 4, $child->width + 4);
                    $height += $child->height + 4;
                }
                if ('junction' === $item['kind']) {
                    $width = 2.0;
                    $height = 2.0;
                }
                $items[$id] = new GraphNode(
                    $numbers[$id],
                    (string) $id,
                    $label,
                    x: 0.0,
                    y: 0.0,
                    w: $width,
                    h: $height,
                    // The icon box colors the node in cells; junctions take the edge color.
                    color: 'junction' === $item['kind'] ? '@archEdgeColor' : '#087ebf',
                    fillColor: '@background',
                    dashed: false,
                    textColor: '@textColor',
                    drawLabel: '',
                );
                yield null;
            }
            $edges = [];
            foreach ($source->edges as $edge) {
                $from = self::representative($source, $edge['lhsId'], $scope);
                $to = self::representative($source, $edge['rhsId'], $scope);
                if (null !== $from && null !== $to && $from !== $to) {
                    $edges[] = ['from' => $from, 'to' => $to, 'left' => $edge['lhsDir'], 'right' => $edge['rhsDir']];
                }
                yield null;
            }
            $hints = [];
            foreach ($source->alignments as $hint) {
                $members = [];
                foreach ($hint['members'] as $id) {
                    $member = self::representative($source, $id, $scope);
                    if (null !== $member && !\in_array($member, $members, strict: true)) {
                        $members[] = $member;
                    }
                    yield null;
                }
                if (\count($members) > 1) {
                    $hints[] = ['direction' => $hint['direction'], 'members' => $members];
                }
                yield null;
            }
            $ordered = [];
            foreach ($this->configuration->order(\array_keys($items), $scope, $this->randomizer) as $id) {
                $ordered[$id] = $items[$id];
            }
            $work = ArchitectureGrid::place($ordered, $edges, $hints);
            yield from $work;
            $layouts[$scope] = $work->getReturn();
        }
        $expansion = self::expand($source, $layouts);
        yield from $expansion;

        return $expansion->getReturn();
    }

    /** @param array<array-key, GraphLayout> $layouts
     * @return \Generator<int, null, mixed, GraphLayout>
     */
    private static function expand(ArchitectureDiagram $source, array $layouts): \Generator
    {
        $nodes = [];
        $clusters = [];
        $queue = [['', 0.0, 0.0]];
        for ($cursor = 0; $cursor < \count($queue); ++$cursor) {
            [$scope, $dx, $dy] = $queue[$cursor];
            foreach ($layouts[$scope]->nodes as $node) {
                $node = $node->at($node->x + $dx, $node->y + $dy);
                $item = $source->items[$node->name];
                if ('group' !== $item['kind']) {
                    $nodes[$node->id] = $node;
                    yield null;
                    continue;
                }
                [$labelWidth, $labelHeight] = MermaidText::box($node->label);
                if (null !== $item['icon']) {
                    $labelWidth += 8;
                    $labelHeight = \max(8.0, $labelHeight);
                }
                $left = $node->x - ($node->w / 2);
                $top = $node->y - ($node->h / 2);
                $clusters[] = new GraphCluster($node->name, '', $left, $top, $left + $node->w, $top + $node->h, '@archGroupBorderColor', null, 'none', dashed: true);
                $nodes[$node->id] = new GraphNode(
                    $node->id,
                    $node->name,
                    $node->label,
                    $node->x,
                    $top + ($labelHeight / 2) + 1,
                    $labelWidth,
                    $labelHeight,
                    '@archGroupBorderColor',
                    '@background',
                    dashed: false,
                    textColor: '@textColor',
                    drawLabel: null === $item['icon'] ? null : '',
                );
                $queue[] = [$node->name, $left + (($node->w - $layouts[$node->name]->width) / 2), $top + $labelHeight + 2];
                yield null;
            }
        }

        return new GraphLayout($layouts['']->width, $layouts['']->height, $nodes, [], $clusters);
    }

    private static function representative(ArchitectureDiagram $source, string $id, string $scope): ?string
    {
        while (true) {
            $parent = $source->items[$id]['in'] ?? '';
            if ($parent === $scope) {
                return $id;
            }
            if ('' === $parent) {
                return null;
            }
            $id = $parent;
        }
    }
}
