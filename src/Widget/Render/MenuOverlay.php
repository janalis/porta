<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Symfony\Component\Tui\Ansi\AnsiUtils;

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
            if (!($span['row'] >= 0 && $span['row'] < \count($lines))) {
                continue;
            }
            // Clip to the widget's LIVE width: a span sliced against a
            // last-frame rect can outlive a relayout (tab bar moving to a
            // side, a pane resize) for one frame, and compositing it verbatim
            // would blow the line-width invariant the renderer enforces.
            $width = min($span['width'], $columns - $span['col']);
            if ($width <= 0) {
                continue;
            }
            $segment = $width < $span['width'] ? AnsiUtils::sliceByColumn($span['segment'], startCol: 0, length: $width) : $span['segment'];

            $lines[$span['row']] = OverlayCompositor::into($lines[$span['row']], $span['col'], $width, $columns, $segment);
        }

        return $lines;
    }
}
