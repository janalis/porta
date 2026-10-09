<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

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
        $top = \PHP_INT_MAX;
        $left = \PHP_INT_MAX;
        $bottom = 0;
        $right = 0;
        foreach ($panes as $pane) {
            $rect = self::liveRect($pane, $renderer);
            if (null === $rect) {
                continue;
            }
            [$rectTop, $rectLeft, $rectBottom, $rectRight] = $rect;
            $top = \min($top, $rectTop);
            $left = \min($left, $rectLeft);
            $bottom = \max($bottom, $rectBottom);
            $right = \max($right, $rectRight);
        }

        return $bottom > $top && $right > $left ? [$top, $left, $bottom, $right] : null;
    }

    /**
     * Where $pane sits on the screen the last frame drew, clipped to it — or
     * null when it has no place there.
     *
     * The renderer keeps a widget's rect for as long as the widget lives,
     * drawn or not, so a rect can describe an older, larger screen: a pane
     * the layout detached (the tree on a narrow screen) and one that drew no
     * rows (an edge rule on a compact one) both still report where they sat
     * before. Folding those in kept a shrunk terminal's bounds at the old
     * size. A detached pane counts for nothing; any other is clipped to its
     * root's rect, which every frame rewrites to the screen it drew.
     *
     * @return array{int, int, int, int}|null [top, left, bottom, right)
     */
    private static function liveRect(AbstractWidget $pane, Renderer $renderer): ?array
    {
        $root = $pane;
        while (null !== ($parent = $root->getParent())) {
            $root = $parent;
        }
        $detached = $root === $pane; // it is its own root
        $rect = $detached ? null : $renderer->getWidgetRect($pane);
        $screen = $detached ? null : $renderer->getWidgetRect($root);
        if (null === $rect || null === $screen) {
            return null;
        }
        // The root always sits at the origin, so only the far edges can hang
        // off it.
        $bottom = \min($rect->row + $rect->rows, $screen->rows);
        $right = \min($rect->col + $rect->columns, $screen->columns);

        return $bottom > $rect->row && $right > $rect->col ? [$rect->row, $rect->col, $bottom, $right] : null;
    }
}
