<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\Caret;
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
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $selection   primary selection (null with multiple carets:
     *                                                                                          every caret then draws uniformly via $carets)
     * @param list<Caret>    $carets      all carets incl. the primary (multi-caret mode)
     * @param list<array{start: int, end: int, current: bool}>                     $matches     search matches on this line (byte cols)
     * @param list<array{0: int, 1: int, 2: int}>                                  $occurrences [line, startDisp, endDisp] identifier occurrences
     * @param list<array{0: int, 1: int}>|null                                     $brackets    [line, displayCol] matching-bracket cells
     * @param list<array{severity: int, message: string, col: int, endCol?: int}>  $diags       diagnostics on this line (utf16 cols)
     *
     * @return array{spans: list<array{0: int, 1: int, 2: string, 3: string, 4: ?string}>, padTo: int}
     */
    public static function forLine(
        Document $doc,
        int $lineIndex,
        ?array $selection,
        array $carets,
        array $matches,
        array $occurrences,
        ?array $brackets,
        array $diags,
        Styles $styles,
    ): array {
        $spans = [];
        $padTo = 0;

        if (null !== $selection && $lineIndex >= $selection['startLine'] && $lineIndex <= $selection['endLine']) {
            $spans[] = self::selectionSpan($doc, $lineIndex, $selection['startLine'], $selection['startCol'], $selection['endLine'], $selection['endCol'], $styles);
        }
        foreach ($matches as $match) {
            // The current match is additionally bold, so it stands out from the
            // other matches without relying on the background color.
            $open = $match['current'] ? $styles->of(Role::SearchCurrentBg)->withBold()->getAnsiRestore() : $styles->of(Role::SearchBg)->getAnsiRestore();
            $close = $match['current'] ? "\x1b[49m\x1b[22m" : Sgr::DEFAULT_BG;
            $spans[] = [$doc->displayColAt($lineIndex, $match['start']), $doc->displayColAt($lineIndex, $match['end']), $open, $close, $open];
        }
        foreach ($occurrences as [$oLine, $oStart, $oEnd]) {
            if ($oLine !== $lineIndex) {
                continue;
            }

            $occurrenceBg = $styles->of(Role::OccurrenceBg)->getAnsiRestore();
            $spans[] = [$oStart, $oEnd, $occurrenceBg, Sgr::DEFAULT_BG, $occurrenceBg];
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
            $sl = min($caret->line, $caret->anchorLine);
            $el = max($caret->line, $caret->anchorLine);
            if (($caret->line !== $caret->anchorLine || $caret->col !== $caret->anchorCol) && $lineIndex >= $sl && $lineIndex <= $el) {
                [, $startCol, , $endCol] = self::caretRange($caret);
                $spans[] = self::selectionSpan($doc, $lineIndex, $sl, $startCol, $el, $endCol, $styles);
            }
            // The caret cell (reverse video), for the primary and extras alike.
            if ($caret->line === $lineIndex) {
                $col = $doc->displayColAt($lineIndex, $caret->col);
                $spans[] = [$col, $col + 1, Sgr::REVERSE, "\x1b[27m", null];
                // An end-of-line caret needs a padded cell to invert.
                $padTo = max($padTo, $col + 1);
            }
        }

        return ['spans' => $spans, 'padTo' => $padTo];
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
        $selectionBg = $styles->of(Role::SelectionBg)->getAnsiRestore();

        return [$startDisp, $endDisp, $selectionBg, Sgr::DEFAULT_BG, $selectionBg];
    }

    /**
     * Ordered [startLine, startCol, endLine, endCol] of a caret record's selection.
     *
     * @return array{int, int, int, int}
     */
    private static function caretRange(Caret $caret): array
    {
        $a = [$caret->anchorLine, $caret->anchorCol];
        $b = [$caret->line, $caret->col];
        [$start, $end] = $a <= $b ? [$a, $b] : [$b, $a];

        return [$start[0], $start[1], $end[0], $end[1]];
    }
}
