<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Tui;

/**
 * Find-in-Files modal readers for E2e suites: promote the modal to the
 * results pane, read its composited text, and locate the hardware-cursor
 * marker. Requires {@see RunsIde} (calls its frame()). Extracted with the
 * IdeEdgeCasesTest split.
 */
trait FindModalReads
{
    /**
     * Boot the IDE, run the scripted steps, stop after $duration seconds.
     *
     * @param array<int|string, callable> $steps delay-ms => callback(Tui, VirtualTerminal)
     */
    /**
     * Promote the open Find-in-Files modal to the results pane (Alt+Enter =
     * "Open in Find Window"), so a test can drive the persistent pane as before.
     * Call it after the initial query has settled.
     */
    private function promote(Tui $tui): void
    {
        $tui->handleInput("\x1b\r"); // alt+enter
        $tui->tick();
    }

    /** The Find-in-Files modal's text (it composites over the editor pane). */
    private function modalText(Tui $tui): string
    {
        // The modal is span-delivered per frame by its presenter — force a
        // frame so a mutation made this step is on the pane before reading.
        $this->frame($tui);

        return AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40))));
    }

    /** The editor pane's raw (styled) rendered rows (after a frame — see modalText). */
    private function editorRows(Tui $tui): array
    {
        $this->frame($tui);

        return $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40));
    }

    /** How many hardware-cursor markers the rendered rows carry (there must be exactly one). */
    private function caretMarkerCount(array $rows): int
    {
        return array_sum(array_map(static fn (string $l): int => substr_count($l, AnsiUtils::CURSOR_MARKER_PREFIX), $rows));
    }

    /** The index of the row carrying the hardware-cursor marker, or null. */
    private function caretMarkerRow(array $rows): ?int
    {
        foreach ($rows as $i => $line) {
            if (str_contains($line, AnsiUtils::CURSOR_MARKER_PREFIX)) {
                return $i;
            }
        }

        return null;
    }
}
