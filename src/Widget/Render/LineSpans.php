<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

/**
 * Pure builder for one content line's decoration spans, gathered in layering
 * order: selection, search matches, identifier occurrences, matching
 * brackets, diagnostic underlines, then caret cells. The primary selection
 * and each extra caret's selection flow through the same range→span code, so
 * the two can never drift apart. `padTo` reports how far the line must be
 * padded so an end-of-line caret has a cell to invert.
 */
final class LineSpans
{
    /**
     * @param list<array{start: int, end: int, current: bool}>                     $matches search matches on this line (byte cols)
     * @param list<array{severity: int, message: string, col: int, endCol?: int}>  $diags   diagnostics on this line (utf16 cols)
     *
     * @return array{spans: list<array{0: int, 1: int, 2: string, 3: string, 4: ?string}>, padTo: int}
     */
    public static function forLine(
        Document $doc,
        int $lineIndex,
        EditorDecorations $decorations,
        array $matches,
        array $diags,
        Styles $styles,
    ): array {
        $selection = $decorations->multiActive ? null : $decorations->selection;
        $occurrences = $decorations->occurrences;
        $brackets = $decorations->brackets;
        $carets = $decorations->carets;
        $spans = [];
        $padTo = 0;

        if (null !== $selection && $lineIndex >= $selection['startLine'] && $lineIndex <= $selection['endLine']) {
            $spans[] = self::selectionSpan($doc, $lineIndex, $selection['startLine'], $selection['startCol'], $selection['endLine'], $selection['endCol'], $styles);
        }
        foreach ($matches as $match) {
            // The current match is additionally bold, so it stands out from the
            // other matches without relying on the background color. The close is
            // derived from the style so it cancels reverse/underline (Monochrome)
            // as it cancels the background (colored themes).
            $matchStyle = $match['current'] ? $styles->of(Role::SearchCurrentBg)->withBold() : $styles->of(Role::SearchBg);
            $open = $matchStyle->getAnsiRestore();
            $spans[] = [$doc->displayColAt($lineIndex, $match['start']), $doc->displayColAt($lineIndex, $match['end']), $open, Sgr::resetFor($matchStyle), $open];
        }
        foreach ($occurrences as [$oLine, $oStart, $oEnd]) {
            if ($oLine !== $lineIndex) {
                continue;
            }

            $occurrenceStyle = $styles->of(Role::OccurrenceBg);
            $occurrenceBg = $occurrenceStyle->getAnsiRestore();
            $spans[] = [$oStart, $oEnd, $occurrenceBg, Sgr::resetFor($occurrenceStyle), $occurrenceBg];
        }
        if (null !== $brackets) {
            foreach ($brackets as [$bLine, $bDispCol]) {
                if ($bLine !== $lineIndex) {
                    continue;
                }

                $spans[] = [$bDispCol, $bDispCol + 1, LineDecorator::BRACKET_SGR, "\x1b[22m\x1b[27m", null];
            }
        }
        foreach ($diags as $diag) {
            $startDisp = EditorRenderer::utf16ToDisplayCol($doc, $lineIndex, $diag['col']);
            $endUtf16 = $diag['endCol'] ?? ($diag['col'] + 1);
            $endDisp = \PHP_INT_MAX === $endUtf16 ? \PHP_INT_MAX : max($startDisp + 1, EditorRenderer::utf16ToDisplayCol($doc, $lineIndex, $endUtf16));
            // Curly, severity-colored underline (degrades to a plain underline).
            $spans[] = [$startDisp, $endDisp, DiagnosticStyle::underlineOpen($diag['severity'], $styles), DiagnosticStyle::underlineClose(), null];
        }
        foreach ($carets as $caret) {
            // Each caret's selection (possibly spanning lines), drawn before
            // the caret cell so the reverse-video cell layers on top.
            [$sl, $startCol, $el, $endCol] = $caret->orderedRange();
            if ($caret->hasSelection() && $lineIndex >= $sl && $lineIndex <= $el) {
                $spans[] = self::selectionSpan($doc, $lineIndex, $sl, $startCol, $el, $endCol, $styles);
            }
            // The caret cell (reverse video), for the primary and extras alike.
            if ($caret->line === $lineIndex) {
                $col = $doc->displayColAt($lineIndex, $caret->col);
                $spans[] = [$col, $col + 1, Sgr::REVERSE, Sgr::NO_REVERSE, null];
                // An end-of-line caret needs a padded cell to invert.
                $padTo = max($padTo, $col + 1);
            }
        }

        return ['spans' => $spans, 'padTo' => $padTo];
    }

    /**
     * Keep only the spans that intersect the visible window [$winStart,
     * $winEnd), clamped to it — the decorated line is sliced to that window
     * anyway (and every row closes with a full SGR reset), so span work
     * outside it is pure waste, and bounding the span count to the viewport
     * is what keeps a prose line with dozens of occurrence spans linear.
     * Also collapses the PHP_INT_MAX "to end of line" diagnostic endCol.
     *
     * @param list<array{0: int, 1: int, 2: string, 3: string, 4: ?string}> $spans
     *
     * @return list<array{0: int, 1: int, 2: string, 3: string, 4: ?string}>
     */
    public static function clip(array $spans, int $winStart, int $winEnd): array
    {
        $clipped = [];
        foreach ($spans as $span) {
            if ($span[1] <= $winStart || $span[0] >= $winEnd) {
                continue;
            }
            $span[0] = max($span[0], $winStart);
            $span[1] = min($span[1], $winEnd);
            $clipped[] = $span;
        }

        return $clipped;
    }

    /**
     * The display-column selection span an ordered byte range produces on one
     * line: from the range start on its first line, to the range end on its
     * last, spanning the full line in between.
     *
     * @return array{0: int, 1: int, 2: string, 3: string, 4: ?string}
     */
    private static function selectionSpan(Document $doc, int $lineIndex, int $startLine, int $startCol, int $endLine, int $endCol, Styles $styles): array
    {
        $startDisp = $lineIndex === $startLine ? $doc->displayColAt($lineIndex, $startCol) : 0;
        $endDisp = $lineIndex === $endLine ? $doc->displayColAt($lineIndex, $endCol) : $doc->lineDisplayWidth($lineIndex);
        $selectionStyle = $styles->of(Role::SelectionBg);
        $selectionBg = $selectionStyle->getAnsiRestore();

        return [$startDisp, $endDisp, $selectionBg, Sgr::resetFor($selectionStyle), $selectionBg];
    }
}
