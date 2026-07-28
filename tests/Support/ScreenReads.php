<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Screen-scrape helpers for E2e assertions on the composed frame: the full
 * grid as plain rows, and row lookup by content. Extracted with the
 * IdeEdgeCasesTest split — several suites read the screen the same way.
 */
trait ScreenReads
{
    /**
     * Force a full frame and return the screen as stripped text rows. A forced
     * render resets the differential writer, so the output is the clear/home
     * prefix followed by every row joined by CRLF — splittable into a grid,
     * unlike the incremental updates a normal render emits.
     *
     * @return list<string>
     */
    private function screenGrid(Tui $tui, VirtualTerminal $terminal): array
    {
        $terminal->consumeOutput(); // drop anything buffered so far
        $tui->requestRender(force: true); // reset the writer -> next frame is full
        $tui->processRender();
        $raw = $terminal->consumeOutput();
        $body = preg_replace('/^.*\x1b\[H/s', replacement: '', subject: $raw); // strip up to cursor-home

        return array_map(
            AnsiUtils::stripAnsiCodes(...),
            explode("\r\n", (string) $body),
        );
    }

    /**
     * The index of the first grid row containing $needle, or null.
     *
     * @param list<string> $grid
     */
    private function rowOf(array $grid, string $needle): ?int
    {
        foreach ($grid as $i => $row) {
            if (str_contains($row, $needle)) {
                return $i;
            }
        }

        return null;
    }
}
