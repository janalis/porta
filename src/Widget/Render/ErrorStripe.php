<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DiagnosticStyle;
use Porta\Support\IntMath;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

/**
 * The editor's right-margin error stripe: one self-contained 1-column cell
 * per screen row, showing the worst-severity diagnostic mark landing on that
 * proportional slice of the file, over a faint viewport thumb (only when the
 * file is taller than the viewport). Pure and static — it takes what it
 * paints plus the palette, and reads nothing else.
 */
final class ErrorStripe
{
    /**
     * @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $diagnosticsByLine doc line => diagnostics
     *
     * @return list<string> exactly $rows cells
     */
    public static function cells(array $diagnosticsByLine, int $rows, int $lineCount, int $vScroll, int $lastVisible, Styles $styles): array
    {
        // Worst severity landing on each stripe row (0 = none).
        $sev = array_fill(0, max(0, $rows), value: 0);
        foreach ($diagnosticsByLine as $line => $list) {
            if ([] === $list) {
                continue;
            }
            $r = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($line * $rows, $lineCount), 0, $rows - 1);
            $worst = DiagnosticStyle::worst($list);
            $sev[$r] = 0 === $sev[$r] ? $worst : min($sev[$r], $worst);
        }

        // Viewport thumb: the stripe rows covering the visible doc-line range,
        // shown only when the file actually scrolls.
        $showThumb = $lineCount > $rows;
        $thumbTop = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($vScroll * $rows, $lineCount), 0, $rows - 1);
        $thumbBottom = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($lastVisible * $rows, $lineCount), 0, $rows - 1);

        $cells = [];
        for ($r = 0; $r < $rows; ++$r) {
            // The leading reset shields the cell from whatever state the row
            // content left open; the styles then close themselves.
            $thumb = $showThumb && $r >= $thumbTop && $r <= $thumbBottom;
            $cells[] = match (true) {
                0 !== $sev[$r] && $thumb => Sgr::RESET.$styles->merge(Role::RowHighlightBg, DiagnosticStyle::role($sev[$r]))->apply(DiagnosticStyle::mark($sev[$r])),
                0 !== $sev[$r] => Sgr::RESET.$styles->of(DiagnosticStyle::role($sev[$r]))->apply(DiagnosticStyle::mark($sev[$r])),
                $thumb => Sgr::RESET.$styles->of(Role::RowHighlightBg)->apply(' '),
                default => Sgr::RESET.' ',
            };
        }

        return $cells;
    }
}
