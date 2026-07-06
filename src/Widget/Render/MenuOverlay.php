<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Composites the application-menu dropdown's styled segments onto a widget's
 * already-rendered rows, so the dropdown floats over the tab bar / editor
 * instead of the menu bar pushing the layout down. Spans are in the widget's
 * local coordinates (the coordinator translates the absolute dropdown position
 * to each widget via its rect); rows outside the widget are ignored.
 */
final class MenuOverlay
{
    /**
     * @param list<string>                                                    $lines
     * @param list<array{row: int, col: int, width: int, segment: string}>    $spans
     *
     * @return list<string>
     */
    public static function apply(array $lines, array $spans, int $columns): array
    {
        foreach ($spans as $span) {
            if ($span['row'] >= 0 && $span['row'] < \count($lines)) {
                $lines[$span['row']] = OverlayCompositor::into($lines[$span['row']], $span['col'], $span['width'], $columns, $span['segment']);
            }
        }

        return $lines;
    }
}
