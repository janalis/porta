<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Whether a server's `TextEdit[]` is applicable, per the LSP rule that says
 * ranges must never overlap.
 *
 * This exists because Porta used to apply them anyway. {@see WorkspaceEditor}
 * sorts bottom-up so earlier positions stay valid, and two edits covering the
 * same characters BOTH land — the second clamping into whatever the first left
 * behind. The result is a file that is neither what the server asked for nor
 * what it started as, reported as a success. Corruption, silently, in the path
 * that runs on every save when format-on-save is on.
 *
 * The spec is precise about what counts, and the precision matters because the
 * naive test forbids something legal:
 *
 * > All text edits ranges must never overlap, that means no part of the
 * > original document must be manipulated by more than one edit. However, it is
 * > possible that multiple edits have the same start position: multiple
 * > inserts, or any number of inserts followed by a single remove or replace
 * > edit.
 *
 * So a shared start position is fine — several inserts at one point are how a
 * server adds two imports on the same line — and only a range that reaches INTO
 * another is an overlap.
 */
final class TextEditOverlap
{
    /**
     * The first pair of edits that overlap, described, or null when the list is
     * applicable.
     *
     * A description rather than a bool because the caller reports it: "the
     * server sent an edit Porta refused" is only actionable if it says which.
     *
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}> $edits
     */
    public static function describe(array $edits): ?string
    {
        // The `?? 0` defaults are for a malformed payload only — a conforming
        // server always sends both coordinates — and WHICH constant they
        // degenerate to cannot change the verdict: a range missing a coordinate
        // is empty or inverted, and neither can overlap anything under the
        // `next.start >= this.end` test below. Mutating them is therefore
        // equivalent, and the same `?? 0` reading is what
        // {@see WorkspaceEditor::applyTextEdits()} applies them with, so the
        // check and the application agree about a malformed edit.
        $ranges = [];
        foreach ($edits as $edit) {
            $ranges[] = [
                'start' => [$edit['range']['start']['line'] ?? 0, $edit['range']['start']['character'] ?? 0],
                'end' => [$edit['range']['end']['line'] ?? 0, $edit['range']['end']['character'] ?? 0],
            ];
        }
        // Sorted by START, top-down — the opposite of the order they are
        // applied in, because "does this one reach into the next" is a question
        // about the ORIGINAL document and reads forwards.
        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($ranges as $index => $range) {
            $next = $ranges[$index + 1] ?? null;
            // Strictly inside: a next edit STARTING where this one ends is
            // adjacent, not overlapping, and servers emit adjacent edits all
            // the time (rewriting two neighbouring tokens).
            if (null === $next || $next['start'] >= $range['end']) {
                continue;
            }

            return \sprintf(
                '%d:%d-%d:%d overlaps %d:%d-%d:%d',
                $range['start'][0] + 1,
                $range['start'][1],
                $range['end'][0] + 1,
                $range['end'][1],
                $next['start'][0] + 1,
                $next['start'][1],
                $next['end'][0] + 1,
                $next['end'][1],
            );
        }

        return null;
    }
}
