<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Request-local drawing assembly keeps circles and filled intersections outside semantic selection. */
final class VennCanvas
{
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var array<int, string> */
    private array $shapes = [];
    /** @var array<int, int> */
    private array $elements = [];
    /** @var list<GraphEdge> */
    private array $edges = [];
    private float $width = 8.0;
    private float $height = 8.0;

    /** @param array<array-key, GraphNode> $circles
     * @return \Generator<int, null, mixed, void>
     */
    public function circles(array $circles, VennStyles $styles, VennTheme $theme): \Generator
    {
        foreach (\array_values($circles) as $index => $circle) {
            $color = $theme->circle($index);
            $paint = $styles->resolve([$circle->name], VennPaint::circle($color));
            $id = \count($this->nodes);
            $this->nodes[$id] = new GraphNode(
                $id,
                '@venn-circle:'.$circle->name,
                '',
                $circle->x,
                $circle->y,
                $circle->w,
                $circle->h,
                $paint->strokeColor(),
                $paint->fill,
                dashed: false,
                background: true,
                strokeWidth: $paint->width,
                drawLabel: '',
            );
            $this->shapes[$id] = 'venn-circle:'.($paint->opacity * $paint->fillAlpha).':'.$paint->strokeAlpha();
            $this->width = \max($this->width, $circle->x + ($circle->w / 2) + 4);
            $this->height = \max($this->height, $circle->y + ($circle->h / 2) + 4);
            yield null;
        }
    }

    /** @param array<array-key, GraphNode> $circles
     * @return \Generator<int, null, mixed, void>
     */
    public function regions(VennDiagram $source, array $circles, VennStyles $styles): \Generator
    {
        $regions = [];
        foreach ($source->subsets as $subset) {
            if (\count($subset['sets']) > 1) {
                $regions[VennDiagram::key($subset['sets'])] = $subset;
            }
            yield null;
        }
        foreach ($regions as $subset) {
            $paint = $styles->resolve($subset['sets'], new VennPaint());
            if ('none' === $paint->fill && 'none' === $paint->strokeColor()) {
                continue;
            }
            $work = VennRegion::polygon($circles, $subset['sets']);
            yield from $work;
            $points = $work->getReturn();
            if (\count($points) < 3) {
                continue;
            }
            $xs = \array_column($points, 0);
            $ys = \array_column($points, 1);
            $x = (\min($xs) + \max($xs)) / 2;
            $y = (\min($ys) + \max($ys)) / 2;
            $relative = \array_map(static fn (array $point): array => [$point[0] - $x, $point[1] - $y], $points);
            $id = \count($this->nodes);
            $this->nodes[$id] = new GraphNode(
                $id,
                '@venn-region:'.$id,
                '',
                $x,
                $y,
                \max($xs) - \min($xs),
                \max($ys) - \min($ys),
                $paint->strokeColor(),
                $paint->fill,
                dashed: false,
                background: true,
                strokeWidth: $paint->width,
                drawLabel: '',
            );
            $this->shapes[$id] = 'venn-region:'.($paint->opacity * $paint->fillAlpha).':'.$paint->strokeAlpha().':'.\base64_encode(\json_encode($relative, flags: \JSON_THROW_ON_ERROR));
            yield null;
        }
    }

    /** @param list<VennCaptionGroup> $groups
     * @return \Generator<int, null, mixed, void>
     */
    public function captions(array $groups, float $scale): \Generator
    {
        $fallbackY = 4.0;
        $outside = $this->width + 4;
        foreach ($groups as $group) {
            $point = $group->point;
            $x = $point['x'] * $scale;
            $y = $point['y'] * $scale;
            $fallback = $point['margin'] <= 0;
            if ($fallback) {
                $x = $outside + ($group->width / 2);
                $y = $fallbackY + ($group->height / 2);
                $fallbackY += $group->height + 2;
            }
            $top = $y - ($group->height / 2);
            foreach ($group->items as $item) {
                $id = \count($this->nodes);
                $labelY = $item->visible ? $top + ($item->height / 2) : $y;
                $this->nodes[$id] = new GraphNode($id, $item->name, $item->label, $x, $labelY, $item->width, $item->height, null, null, dashed: false, drawLabel: '', textColor: $item->paint->color);
                $this->shapes[$id] = 'venn-text:'.$item->paint->colorAlpha.':'.($item->visible ? '1' : '0');
                $this->elements[$id] = $item->statement->line;
                if ($item->visible) {
                    $top += $item->height;
                }
                $this->width = \max($this->width, $x + ($item->width / 2) + 4);
                $this->height = \max($this->height, $top + 4);
                yield null;
            }
            if ($fallback) {
                $points = [[$x - ($group->width / 2), $y], [$point['x'] * $scale, $point['y'] * $scale]];
                $this->edges[] = new GraphEdge(
                    -1,
                    -1,
                    $points,
                    null,
                    \min(\array_column($points, 0)),
                    \min(\array_column($points, 1)),
                    \max(\array_column($points, 0)),
                    \max(\array_column($points, 1)),
                    '#888888',
                    null,
                    null,
                    dashed: true,
                    decoration: true,
                );
            }
        }
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function scene(VennDiagram $source): \Generator
    {
        $origins = MermaidOrigins::rows($this->height, $source->origin);
        foreach ($this->elements as $id => $origin) {
            $node = $this->nodes[$id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $origin;
                yield null;
            }
        }
        $legend = [];
        foreach ($source->subsets as $subset) {
            $name = \implode(' & ', $subset['sets']);
            $label = null === $subset['label'] ? $name : $subset['label'].' ('.$name.')';
            $legend[] = ['text' => $label.': '.$subset['size'], 'origin' => $subset['statement']->line];
            yield null;
        }
        foreach ($source->texts as $text) {
            $legend[] = ['text' => \implode(' & ', $text['sets']).' / '.$text['id'].': '.($text['label'] ?? $text['id']), 'origin' => $text['statement']->line];
            yield null;
        }

        return new MermaidScene(new GraphLayout($this->width, $this->height, $this->nodes, $this->edges, []), $origins, $legend, $this->elements, $this->shapes);
    }
}
