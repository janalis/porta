<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Pinned treemap colors: d3 ordinal scales keyed by name, in the order the renderer first asks.
 * The fill and peer scales start with `transparent` for the unnamed root, then take sections
 * breadth first; leaves fill with their parent's color. The label scale continues past the
 * sections into the leaves, pre-order. Children sort by value, largest first.
 */
final readonly class TreemapColors
{
    /** @var array<int, string> fill tokens by node id */
    public array $fills;
    /** @var array<int, string> stroke tokens by node id: the section peer, or the leaf fill */
    public array $strokes;
    /** @var array<int, string> text tokens by node id */
    public array $labels;
    /** @var array<int, float> each node's value: a leaf's own, a section's subtree total */
    public array $sums;

    public function __construct(TreemapDiagram $source)
    {
        $sums = [];
        // Children follow their parent in source order, so a reverse pass sums each subtree.
        foreach (\array_reverse(\array_keys($source->nodes)) as $id) {
            $sums[$id] = $source->nodes[$id]['value'] ?? 0.0;
            foreach ($source->children[$id] ?? [] as $child) {
                $sums[$id] += $sums[$child];
            }
        }
        $children = [];
        foreach ($source->children as $parent => $ids) {
            \usort($ids, static fn (int $a, int $b): int => $sums[$b] <=> $sums[$a]);
            $children[$parent] = $ids;
        }
        $keys = [''];
        $sections = [];
        $queue = $children[-1] ?? [];
        while ([] !== $queue) {
            $id = \array_shift($queue);
            if ([] !== ($children[$id] ?? [])) {
                $sections[] = $id;
                $keys[] = $source->nodes[$id]['label'];
                \array_push($queue, ...$children[$id]);
            }
        }
        $fill = \array_flip(\array_values(\array_unique($keys)));
        $labels = \array_slice($keys, offset: 1); // The hidden root never asks for a label color.
        $stack = \array_reverse($children[-1] ?? []);
        while ([] !== $stack) {
            $id = \array_pop($stack);
            if ([] === ($children[$id] ?? [])) {
                $labels[] = $source->nodes[$id]['label'];
            }
            \array_push($stack, ...\array_reverse($children[$id] ?? []));
        }
        $label = \array_flip(\array_values(\array_unique($labels)));
        $fills = [];
        $strokes = [];
        $texts = [];
        foreach ($source->nodes as $id => $item) {
            $section = \in_array($id, $sections, strict: true);
            // A leaf fills with its parent's color; a top-level leaf with the root's.
            $parent = null === $item['parent'] ? '' : $source->nodes[$item['parent']]['label'];
            $key = $section ? $item['label'] : $parent;
            $fills[$id] = self::scale('cScale', $fill[$key]);
            $strokes[$id] = $section ? self::scale('cScalePeer', $fill[$key]) : $fills[$id];
            $texts[$id] = '@cScaleLabel'.($label[$item['label']] % 12);
        }
        $this->fills = $fills;
        $this->strokes = $strokes;
        $this->labels = $texts;
        $this->sums = $sums;
    }

    /** Entry 0 of the thirteen-color range is the root's `transparent`. */
    private static function scale(string $prefix, int $index): string
    {
        $index %= 13;

        return 0 === $index ? 'none' : '@'.$prefix.($index - 1);
    }
}
