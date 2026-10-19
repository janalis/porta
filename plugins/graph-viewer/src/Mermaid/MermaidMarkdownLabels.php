<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Markdown string labels around a graph layout: before it, each label takes its wrapped plain
 * text, so nodes, frames and captions size to it; after it, the scene keeps the styled runs
 * that drawings paint.
 */
final readonly class MermaidMarkdownLabels
{
    /**
     * Pinned `markdownAutoWrap` wraps at the family's label width: flowchart `wrappingWidth`,
     * mindmap `maxNodeWidth`.
     */
    public static function prepare(MermaidGraph $graph, MermaidOptions $options, string $family): void
    {
        $width = 'mindmap' === $family ? $options->number('mindmap', 'maxNodeWidth', fallback: 200.0) : $options->number('flowchart', 'wrappingWidth', fallback: 120.0);
        $wrap = false !== $options->root('markdownAutoWrap');
        if ('mindmap' === $family) {
            self::mindmap($graph, $width);
        }
        foreach ($graph->markdown as $key => $markdown) {
            $markdown = $wrap ? $markdown->wrap($width) : $markdown;
            $graph->markdown[$key] = $markdown;
            $plain = MermaidText::decode($markdown->plain());
            [$kind, $id] = \explode(':', $key, limit: 2);
            match ($kind) {
                'node' => $graph->nodes[$id]['label'] = $plain,
                'edge' => $graph->edges[(int) $id]['label'] = $plain,
                default => $graph->groups[$id]['label'] = $plain,
            };
        }
    }

    /**
     * Pinned mindmap labels of any kind wrap once wider than `maxNodeWidth`: plain text breaks
     * between words as Markdown does, its emphasis characters kept as written.
     */
    private static function mindmap(MermaidGraph $graph, float $width): void
    {
        // Markdown labels are wrapped again from their source right after, replacing this text.
        foreach ($graph->nodes as $id => $node) {
            $escaped = \preg_replace('/[\\\\*_]/', replacement: '\\\\$0', subject: $node['label']) ?? $node['label'];
            $graph->nodes[$id]['label'] = MermaidMarkdown::parse($escaped)->wrap($width)->plain();
        }
    }

    /** The scene with the runs of every laid-out Markdown label. */
    public static function apply(MermaidScene $scene, MermaidGraph $graph): MermaidScene
    {
        $runs = [];
        foreach ($scene->graph->nodes as $id => $node) {
            $markdown = $graph->markdown['node:'.$node->name] ?? null;
            if (null !== $markdown) {
                $runs['node:'.$id] = $markdown->lines;
            }
        }
        foreach (\array_keys($scene->graph->edges) as $index) {
            $markdown = $graph->markdown['edge:'.$index] ?? null;
            if (null !== $markdown) {
                $runs['edge:'.$index] = $markdown->lines;
            }
        }
        foreach ($scene->graph->clusters as $index => $cluster) {
            $markdown = $graph->markdown['group:'.$cluster->name] ?? null;
            if (null !== $markdown) {
                $runs['cluster:'.$index] = $markdown->lines;
            }
        }

        return $scene->styled($runs);
    }
}
