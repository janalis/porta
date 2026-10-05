<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Composites styled segments onto a widget's already-rendered rows: the
 * application-menu dropdown floating over the tab bar / editor instead of the
 * menu bar pushing the layout down, the other floating surfaces, and the
 * frame passes' cells ({@see \Porta\Overlay\Presenter\FrameJoins}). Spans are
 * in the widget's local coordinates (the coordinator translates the absolute
 * dropdown position to each widget via its rect); rows outside the widget are
 * ignored.
 *
 * Spans that run on from one another along a row are composited as ONE: each
 * splice re-slices the whole row, and the slicer replays every escape it
 * skips, so a row grows by its own prefix per splice. A frame pass that hands
 * a cell per span (a head bar reaching into a bar's half, 175 cells wide) took
 * a 7.8 KB row to 579 KB and 1.5 s a frame; merged, it is one splice.
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
        foreach (self::runs($spans) as $span) {
            if ($span['row'] < 0 || $span['row'] >= \count($lines)) {
                continue;
            }
            // Clip to the widget's LIVE width: a span sliced against a
            // last-frame rect can outlive a relayout (tab bar moving to a
            // side, a pane resize) for one frame, and compositing it verbatim
            // would blow the line-width invariant the renderer enforces.
            $width = \min($span['width'], $columns - $span['col']);
            if ($width <= 0) {
                continue;
            }
            $segment = $width < $span['width'] ? AnsiUtils::sliceByColumn($span['segment'], startCol: 0, length: $width) : $span['segment'];

            $lines[$span['row']] = OverlayCompositor::into($lines[$span['row']], $span['col'], $width, $columns, $segment);
        }

        return $lines;
    }

    /**
     * $spans with each one that starts where the one before it ends, on the
     * same row, folded into it — in the order given, so where spans overlap
     * the later still wins. The segments are joined by a reset, as a splice
     * of each would have put one between them.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    private static function runs(array $spans): array
    {
        /** @var list<array{row: int, col: int, width: int, segment: string}> $runs */
        $runs = [];
        foreach ($spans as $span) {
            $last = \array_pop($runs);
            if (null !== $last && $last['row'] === $span['row'] && ($last['col'] + $last['width']) === $span['col']) {
                $runs[] = ['row' => $last['row'], 'col' => $last['col'], 'width' => $last['width'] + $span['width'], 'segment' => $last['segment'].Sgr::RESET.$span['segment']];
                continue;
            }
            if (null !== $last) {
                $runs[] = $last;
            }
            $runs[] = $span;
        }

        return $runs;
    }
}
