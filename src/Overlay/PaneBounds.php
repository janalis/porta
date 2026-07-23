<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The union of a pane set's last-rendered rects — the paintable screen area a
 * floating surface centers and clamps against. One home for the fold every
 * floating presenter (Welcome, Find in Files, the menu overlays) used to
 * carry privately.
 */
final class PaneBounds
{
    /**
     * @param list<AbstractWidget&MenuOverlayHost> $panes
     *
     * @return array{int, int, int, int}|null [top, left, bottom, right), or null before the first frame
     */
    public static function union(array $panes, Renderer $renderer): ?array
    {
        $top = PHP_INT_MAX;
        $left = PHP_INT_MAX;
        $bottom = 0;
        $right = 0;
        foreach ($panes as $pane) {
            $rect = $renderer->getWidgetRect($pane);
            if (null === $rect) {
                continue;
            }
            $top = min($top, $rect->row);
            $left = min($left, $rect->col);
            $bottom = max($bottom, $rect->row + $rect->rows);
            $right = max($right, $rect->col + $rect->columns);
        }

        return $bottom > $top && $right > $left ? [$top, $left, $bottom, $right] : null;
    }
}
