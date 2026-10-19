<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\Theme\JsObject;

/** Curves share a radial scale; only data markers are selectable, never captions or grid decorations. */
final readonly class RadarLayout
{
    private MermaidOptions $options;
    private MermaidPalette $palette;

    public function __construct(?MermaidOptions $options = null, ?MermaidPalette $palette = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
        $this->palette = $palette ?? MermaidPalette::defaults('radar-beta');
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(RadarDiagram $source): \Generator
    {
        $nodes = [];
        $shapes = [];
        $originMap = [];
        $elements = [];
        $legend = [];
        $top = 2.0;
        foreach ($source->metadata as $name => $item) {
            $caption = self::caption($nodes, $shapes, $originMap, $item['label'], x: 104.0, y: $top, origin: $item['origin']);
            yield from $caption;
            $top += $caption->getReturn()->h + 2;
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
        }
        $centerY = $top + 100;
        $grid = RadarGrid::edges($source, $centerY, $this->options->number('radar', 'axisScaleFactor', fallback: 1.0), $this->opacity('graticuleOpacity', 0.3));
        yield from $grid;
        $edges = $grid->getReturn();
        $axes = $this->axes($source, $centerY, $nodes, $shapes, $originMap);
        yield from $axes;
        $scale = self::maximum($source);
        yield from $scale;
        $maximum = $scale->getReturn();
        $footer = $centerY + 104;
        foreach ($source->curves as $index => $curve) {
            // Pinned `.radarCurve-N` rules exist for the twelve `cScale` colors; later curves stay unstyled.
            $color = '@cScale'.$index.'|=#000000';
            $points = [];
            foreach ($curve['entries'] as $axis => $entry) {
                $fraction = $maximum === $source->minimum ? 0.0 : XyScale::fraction($entry['value'], [$source->minimum, $maximum]);
                [$x, $y] = RadarGrid::point($fraction, $axis, \count($source->axes), $centerY);
                $points[] = [$x, $y];
                $id = \count($nodes);
                $summary = $curve['label'].' · '.$source->axes[$axis]['label'].': '.$entry['value'];
                $nodes[$id] = new GraphNode($id, 'radar-'.$index.'-'.$axis, $summary, $x, $y, 1.4, 1.4, $color, $color, dashed: false, drawLabel: '');
                $shapes[$id] = 'point';
                $elements[$id] = $entry['origin'];
                $originMap[$id] = $entry['origin'];
                $legend[] = ['text' => $summary, 'origin' => $entry['origin']];
                yield null;
            }
            if ('circle' === $source->graticule) {
                $smooth = RadarGrid::smooth($points, $this->options->number('radar', 'curveTension', fallback: 0.17));
                yield from $smooth;
                $points = $smooth->getReturn();
            }
            if ('polygon' === $source->graticule) {
                $points[] = $points[0];
            }
            // Pinned curves fill at `radar.curveOpacity` beneath their outline.
            $edges[] = RadarGrid::edge($points, $color.'@'.$this->opacity('curveOpacity', 0.5), filled: true);
            $edges[] = RadarGrid::edge($points, $color);
            if ($source->showLegend) {
                $caption = self::caption($nodes, $shapes, $originMap, $curve['label'], x: 104.0, y: $footer, origin: $curve['origin']);
                yield from $caption;
                $labelNode = $caption->getReturn();
                $footer += $labelNode->h + 2;
                $id = \count($nodes);
                $nodes[$id] = new GraphNode($id, 'swatch-'.$index, '', $labelNode->x - ($labelNode->w / 2) - 2, $labelNode->y, 2.0, 2.0, $color, $color.'@50', dashed: false, background: true);
                $originMap[$id] = $curve['origin'];
            }
        }
        $height = $footer + 4;
        $origins = self::rows($source, $nodes, $originMap, $height);
        yield from $origins;

        return new MermaidScene(new GraphLayout(208.0, $height, $nodes, $edges, clusters: []), $origins->getReturn(), $legend, $elements, $shapes);
    }

    /** A `radar` theme opacity as a whole percentage, for a `@NN` color token. */
    private function opacity(string $key, float $default): int
    {
        // A theme without a `radar` object keeps the pinned defaults, the default theme's values.
        // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed below.
        $radar = JsObject::get($this->palette->appearance->variables, 'radar');
        // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed below.
        $value = $radar instanceof \stdClass ? JsObject::get($radar, $key) : null;

        return (int) \round(100 * \max(0.0, \min(1.0, \is_int($value) || \is_float($value) ? (float) $value : $default)));
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, int> $originMap
     * @return \Generator<int, null, mixed, list<int>>
     */
    private static function rows(RadarDiagram $source, array $nodes, array $originMap, float $height): \Generator
    {
        $rows = MermaidOrigins::rows($height + 2, $source->origin);
        foreach ($nodes as $id => $node) {
            for ($row = \max(0, (int) \floor(($node->y - ($node->h / 2)) / 2)); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $originMap[$id];
                yield null;
            }
        }

        return $rows;
    }

    /** @return \Generator<int, null, mixed, float> */
    private static function maximum(RadarDiagram $source): \Generator
    {
        $maximum = $source->maximum ?? $source->minimum;
        foreach ($source->curves as $curve) {
            foreach ($curve['entries'] as $entry) {
                if (null === $source->maximum) {
                    $maximum = \max($maximum, $entry['value']);
                }
                yield null;
            }
        }

        return $maximum;
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     * @param array<int, int> $origins
     * @return \Generator<int, null, mixed, null>
     */
    private function axes(RadarDiagram $source, float $centerY, array &$nodes, array &$shapes, array &$origins): \Generator
    {
        foreach ($source->axes as $index => $axis) {
            $short = \mb_strimwidth($axis['label'], start: 0, width: 256, trim_marker: '…');
            $wrap = MermaidWrap::text($short, width: 24);
            yield from $wrap;
            $draw = $wrap->getReturn();
            [$w, $h] = MermaidText::box($draw);
            // Native labels sit further out than the pinned 1.05 default so terminal text clears the grid.
            [$x, $y] = RadarGrid::point((1.17 * $this->options->number('radar', 'axisLabelFactor', fallback: 1.05)) / 1.05, $index, \count($source->axes), $centerY);
            $x += ($x <=> 104.0) * ($w / 2);
            $y += ($y <=> $centerY) * ($h / 2);
            $id = \count($nodes);
            $nodes[$id] = new GraphNode($id, $axis['name'], $axis['label'], $x, $y, $w, $h, null, null, dashed: false, background: true, textColor: '@textColor', drawLabel: $draw);
            $shapes[$id] = 'text';
            $origins[$id] = $axis['origin'];
            yield null;
        }

        return null;
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     * @param array<int, int> $origins
     * @return \Generator<int, null, mixed, GraphNode>
     */
    private static function caption(array &$nodes, array &$shapes, array &$origins, string $text, float $x, float $y, int $origin): \Generator
    {
        $wrap = MermaidWrap::text($text, width: 60);
        yield from $wrap;
        $draw = $wrap->getReturn();
        [$w, $h] = MermaidText::box($draw);
        $id = \count($nodes);
        $nodes[$id] = new GraphNode($id, 'caption-'.$id, $text, $x, $y + ($h / 2), $w, $h, null, null, dashed: false, background: true, textColor: '@textColor', drawLabel: $draw);
        $shapes[$id] = 'text';
        $origins[$id] = $origin;

        return $nodes[$id];
    }
}
