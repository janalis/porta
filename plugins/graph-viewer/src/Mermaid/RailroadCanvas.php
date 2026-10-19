<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Request-local rail assembly separates decorative tracks from authored hit targets. */
final class RailroadCanvas
{
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var list<GraphEdge> */
    private array $edges = [];
    /** @var array<int, string> */
    private array $shapes = [];
    /** @var array<int, int> */
    private array $elements = [];
    /** @var list<array{text: string, origin: int}> */
    private array $legend = [];
    private float $width = 8.0;
    private float $height = 8.0;

    public function __construct(
        private readonly MermaidOptions $options = new MermaidOptions(),
    ) {}

    /** @return \Generator<int, null, mixed, void> */
    public function rule(string $name, MermaidStatement $statement, RailroadMeasure $box, float $top): \Generator
    {
        $label = MermaidText::decode($name);
        [$width, $height] = MermaidText::box($label);
        $this->node($label, $name, 4, $top, $width, $height, 'text', $statement);
        $y = $top + $height + 2;
        $baseline = $y + $box->baseline;
        $this->marker(4, $baseline);
        $this->line([[4.0, $baseline], [8.0, $baseline]]);
        yield from $this->expression($box, 8, $y);
        $end = $box->width + 12;
        $this->line([[$box->width + 8, $baseline], [$end, $baseline]]);
        $this->marker($end, $baseline);
        $this->height = $y + $box->height + 6;
        $this->width = \max($this->width, $end + 4, $width + 8);
        $this->legend[] = ['text' => $label, 'origin' => $statement->line];
    }

    public function nextTop(): float
    {
        return $this->height;
    }

    /** @return \Generator<int, null, mixed, void> */
    private function expression(RailroadMeasure $box, float $x, float $y): \Generator
    {
        $type = $box->expression->type;
        if ([] === $box->children) {
            $shape = match ($type) {
                'terminal' => 'rounded',
                'nonterminal' => 'rect',
                default => 'stadium',
            };
            $this->node($box->label, $box->expression->label, $x, $y, $box->width, $box->height, $shape, $box->expression->statement);
            $this->legend[] = ['text' => $box->label, 'origin' => $box->expression->statement->line];
            yield null;

            return;
        }
        yield from match ($type) {
            'sequence' => $this->sequence($box, $x, $y),
            'choice' => $this->choice($box, $x, $y),
            default => $this->loop($box, $x, $y),
        };
    }

    /** @return \Generator<int, null, mixed, void> */
    private function sequence(RailroadMeasure $box, float $x, float $y): \Generator
    {
        foreach ($box->children as $index => $child) {
            if ($index > 0) {
                $this->line([[$x - 4, $y + $box->baseline], [$x, $y + $box->baseline]]);
            }
            yield from $this->expression($child, $x, $y + $box->baseline - $child->baseline);
            $x += $child->width + 4;
        }
    }

    /** @return \Generator<int, null, mixed, void> */
    private function choice(RailroadMeasure $box, float $x, float $y): \Generator
    {
        $top = $y;
        $baseline = $y + $box->baseline;
        foreach ($box->children as $child) {
            $row = $top + $child->baseline;
            $left = $x + (($box->width - $child->width) / 2);
            $this->line([[$x, $baseline], [$x + 2, $baseline], [$x + 4, $row], [$left, $row]]);
            $this->line([[$left + $child->width, $row], [$x + $box->width - 4, $row], [$x + $box->width - 2, $baseline], [$x + $box->width, $baseline]]);
            yield from $this->expression($child, $left, $top);
            $top += $child->height + 4;
        }
    }

    /** @return \Generator<int, null, mixed, void> */
    private function loop(RailroadMeasure $box, float $x, float $y): \Generator
    {
        $expression = $box->expression;
        $child = $box->children[0];
        $left = $x + (($box->width - $child->width) / 2);
        $baseline = $y + $box->baseline;
        $this->line([[$x, $baseline], [$left, $baseline]]);
        $this->line([[$left + $child->width, $baseline], [$x + $box->width, $baseline]]);
        yield from $this->expression($child, $left, $baseline - $child->baseline);
        if ('optional' === $expression->type || 0 === $expression->min) {
            $upper = $y + 1;
            $this->line([[$x, $baseline], [$x + 2, $baseline], [$x + 4, $upper], [$x + $box->width - 4, $upper], [$x + $box->width - 2, $baseline], [$x + $box->width, $baseline]]);
        }
        if ('repetition' === $expression->type) {
            $lower = $y + $box->height - 2;
            $this->line([[$left + $child->width, $baseline], [$x + $box->width - 2, $baseline], [$x + $box->width - 4, $lower], [$x + 4, $lower], [$x + 2, $baseline], [$left, $baseline]]);
            // The return rail runs right-to-left; a chevron distinguishes it from an optional bypass.
            $middle = $x + ($box->width / 2);
            $this->line([[$middle + 1, $lower - 0.7], [$middle, $lower], [$middle + 1, $lower + 0.7]]);
            $count = RailroadMeasure::countLabel($expression);
            if ('' !== $count) {
                [$width, $height] = MermaidText::box($count);
                $this->node($count, '@repeat', $middle - ($width / 2), $lower - 1, $width, $height, 'text', null);
            }
        }
        yield null;
    }

    private function node(string $label, string $name, float $x, float $y, float $width, float $height, string $shape, ?MermaidStatement $statement): void
    {
        $id = \count($this->nodes);
        // Pinned `buildThemeDefaults` over the default theme spread under this one: terminals in
        // the secondary colors, non-terminals in the node colors, special sequences in the tertiary
        // ones; rule names in the title color.
        [$stroke, $fill, $text] = match ($shape) {
            'rounded' => [
                $this->color('terminalStroke', '@^secondaryBorderColor|^lineColor'),
                $this->color('terminalFill', '@^secondBkg|^secondaryColor'),
                $this->color('terminalTextColor', '@^secondaryTextColor|^textColor'),
            ],
            'rect' => [
                $this->color('nonTerminalStroke', '@^primaryBorderColor|^lineColor'),
                $this->color('nonTerminalFill', '@^mainBkg|^background'),
                $this->color('nonTerminalTextColor', '@^primaryTextColor|^textColor'),
            ],
            'stadium' => [
                $this->color('specialStroke', '@^tertiaryBorderColor|^secondaryBorderColor'),
                $this->color('specialFill', '@^tertiaryColor|^secondaryColor'),
                $this->color('nonTerminalTextColor', '@^primaryTextColor|^textColor'),
            ],
            default => ['none', 'none', '@repeat' === $name ? '@^textColor' : $this->color('ruleNameColor', '@^titleColor|^textColor')],
        };
        $this->nodes[$id] = new GraphNode($id, $name, $label, $x + ($width / 2), $y + ($height / 2), $width, $height, $stroke, $fill, dashed: false, textColor: $text);
        $this->shapes[$id] = $shape;
        if (null !== $statement) {
            $this->elements[$id] = $statement->line;
        }
    }

    private function marker(float $x, float $y): void
    {
        $id = \count($this->nodes);
        $this->nodes[$id] = new GraphNode($id, '@marker', '', $x, $y, 1.0, 1.0, $this->color('markerFill', '@^lineColor'), $this->color('markerFill', '@^lineColor'), dashed: false, background: true);
        $this->shapes[$id] = 'circle';
    }

    /** @param non-empty-list<array{float, float}> $points */
    private function line(array $points): void
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);
        $this->edges[] = new GraphEdge(-1, -1, $points, null, \min($xs), \min($ys), \max($xs), \max($ys), $this->color('lineColor', '@^lineColor'), null, null, dashed: false, decoration: true);
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function scene(int $origin): \Generator
    {
        $origins = MermaidOrigins::rows($this->height, $origin);
        foreach ($this->elements as $id => $line) {
            $node = $this->nodes[$id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $line;
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($this->width, $this->height, $this->nodes, $this->edges, []), $origins, $this->legend, $this->elements, $this->shapes);
    }

    /** A `railroad` option color, as pinned `sanitizeColorValue` keeps it, else the theme's. */
    private function color(string $key, string $theme): string
    {
        return MermaidColor::css($this->options->text('railroad', $key)) ?? $theme;
    }
}
