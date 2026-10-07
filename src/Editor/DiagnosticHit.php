<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which of a line's diagnostics are AT a place, worst first: under the
 * pointer, or around the caret — the problem a hover card or Ctrl+F1 is
 * about.
 */
final class DiagnosticHit
{
    /**
     * The diagnostics whose squiggle covers a display column. A range that
     * runs on past its line stops at the line's last character: the empty
     * cells after the code are no problem's.
     *
     * @template T of array{severity: DiagnosticSeverity, col: int, endCol?: int, ...}
     *
     * @param list<T> $lineDiags
     *
     * @return list<T>
     */
    public static function atDisplayCol(Document $doc, int $line, array $lineDiags, int $displayCol): array
    {
        $width = $doc->lineDisplayWidth($line);

        return DiagnosticStyle::worstFirst(\array_values(\array_filter($lineDiags, static function (array $diag) use ($doc, $line, $width, $displayCol): bool {
            [$start, $end] = DiagnosticSpan::display($doc, $line, $diag);

            return $displayCol >= $start && $displayCol < \min($end, \max($start + 1, $width));
        })));
    }

    /**
     * The diagnostics around the caret, PhpStorm's way: the caret just after
     * a range still counts — that is where it rests after typing the
     * offending word — and an empty range is found at its column.
     *
     * @template T of array{severity: DiagnosticSeverity, col: int, endCol?: int, ...}
     *
     * @param list<T> $lineDiags
     * @param int     $utf16Col  the caret's column in UTF-16 units, as the diagnostics are
     *
     * @return list<T>
     */
    public static function atCaret(array $lineDiags, int $utf16Col): array
    {
        return DiagnosticStyle::worstFirst(\array_values(\array_filter(
            $lineDiags,
            static fn (array $diag): bool => $utf16Col >= $diag['col'] && $utf16Col <= ($diag['endCol'] ?? ($diag['col'] + 1)),
        )));
    }
}
