<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Labels remain readable in cells; native pictures use ordered sectors and a separate legend. */
final class PieCanvas
{
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var array<int, string> */
    private array $shapes = [];
    /** @var array<int, int> */
    private array $elements = [];

    /** Pinned default-theme sizes stand in when a variable is not a usable length. */
    private const array SIZES = ['pieTitleTextSize' => 25.0, 'pieSectionTextSize' => 17.0, 'pieLegendTextSize' => 17.0, 'pieStrokeWidth' => 2.0, 'pieOuterStrokeWidth' => 2.0, 'pieOpacity' => 0.7];

    public function __construct(
        private readonly MermaidConfiguration $configuration,
        private readonly MermaidPalette $palette,
    ) {}

    /** @param list<array{display: string, text: string, origin: int, ratio: ?float, highlight: bool}> $items
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    public function layout(array $items): \Generator
    {
        $top = 2.0;
        $legend = [];
        $slices = [];
        $labelWidth = 1.0;
        $legendHeight = 0.0;
        $width = 56.0;
        foreach ($items as $item) {
            $work = MermaidWrap::text($item['display'], width: 60);
            yield from $work;
            $item['display'] = $work->getReturn();
            $scale = $this->number(null === $item['ratio'] ? 'pieTitleTextSize' : 'pieLegendTextSize') / 16;
            [$w, $h] = MermaidText::box($item['display']);
            $w *= $scale;
            $h *= $scale;
            $legend[] = ['text' => $item['text'], 'origin' => $item['origin']];
            if (null === $item['ratio']) {
                $this->text($item['display'], $w / 2, $top + ($h / 2), $w, $h, $this->color('pieTitleTextColor'), $scale);
                $width = \max($width, $w + 4);
                $top += $h + 2;
                yield null;
                continue;
            }
            $slices[] = $item;
            $labelWidth = \max($labelWidth, $w);
            $legendHeight += $h + 2;
            yield null;
        }
        [$cx, $cy, $lx, $ly, $w, $h] = $this->positions($top, $labelWidth, $legendHeight);
        $outerWidth = $this->number('pieOuterStrokeWidth') / 10;
        $outer = \count($this->nodes);
        $this->nodes[$outer] = new GraphNode(
            $outer,
            '@pie-outer',
            '',
            $cx,
            $cy,
            48 + $outerWidth,
            48 + $outerWidth,
            $this->color('pieOuterStrokeColor'),
            'none',
            dashed: false,
            background: true,
            strokeWidth: $outerWidth,
        );
        $this->shapes[$outer] = 'ellipse';
        yield from $this->slices($slices, $cx, $cy, $lx, $ly);
        $height = $top + $h + 2;

        return new MermaidScene(
            new GraphLayout(\max($width, $w), $height, $this->nodes, edges: [], clusters: []),
            \array_fill(0, \max(1, (int) \ceil($height / 2)), value: 0),
            $legend,
            $this->elements,
            $this->shapes,
            $this->palette->color('background'),
            $this->color('pieTitleTextColor'),
            $this->number('pieTitleTextSize') / 16,
        );
    }

    /** @param list<array{display: string, text: string, origin: int, ratio: float, highlight: bool}> $slices
     * @return \Generator<int, null, mixed, void>
     */
    private function slices(array $slices, float $cx, float $cy, float $lx, float $ly): \Generator
    {
        $angle = 0.0;
        $visibleTotal = \array_sum(\array_map(static fn (array $item): float => $item['ratio'] >= 0.01 ? $item['ratio'] : 0.0, $slices));
        foreach ($slices as $index => $item) {
            [$labelW, $labelH] = MermaidText::box($item['display']);
            $scale = $this->number('pieLegendTextSize') / 16;
            $labelW *= $scale;
            $labelH *= $scale;
            $color = $this->color('pie'.(1 + ($index % 12)));
            $id = \count($this->nodes);
            if ($item['ratio'] >= 0.01) {
                $end = $angle + ((2 * \M_PI * $item['ratio']) / $visibleTotal);
                $middle = (($angle + $end) / 2) - (\M_PI / 2);
                $sectorRadius = $item['highlight'] ? 25.2 : 24.0;
                $centerRadius = $sectorRadius * ((1 + ($this->configuration->pie->donutHole ?? 0.0)) / 2);
                $x = $cx + ($centerRadius * \cos($middle));
                $y = $cy + ($centerRadius * \sin($middle));
                $this->nodes[$id] = new GraphNode(
                    $id,
                    'pie'.$index,
                    $item['display'],
                    $x,
                    $y,
                    $labelW,
                    $labelH,
                    $this->color('pieStrokeColor'),
                    $color,
                    dashed: false,
                    background: true,
                    strokeWidth: $this->number('pieStrokeWidth') / 10,
                    drawLabel: '',
                    opacity: $item['highlight'] ? 1.0 : $this->number('pieOpacity'),
                );
                $this->shapes[$id] = 'pie:'.$angle.':'.$end.':'.($this->configuration->pie->donutHole ?? 0.0).':'.($cx - $x).':'.($cy - $y).':'.$sectorRadius;

                $radius = 24 * ($this->configuration->pie->textPosition ?? 0.75);
                $percentScale = $this->number('pieSectionTextSize') / 16;
                $this->text(
                    (string) \round(100 * $item['ratio']).'%',
                    $cx + ($radius * \cos($middle)),
                    $cy + ($radius * \sin($middle)),
                    6.0 * $percentScale,
                    2.0 * $percentScale,
                    $this->color('pieSectionTextColor'),
                    $percentScale,
                );
                $angle = $end;
            }
            $this->text($item['display'], $lx + 4 + ($labelW / 2), $ly + ($labelH / 2), $labelW, $labelH, $this->color('pieLegendTextColor'), $scale);
            $this->elements[$id] = $item['origin'];
            $swatch = \count($this->nodes);
            $this->nodes[$swatch] = new GraphNode($swatch, '@pie-color:'.$index, '', $lx + 1, $ly + ($labelH / 2), 2.0, 2.0, $color, $color, dashed: false);
            $ly += $labelH + 2;
            yield null;
        }
    }

    /** A negative length is an invalid declaration, and CSS clamps opacity to [0, 1]. */
    private function number(string $key): float
    {
        $number = $this->palette->number($key, self::SIZES[$key]);

        return match (true) {
            'pieOpacity' === $key => \max(0.0, \min(1.0, $number)),
            $number < 0 => self::SIZES[$key],
            default => $number,
        };
    }

    /** An unusable color is an invalid declaration: SVG paints its initial black. */
    private function color(string $variable): string
    {
        return $this->palette->color($variable) ?? '#000000';
    }

    private function text(string $label, float $x, float $y, float $width, float $height, string $color, float $scale): void
    {
        $id = \count($this->nodes);
        $this->nodes[$id] = new GraphNode($id, '@pie-text:'.$id, $label, $x, $y, $width, $height, color: null, fillColor: null, dashed: false, textColor: $color, fontScale: $scale);
        $this->shapes[$id] = 'text';
    }

    /** @return array{float, float, float, float, float, float} chart center, legend corner, scene extent */
    private function positions(float $top, float $width, float $height): array
    {
        $legend = $width + 8;
        $diameter = 48 + (2 * \max(4.0, ($this->number('pieOuterStrokeWidth') / 10) + 2, ($this->number('pieStrokeWidth') / 20) + 2));
        $offset = $diameter / 2;
        $wide = \max($diameter, $legend);
        $tall = \max($diameter, $height);
        $legendX = (($wide - $legend) / 2) + 2;
        $legendY = $top + (($tall - $height) / 2);

        return match ($this->configuration->pie->legendPosition ?? 'right') {
            'left' => [$legend + $offset, $top + ($tall / 2), 2.0, $legendY, $legend + $diameter, $tall],
            'top' => [$wide / 2, $top + $height + $offset, $legendX, $top, $wide, $height + $diameter],
            'bottom' => [$wide / 2, $top + $offset, $legendX, $top + $diameter, $wide, $height + $diameter],
            'center' => [$wide / 2, $top + ($tall / 2), $legendX, $legendY, $wide, $tall],
            default => [$offset, $top + ($tall / 2), $diameter + 2, $legendY, $diameter + $legend, $tall],
        };
    }
}
