<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Section headers and leaves are semantic; full section backgrounds cannot occlude their children. */
final readonly class TreemapLayout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /**
     * Pinned `treemap.valueFormat`: a d3-format specifier, with its own `$` currency handling;
     * an invalid one falls back to `,`.
     *
     * @return \Closure(float): string
     */
    private function formatter(): \Closure
    {
        $specifier = $this->options->text('treemap', 'valueFormat');
        $specifier = '' === $specifier ? ',' : $specifier;
        $precision = [];
        \preg_match('/\.\d+/', $specifier, $precision);
        [$currency, $rest] = match (true) {
            \str_starts_with($specifier, '$') && \str_contains($specifier, ',') => ['$', ','.($precision[0] ?? '')],
            \str_starts_with($specifier, '$') => ['$', \substr($specifier, offset: 1)],
            default => ['', $specifier],
        };
        try {
            $format = D3Format::parse($rest);
        } catch (\InvalidArgumentException) {
            $format = D3Format::parse(',');
        }

        return static fn (float $value): string => $currency.$format->format($value);
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(TreemapDiagram $source): \Generator
    {
        $nodes = [];
        $legend = [];
        $elements = [];
        $shapes = [];
        $top = 2.0;
        $titles = [];
        foreach ($source->metadata as $name => $item) {
            $wrap = MermaidWrap::text($item['label'], width: 60);
            yield from $wrap;
            $draw = $wrap->getReturn();
            [, $height] = MermaidText::box($draw);
            $titles[] = ['name' => $name, 'draw' => $draw, 'height' => $height, 'top' => $top];
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
            $top += $height + 2;
            yield null;
        }
        $tiles = new TreemapTiles()->rectangles($source, $top);
        yield from $tiles;
        [$boxes, $width, $height] = $tiles->getReturn();
        $backgrounds = [];
        $colors = new TreemapColors($source);
        $format = $this->formatter();
        foreach ($source->nodes as $id => $item) {
            $box = $boxes[$id];
            $section = null === $item['value'];
            // Pinned `showValues` labels each node with its value through `valueFormat`; zero shows nothing.
            $value = $this->options->flag('treemap', 'showValues') && 0.0 !== $colors->sums[$id] ? $format($colors->sums[$id]) : '';
            // Pinned opacities: sections fill at 60% under a 40% peer border, leaves fill at 30%.
            $style = self::style(
                $source->classes[$item['class']] ?? [],
                $colors->fills[$id].($section ? '@60' : '@30'),
                $section ? $colors->strokes[$id].'@40' : $colors->strokes[$id],
                $colors->labels[$id],
            );
            $label = $item['label'].('' === $value ? '' : ' ('.$value.')');
            $body = $item['label'].('' === $value ? '' : ($section ? '  ' : "\n").$value);
            $extent = $section ? \min(6.0, $box['h']) : $box['h'];
            $fit = self::fit($body, $box['w'], $extent);
            yield from $fit;
            $nodes[$id] = new GraphNode(
                $id,
                'treemap-'.$id,
                $label,
                $box['x'] + ($box['w'] / 2),
                $box['y'] + ($extent / 2),
                $box['w'],
                $extent,
                $section ? 'none' : $style['stroke'],
                $section ? 'none' : $style['fill'],
                $style['dashed'],
                strokeWidth: $section ? 0.0 : $style['width'],
                drawLabel: $fit->getReturn(),
                textColor: $style['text'],
                dashes: $style['dashes'],
            );
            $elements[$id] = $item['origin'];
            $legend[] = ['text' => $label, 'origin' => $item['origin']];
            if ($section) {
                $backgrounds[] = ['box' => $box, 'style' => $style];
            }
            yield null;
        }
        foreach ($backgrounds as $item) {
            $id = \count($nodes);
            $box = $item['box'];
            $style = $item['style'];
            $nodes[$id] = new GraphNode(
                $id,
                'section-'.$id,
                '',
                $box['x'] + ($box['w'] / 2),
                $box['y'] + ($box['h'] / 2),
                $box['w'],
                $box['h'],
                $style['stroke'],
                $style['fill'],
                $style['dashed'],
                background: true,
                strokeWidth: $style['width'],
                dashes: $style['dashes'],
            );
            yield null;
        }
        foreach ($titles as $item) {
            $id = \count($nodes);
            $nodes[$id] = new GraphNode(
                $id,
                $item['name'],
                $item['draw'],
                $width / 2,
                $item['top'] + ($item['height'] / 2),
                $width - 4,
                $item['height'],
                null,
                null,
                dashed: false,
                background: true,
                textColor: '@titleColor',
            );
            $shapes[$id] = 'text';
            yield null;
        }
        $rows = self::origins($source, $nodes, $height);
        yield from $rows;

        return new MermaidScene(new GraphLayout($width, $height, $nodes, edges: [], clusters: []), $rows->getReturn(), $legend, $elements, $shapes);
    }

    /** @param array<int, GraphNode> $nodes
     * @return \Generator<int, null, mixed, list<int>>
     */
    private static function origins(TreemapDiagram $source, array $nodes, float $height): \Generator
    {
        $origins = MermaidOrigins::rows($height + 2, $source->origin);
        foreach ($source->nodes as $id => $item) {
            $node = $nodes[$id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $item['origin'];
                yield null;
            }
        }

        return $origins;
    }

    /** @return \Generator<int, null, mixed, string> */
    private static function fit(string $text, float $width, float $height): \Generator
    {
        $columns = (int) \floor($width - 4);
        $maximum = (int) \floor(($height - 2) / 2);
        if ($columns < 1 || $maximum < 1) {
            return '';
        }
        $wrap = MermaidWrap::text($text, $columns);
        yield from $wrap;
        $all = \explode("\n", $wrap->getReturn());
        $lines = \array_slice($all, offset: 0, length: $maximum);
        $last = \count($lines) - 1;
        if (\count($all) > $maximum) {
            $lines[$last] = \mb_strimwidth($lines[$last], start: 0, width: \max(0, $columns - 1)).'…';
        }
        foreach ($lines as $index => $line) {
            $lines[$index] = \mb_strimwidth($line, start: 0, width: $columns, trim_marker: '…');
            yield null;
        }

        return \implode("\n", $lines);
    }

    /** @param array<string, float|string|bool> $style
     * @return array{fill: string, stroke: string, width: float, dashed: bool, dashes: ?list<float>, text: string}
     */
    private static function style(array $style, string $fill, string $stroke, string $text): array
    {
        $authored = \is_string($style['fill'] ?? null) ? $style['fill'] : null;
        $outline = \is_string($style['stroke'] ?? null) ? $style['stroke'] : $stroke;
        $width = \is_float($style['stroke-width'] ?? null) ? $style['stroke-width'] : 0.2;

        // An authored fill without an authored color keeps its label readable.
        $readable = null === $authored ? $text : TreemapStyles::contrast($authored);

        [$fill, $outline, $text] = MermaidCss::faded($style, $authored ?? $fill, $outline, \is_string($style['color'] ?? null) ? $style['color'] : $readable);

        return [
            'fill' => $fill ?? '',
            'stroke' => $outline ?? '',
            'width' => 'none' === $outline ? 0.0 : $width,
            'dashed' => true === ($style['stroke-dasharray'] ?? false),
            'dashes' => MermaidCss::dashes($style, current: null),
            'text' => $text ?? '',
        ];
    }
}
