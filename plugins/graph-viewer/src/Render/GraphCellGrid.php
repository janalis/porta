<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Render;

use Janalis\Viz\Layout\GraphNode;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Color;

/**
 * The mutable cell grid one {@see GraphRenderer::render} pass draws into:
 * (char, style-key) cells, world→cell transforms, and the final row assembly
 * that merges consecutive same-style cells into single SGR runs. Internal to
 * the renderer — constructed per frame, never shared.
 */
final class GraphCellGrid
{
    /** @var list<list<array{string, string}>> [row][col] => [char, styleKey] */
    private array $cells;

    /** @var array<string, \Closure(string): string> styleKey => run painter */
    private array $painters = [];

    private readonly float $scale;

    /** @var array{srcX: int, srcY: int, padCols: int, padHalfRows: int, outCols: int, outHalfRows: int} */
    private readonly array $rect;

    public function __construct(
        private readonly int $cols,
        private readonly int $rows,
        ZoomPanModel $view,
        private readonly ?Styles $styles,
    ) {
        $this->cells = \array_fill(0, \max(1, $rows), \array_fill(0, \max(1, $cols), [' ', '']));
        $this->scale = $view->effectiveScale($cols, 2 * $rows);
        $this->rect = $view->visibleRect($cols, 2 * $rows);
    }

    public function colOf(float $x): int
    {
        return $this->rect['padCols'] + (int) \round(($x - $this->rect['srcX']) * $this->scale);
    }

    public function rowOf(float $y): int
    {
        return \intdiv($this->rect['padHalfRows'] + (int) \round(($y - $this->rect['srcY']) * $this->scale), num2: 2);
    }

    /** Whether a world-rect intersects the visible world window (conservative). */
    public function intersects(float $x0, float $y0, float $x1, float $y1): bool
    {
        $viewX0 = $this->rect['srcX'] - ($this->rect['padCols'] / $this->scale);
        $viewY0 = $this->rect['srcY'] - ($this->rect['padHalfRows'] / $this->scale);
        $viewX1 = $viewX0 + ($this->cols / $this->scale);
        $viewY1 = $viewY0 + ((2 * $this->rows) / $this->scale);

        return $x1 >= $viewX0 && $x0 <= $viewX1 && $y1 >= $viewY0 && $y0 <= $viewY1;
    }

    /** @return array{int, int, int, int} the node's cell box [x0, y0, x1, y1] */
    public function nodeBox(GraphNode $node): array
    {
        return [
            $this->colOf($node->x - ($node->w / 2)),
            $this->rowOf($node->y - ($node->h / 2)),
            $this->colOf($node->x + ($node->w / 2)),
            $this->rowOf($node->y + ($node->h / 2)),
        ];
    }

    /**
     * The cells a node's own outline reaches in a picture Graphviz drew. That
     * picture maps column c to the document span starting at c's left edge,
     * not to the rounded centre the cell drawing boxes by ({@see nodeBox()}),
     * so a box rounded outward claims a cell of empty picture on every side.
     *
     * @return array{int, int, int, int} [x0, y0, x1, y1], inclusive
     */
    public function shownBox(GraphNode $node): array
    {
        [$left, $top, $right, $bottom] = $this->span($node);

        return [(int) \floor($left), (int) \floor($top / 2), (int) \ceil($right) - 1, (int) \ceil($bottom / 2) - 1];
    }

    /** How much of the cell at ($col, $row) a node's outline covers in that picture: 0 (none) to 1 (all). */
    public function coverage(GraphNode $node, int $col, int $row): float
    {
        [$left, $top, $right, $bottom] = $this->span($node);
        $width = \min($col + 1.0, $right) - \max((float) $col, $left);
        $height = \min((2 * $row) + 2.0, $bottom) - \max(2.0 * $row, $top);

        return $width > 0.0 && $height > 0.0 ? ($width * $height) / 2.0 : 0.0;
    }

    /** @return array{float, float, float, float} the node's outline in columns and half-rows, unrounded */
    private function span(GraphNode $node): array
    {
        $col = fn (float $x): float => $this->rect['padCols'] + (($x - $this->rect['srcX']) * $this->scale);
        $halfRow = fn (float $y): float => $this->rect['padHalfRows'] + (($y - $this->rect['srcY']) * $this->scale);

        return [$col($node->x - ($node->w / 2)), $halfRow($node->y - ($node->h / 2)), $col($node->x + ($node->w / 2)), $halfRow($node->y + ($node->h / 2))];
    }

    public function put(int $col, int $row, string $char, string $key): void
    {
        if ($col >= 0 && $col < $this->cols && $row >= 0 && $row < $this->rows) {
            $this->cells[$row][$col] = [$char, $key];
        }
    }

    /** Write a text run left-to-right, clipped to the grid (and to $maxCol when given). */
    public function text(int $col, int $row, string $text, string $key, ?int $maxCol): void
    {
        $characters = [];
        \preg_match_all('/\X/u', $text, $characters);
        $target = $col;
        foreach ($characters[0] ?? [] as $char) {
            $width = \max(1, AnsiUtils::visibleWidth($char));
            if (($target + $width) > $this->cols || (null !== $maxCol && ($target + $width - 1) > $maxCol)) {
                return;
            }
            $this->put($target, $row, $char, $key);
            for ($i = 1; $i < $width; ++$i) {
                $this->put($target + $i, $row, '', $key);
            }
            $target += $width;
        }
    }

    /**
     * Draw one polyline segment between two world points with slope-picked
     * glyphs, sampled densely enough that no cell gap opens. $dashPeriod 1
     * paints every cell (solid); 2 paints every other cell (dashed/dotted).
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    public function segment(array $from, array $to, string $key, int $dashPeriod): void
    {
        $x0 = $this->colOf($from[0]);
        $y0 = $this->rowOf($from[1]);
        $x1 = $this->colOf($to[0]);
        $y1 = $this->rowOf($to[1]);
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $steps = \max(\abs($dx), \abs($dy));
        $glyph = match (true) {
            \abs($dx) >= (2 * \abs($dy)) => '─',
            \abs($dy) >= (2 * \abs($dx)) => '│',
            $dx > 0 === $dy > 0 => '╲',
            default => '╱',
        };
        if (0 === $steps) {
            $this->putIfEmpty($x0, $y0, $dashPeriod > 1 ? '·' : $glyph, $key);

            return;
        }
        for ($i = 0; $i <= $steps; ++$i) {
            if (0 !== ($i % $dashPeriod)) {
                continue;
            }
            $col = $x0 + (int) \round(($dx * $i) / $steps);
            $row = $y0 + (int) \round(($dy * $i) / $steps);
            $this->putIfEmpty($col, $row, $glyph, $key);
        }
    }

    /** @return list<string> the assembled rows, consecutive same-style cells merged into one run */
    public function lines(): array
    {
        $lines = [];
        for ($row = 0; $row < $this->rows; ++$row) {
            $line = '';
            $runKey = null;
            $runText = '';
            for ($col = 0; $col < $this->cols; ++$col) {
                [$char, $key] = $this->cells[$row][$col];
                if ($key !== $runKey && null !== $runKey) {
                    $line .= $this->paint($runKey, $runText);
                    $runText = '';
                }
                $runKey = $key;
                $runText .= $char;
            }
            if (null !== $runKey) {
                $line .= $this->paint($runKey, $runText);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** A style key for a DOT truecolor (when the palette has color) or the fallback role. */
    public function colorKey(?string $hex, Role $fallback): string
    {
        // Cell outlines remain readable when native graphics omit their stroke.
        $hex = 'none' === $hex ? null : $hex;
        if (null !== $hex && null !== $this->styles && $this->styles->hasColor()) {
            // A cell has no alpha: a theme's `#rrggbbaa` draws its opaque color.
            $prefix = Color::hex(\substr($hex, offset: 0, length: 7))->toForegroundCode();
            $this->painters[$prefix] ??= static fn (string $text): string => $prefix.$text.Sgr::RESET;

            return $prefix;
        }

        return $this->roleKey($fallback);
    }

    public function roleKey(Role $role): string
    {
        if (null === $this->styles) {
            return '';
        }
        $key = 'role:'.$role->name;
        $style = $this->styles->of($role);
        $this->painters[$key] ??= $style->apply(...);

        return $key;
    }

    private function putIfEmpty(int $col, int $row, string $char, string $key): void
    {
        if ($col >= 0 && $col < $this->cols && $row >= 0 && $row < $this->rows && ' ' === $this->cells[$row][$col][0]) {
            $this->cells[$row][$col] = [$char, $key];
        }
    }

    private function paint(string $key, string $text): string
    {
        if ('' === $key || ('' === \trim($text) && !\str_starts_with($key, 'role:SelectedRow'))) {
            return $text; // plain cells and blank runs need no escapes (except a selected node's inverse interior)
        }
        $painter = $this->painters[$key] ?? null;

        return null !== $painter ? $painter($text) : $text;
    }
}
