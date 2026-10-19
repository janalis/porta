<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Source-ordered outline rows with elbow connectors and full semantic labels. As pinned, every
 * tree hangs from an implicit bold `/` root, directories draw bold without their slash,
 * descriptions follow in italics, and icons stand before their label.
 */
final readonly class TreeViewLayout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(TreeViewDiagram $source): \Generator
    {
        $nodes = [];
        $edges = [];
        $elements = [];
        $shapes = [];
        $legend = [];
        $origins = [$source->origin];
        $width = 12.0;
        $decorations = [];
        $decorationShapes = [];
        $metadata = self::metadata($source, $decorations, $decorationShapes, $legend, $origins, $width, top: 2.0);
        yield from $metadata;
        $top = $metadata->getReturn();
        $rootId = \count($source->nodes) + \count($decorations);
        $root = new GraphNode($rootId, 'tree-view-root', '/', 6.0, $top + 2, 4.0, 4.0, null, null, dashed: false, background: true, textColor: '@treeView.labelColor|=#000000', font: GraphFont::Bold);
        $top += 4;
        $runs = [];
        // Pinned `rowIndent` pixels per level, eight units at its default of ten; `lineThickness` pixels.
        $indent = (8 * \max(0.0, $this->options->number('treeView', 'rowIndent', fallback: 10.0))) / 10;
        $thickness = \max(0.0, $this->options->number('treeView', 'lineThickness', fallback: 1.0)) / 10;
        foreach ($source->nodes as $id => $item) {
            $label = $item['label'];
            $description = $item['description'];
            $icon = TreeViewIcons::icon($label, $item['icon'], $this->options);
            $summary = $label.('' === $description ? '' : ' — '.$description);
            // Pinned directories draw their name without the slash; a description follows it.
            $name = \str_ends_with($label, '/') ? \substr($label, offset: 0, length: -1) : $label;
            $wrap = MermaidWrap::text($name.('' === $description ? '' : '  '.$description), width: 100);
            yield from $wrap;
            $draw = $wrap->getReturn();
            $columns = \max(1, ...\array_map(AnsiUtils::visibleWidth(...), \explode("\n", $draw)));
            $w = (float) ($columns + 4) + (null === $icon ? 0.0 : TreeViewIcons::SPAN);
            $h = (float) (2 * (\substr_count($draw, needle: "\n") + 2));
            $left = 4.0 + (($item['depth'] + 1) * $indent);
            $highlight = 'highlight' === $item['class'];
            $runs['node:'.$id] = self::runs($draw, \mb_strlen(\str_replace("\n", replace: '', subject: $name)), \str_ends_with($label, '/') ? GraphFont::Bold : GraphFont::Regular);
            $nodes[$id] = new GraphNode(
                $id,
                'tree-view-'.$id,
                $summary,
                $left + ($w / 2),
                $top + ($h / 2),
                $w,
                $h,
                '@treeView.highlightStroke|=#ffc107',
                $highlight ? '@treeView.highlightBg|=#ffc10726' : null,
                dashed: false,
                textColor: '@treeView.labelColor|=#000000',
                drawLabel: $draw,
            );
            $base = $highlight ? 'rounded' : 'text';
            $shapes[$id] = null === $icon ? $base : TreeViewIcons::shape($icon, $base);
            $elements[$id] = $item['origin'];
            $iconText = null === $item['icon'] || 'none' === $item['icon'] ? '' : '['.$item['icon'].'] ';
            $legend[] = ['text' => $iconText.$summary.('' === $item['class'] ? '' : ' :::'.$item['class']), 'origin' => $item['origin']];
            for ($row = (int) ($top / 2); $row <= (int) (($top + $h) / 2); ++$row) {
                $origins[$row] = $item['origin'];
                yield null;
            }
            $edges[] = self::connector(null === $item['parent'] ? $root : $nodes[$item['parent']], $nodes[$id])->stroked($thickness);
            $width = \max($width, $left + $w + 2);
            $top += $h;
            yield null;
        }
        foreach ($decorations as $decoration) {
            $id = \count($nodes);
            $nodes[$id] = new GraphNode($id, $decoration->name, $decoration->label, $decoration->x, $decoration->y, $decoration->w, $decoration->h, null, null, dashed: false, background: true);
            $shapes[$id] = 'text';
            yield null;
        }
        $nodes[$rootId] = $root;
        $shapes[$rootId] = 'text';
        $height = \max(8.0, $top + 2);

        return new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, clusters: []), $origins, $legend, $elements, $shapes, runs: $runs);
    }

    /** The elbow from a parent's foot to its child's left side. */
    private static function connector(GraphNode $parent, GraphNode $child): GraphEdge
    {
        $x = $parent->x - ($parent->w / 2) + 2;
        $y = $parent->y + ($parent->h / 2);
        $left = $child->x - ($child->w / 2);

        return new GraphEdge($parent->id, $child->id, [[$x, $y], [$x, $child->y], [$left, $child->y]], null, $x, $y, $left, $child->y, '@treeView.lineColor|=#000000', null, null, dashed: false);
    }

    /**
     * A wrapped row's runs: the name in its face, the description after it in italics.
     *
     * @return list<list<array{string, GraphFont}>>
     */
    private static function runs(string $draw, int $name, GraphFont $face): array
    {
        $lines = [];
        $consumed = 0;
        foreach (\explode("\n", $draw) as $line) {
            $split = \max(0, \min(\mb_strlen($line), $name - $consumed));
            $runs = [[\mb_substr($line, start: 0, length: $split), $face], [\mb_substr($line, $split), GraphFont::Italic]];
            $lines[] = \array_values(\array_filter($runs, static fn (array $run): bool => '' !== $run[0]));
            $consumed += \mb_strlen($line);
        }

        return $lines;
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     * @param list<array{text: string, origin: int}> $legend
     * @param list<int> $origins
     * @return \Generator<int, null, mixed, float>
     */
    private static function metadata(TreeViewDiagram $source, array &$nodes, array &$shapes, array &$legend, array &$origins, float &$width, float $top): \Generator
    {
        foreach ($source->metadata as $name => $item) {
            $wrap = MermaidWrap::text($item['label'], width: 60);
            yield from $wrap;
            $draw = $wrap->getReturn();
            [$w, $h] = MermaidText::box($draw);
            $id = \count($nodes);
            $nodes[$id] = new GraphNode($id, $name, $draw, 2 + ($w / 2), $top + ($h / 2), $w, $h, null, null, dashed: false, background: true);
            $shapes[$id] = 'text';
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
            for ($row = (int) ($top / 2); $row <= (int) (($top + $h) / 2); ++$row) {
                $origins[$row] = $item['origin'];
                yield null;
            }
            $top += $h + 2;
            $width = \max($width, $w + 4);
        }

        return $top;
    }
}
