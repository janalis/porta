<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Graph\GraphLayout;
use Porta\Image\ZoomPanModel;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Frames the selected node on top of the rendered bitmap. The picture comes from
 * dot and knows nothing about selection, but the layout does — so the cue is
 * drawn from node geometry through {@see GraphCellGrid}'s transform, the same one
 * {@see GraphRenderer::nodeAt()} inverts for clicks. The two therefore agree by
 * construction: whatever a click selects is what gets framed.
 *
 * Stateless, like the rest of this tier: it takes rendered lines and returns
 * rendered lines, splicing by display column the way both viewers' focus frames
 * do (so the image underneath keeps its geometry and every line keeps its width).
 */
final class GraphSelectionOverlay
{
    /**
     * @param list<string> $lines the rasterized bitmap
     *
     * @return list<string> the same lines, with the selected node framed
     */
    public static function apply(array $lines, GraphLayout $graph, ZoomPanModel $view, ?int $selected, int $cols, int $rows, Styles $styles): array
    {
        $node = null !== $selected ? $graph->nodes[$selected] ?? null : null;
        if (null === $node) {
            return $lines;
        }

        [$x0, $y0, $x1, $y1] = new GraphCellGrid($cols, $rows, $view, null)->nodeBox($node);
        $x0 = max(0, $x0);
        $y0 = max(0, $y0);
        $x1 = min($cols - 1, $x1);
        $y1 = min($rows - 1, $y1);
        if ($x1 < $x0 || $y1 < $y0) {
            return $lines; // the node is scrolled out of the pane
        }

        $accent = $styles->of(Role::TabAccent);
        $span = max(1, $x1 - $x0 + 1); // the guard above proves this, max() proves it to the analyzer
        $rule = $accent->apply(str_repeat(Glyphs::FRAME_HORIZONTAL, $span));
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        for ($row = $y0; $row <= $y1; ++$row) {
            $lines[$row] =
                $row === $y0 || $row === $y1
                    ? self::splice($lines[$row], $x0, $span, $rule, $cols)
                    : self::splice(self::splice($lines[$row], $x0, 1, $rail, $cols), $x1, 1, $rail, $cols);
        }

        return $lines;
    }

    /** $line with $width columns at $col replaced by $patch, keeping the line's width. */
    private static function splice(string $line, int $col, int $width, string $patch, int $cols): string
    {
        return AnsiUtils::sliceByColumn($line, 0, $col).$patch.AnsiUtils::sliceByColumn($line, $col + $width, $cols - $col - $width);
    }
}
