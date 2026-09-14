<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Overlay\State\FindInFilesModal;
use Porta\Ui\CaretVisibility;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Lay a find-in-files modal out and read its composed rows.
 *
 * The modal is span-delivered onto whatever pane is under it, so a test that
 * wants to READ one has to place the box itself — over the prod
 * {@see OverlayCompositor::into} splicer, never a hand-rolled one, or the test
 * proves its own arithmetic instead of the compositor's.
 *
 * Extracted when {@see \Porta\Tests\Unit\Overlay\State\FindInFilesModalTest}
 * outgrew mago's method threshold and its geometry cases moved to a class of
 * their own: the two need the same four helpers, and the duplication gate is
 * tree-wide with no allowlist.
 */
trait ComposesFindModal
{
    private function modal(): FindInFilesModal
    {
        return new FindInFilesModal(static fn (): null => null);
    }

    /**
     * The modal laid out over a blank screen of $columns × $rows.
     *
     * @param positive-int $columns
     * @param positive-int $rows
     *
     * @return list<string>
     */
    private function compose(FindInFilesModal $modal, int $columns = 100, int $rows = 24): array
    {
        $lines = \array_fill(0, count: $rows, value: \str_repeat(' ', times: $columns));
        $placed = $modal->layout([0, 0, $rows, $columns], CaretVisibility::Shown);
        if (null === $placed) {
            return $lines;
        }
        $box = $modal->lastBox();
        \assert(null !== $box, description: 'the modal laid out a box');

        return $this->placeAt($lines, $placed['rows'], $placed['top'], $placed['col'], $box->width, $columns);
    }

    /**
     * Splice the box rows into the screen lines — the test's own composition
     * scaffold over the prod {@see OverlayCompositor::into} splicer.
     *
     * @param list<string> $lines
     * @param list<string> $box
     *
     * @return list<string>
     */
    private function placeAt(array $lines, array $box, int $startRow, int $col, int $width, int $columns): array
    {
        foreach ($box as $i => $boxLine) {
            $row = $startRow + $i;
            if ($row >= \count($lines)) {
                break;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $boxLine);
        }

        return $lines;
    }

    /** The composed screen as one plain string. */
    private function screen(FindInFilesModal $modal): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->compose($modal)));
    }
}
