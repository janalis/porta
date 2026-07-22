<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * Translates a floating panel's absolute rows to widget-local overlay spans:
 * keeps only the rows that fall inside the widget and clips each segment to
 * the widget's columns. Clipping matters for panels anchored over one pane
 * while spilling toward another; without it a segment wider than the host
 * widget would blow out its line width and wreck the layout. Panels that do
 * not overlap the widget at all yield no spans.
 *
 * Extracted from {@see MenuOverlayController} unchanged so the Welcome modal
 * shares the one implementation of this math.
 */
final class SpanSlicer
{
    /**
     * @param list<string> $segments panel rows, each exactly $panelWidth visible columns
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function localSpans(array $segments, WidgetRect $rect, int $top, int $anchor, int $panelWidth): array
    {
        // Horizontal overlap of the panel [anchor, anchor+panelWidth) with the rect.
        $start = max($anchor, $rect->col);
        $end = min($anchor + $panelWidth, $rect->col + $rect->columns);
        if ($start >= $end) {
            return []; // the panel does not touch this widget
        }
        $clipOffset = $start - $anchor;
        $clipWidth = $end - $start;
        $localCol = $start - $rect->col;
        $clipped = $clipOffset > 0 || $clipWidth < $panelWidth;

        $spans = [];
        foreach ($segments as $i => $segment) {
            $localRow = $top + $i - $rect->row;
            if ($localRow >= 0 && $localRow < $rect->rows) {
                $spans[] = [
                    'row' => $localRow,
                    'col' => $localCol,
                    'width' => $clipWidth,
                    'segment' => $clipped
                        ? AnsiUtils::sliceByColumn($segment, $clipOffset, $clipWidth)
                        : $segment,
                ];
            }
        }

        return $spans;
    }
}
