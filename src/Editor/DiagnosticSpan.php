<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The display columns a diagnostic's squiggle covers on its line — the one
 * answer the underline draws from and the pointer hit-tests against, so a
 * hover finds a problem exactly where it is drawn.
 */
final class DiagnosticSpan
{
    /**
     * `[start, end)` in display columns. A zero-width range still covers its
     * one cell; a range that runs on past its line ends at `PHP_INT_MAX`.
     *
     * @param array{col: int, endCol?: int, ...} $diag columns in UTF-16 units, as the server sent them
     *
     * @return array{int, int}
     */
    public static function display(Document $doc, int $line, array $diag): array
    {
        $start = $doc->displayColAt($line, $doc->utf16ColToByteCol($line, $diag['col']));
        $endUtf16 = $diag['endCol'] ?? ($diag['col'] + 1);
        if (\PHP_INT_MAX === $endUtf16) {
            return [$start, \PHP_INT_MAX];
        }

        return [$start, \max($start + 1, $doc->displayColAt($line, $doc->utf16ColToByteCol($line, $endUtf16)))];
    }
}
