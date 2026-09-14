<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Render;

use Porta\GraphViewer\Graph\GraphNode;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

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

    public function put(int $col, int $row, string $char, string $key): void
    {
        if ($col >= 0 && $col < $this->cols && $row >= 0 && $row < $this->rows) {
            $this->cells[$row][$col] = [$char, $key];
        }
    }

    /** Write a text run left-to-right, clipped to the grid (and to $maxCol when given). */
    public function text(int $col, int $row, string $text, string $key, ?int $maxCol): void
    {
        $length = \mb_strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $target = $col + $i;
            if (null !== $maxCol && $target > $maxCol) {
                return;
            }
            $this->put($target, $row, \mb_substr($text, $i, length: 1), $key);
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
        if (null !== $hex && null !== $this->styles && $this->styles->hasColor()) {
            $r = (int) \hexdec(\substr($hex, offset: 1, length: 2));
            $g = (int) \hexdec(\substr($hex, offset: 3, length: 2));
            $b = (int) \hexdec(\substr($hex, offset: 5, length: 2));
            $prefix = "\x1b[38;2;{$r};{$g};{$b}m";
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
        if ('' === $key || '' === \trim($text) && !\str_starts_with($key, 'role:SelectedRow')) {
            return $text; // plain cells and blank runs need no escapes (except a selected node's inverse interior)
        }
        $painter = $this->painters[$key] ?? null;

        return null !== $painter ? $painter($text) : $text;
    }
}
