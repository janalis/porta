<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Widget;

/**
 * The diff pane's column arithmetic, spelled ONCE: where the split boundary
 * sits, where a row's text begins per column and how wide it runs. The mouse path
 * ({@see DiffViewWidget}) and the render path
 * ({@see \Porta\DiffPlugin\Widget\DiffViewRenderer}) both read these — they used
 * to carry private copies, and a drift between them would make a drag
 * selection highlight different columns than it selected.
 */
final class DiffGeometry
{
    /** The split view's divider column (the left half's exclusive end). */
    public static function splitBoundary(int $width): int
    {
        return \intdiv($width - 1, num2: 2);
    }

    /**
     * The widget column where a row's line text starts: after the unified
     * gutter (null), the left half's gutter, or the divider plus the right
     * half's gutter.
     */
    public static function textStart(?DiffColumn $column, int $width): int
    {
        return match ($column) {
            null => 12,
            DiffColumn::Old => 6,
            DiffColumn::New => self::splitBoundary($width) + 7,
        };
    }

    /**
     * The columns of line text a row shows per column, past the gutter and the
     * ± marker: the width a soft-wrapped line breaks at. Below one, a pane too
     * narrow to seat any text.
     */
    public static function bodyWidth(?DiffColumn $column, int $width): int
    {
        return match ($column) {
            null => $width - 12,
            DiffColumn::Old => self::splitBoundary($width) - 6,
            DiffColumn::New => $width - self::textStart(DiffColumn::New, $width),
        };
    }
}
