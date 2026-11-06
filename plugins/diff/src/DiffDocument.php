<?php

declare(strict_types=1);

namespace Porta\DiffPlugin;

/**
 * A single file's unified diff, parsed into hunks with per-line old/new line
 * numbers. One structure serves both the diff view (via {@see rows()}) and
 * patch crafting for line staging (via the preserved hunk bodies).
 */
final readonly class DiffDocument
{
    /**
     * @param list<string>   $headerLines the meta lines before the first hunk
     * @param list<DiffHunk> $hunks
     */
    private function __construct(
        public array $headerLines,
        public array $hunks,
    ) {}

    public static function parse(string $raw): self
    {
        $headerLines = [];
        /** @var list<array{old: int, oldCount: int, new: int, newCount: int, heading: string, lines: list<DiffLine>, oldNo: int, newNo: int}> $pending */
        $pending = [];

        $lines = \explode("\n", $raw);
        if ('' === \end($lines)) {
            \array_pop($lines); // trailing newline
        }

        foreach ($lines as $line) {
            $m = [];
            if (\preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@ ?(.*)$/', $line, $m)) {
                $old = (int) $m[1];
                $new = (int) $m[3];
                $pending[] = [
                    'old' => $old,
                    'oldCount' => '' === ($m[2] ?? '') ? 1 : (int) $m[2],
                    'new' => $new,
                    'newCount' => '' === ($m[4] ?? '') ? 1 : (int) $m[4],
                    'heading' => $m[5],
                    'lines' => [],
                    'oldNo' => $old,
                    'newNo' => $new,
                ];

                continue;
            }

            if ([] === $pending) {
                if ('' !== $line) {
                    $headerLines[] = $line;
                }

                continue;
            }

            $last = \array_key_last($pending);
            $origin = $line[0] ?? ' ';
            $text = \substr($line, offset: 1);
            $pending[$last]['lines'][] = match ($origin) {
                '+' => new DiffLine('+', $text, null, $pending[$last]['newNo']++),
                '-' => new DiffLine('-', $text, $pending[$last]['oldNo']++, null),
                '\\' => new DiffLine('\\', $text, null, null),
                // Context (including a fully empty line, which git emits as ' ').
                default => new DiffLine(' ', $text, $pending[$last]['oldNo']++, $pending[$last]['newNo']++),
            };
        }

        $hunks = \array_map(
            static fn (array $h): DiffHunk => new DiffHunk($h['old'], $h['oldCount'], $h['new'], $h['newCount'], $h['heading'], self::withIntraline($h['lines'])),
            $pending,
        );

        return new self($headerLines, $hunks);
    }

    /**
     * Pair each ± run's i-th deletion with its i-th addition and stamp the
     * intraline changed range on both lines.
     *
     * @param list<DiffLine> $lines
     *
     * @return list<DiffLine>
     */
    private static function withIntraline(array $lines): array
    {
        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            // A run is consumed whole below, so every '-' reached here starts one.
            if ('-' !== $lines[$i]->origin) {
                continue;
            }
            $delStart = $i;
            $dels = 0;
            while (($delStart + $dels) < $count && '-' === $lines[$delStart + $dels]->origin) {
                ++$dels;
            }
            $addStart = $delStart + $dels;
            // The old side's `\ No newline` sits between the runs: the last
            // line changed in a file with no final newline still pairs.
            $addStart += $addStart < $count && '\\' === $lines[$addStart]->origin ? 1 : 0;
            $adds = 0;
            while (($addStart + $adds) < $count && '+' === $lines[$addStart + $adds]->origin) {
                ++$adds;
            }
            for ($p = 0; $p < \min($dels, $adds); ++$p) {
                $del = $lines[$delStart + $p];
                $add = $lines[$addStart + $p];
                $ranges = IntralineDiff::changedRanges($del->text, $add->text);
                if (null !== $ranges) {
                    $lines[$delStart + $p] = new DiffLine('-', $del->text, $del->oldNo, $del->newNo, $ranges[0]);
                    $lines[$addStart + $p] = new DiffLine('+', $add->text, $add->oldNo, $add->newNo, $ranges[1]);
                }
            }
            $i = $addStart + $adds - 1;
        }

        return $lines;
    }

    public function isEmpty(): bool
    {
        return [] === $this->hunks;
    }

    /**
     * Whether git said the two sides are binary and differ — a change with no
     * hunk to show, which {@see isEmpty()} alone reads as "nothing changed".
     */
    public function isBinary(): bool
    {
        return [] !== \preg_grep('/^Binary files .* differ$/', $this->headerLines);
    }

    /**
     * The flat render row list: one 'hunk' row per hunk header followed by
     * one 'line' row per body line (meta header lines are not rendered — the
     * view shows the file name in its own chrome).
     *
     * @return list<array{kind: DiffRowKind, hunk: int, line: int}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->hunks as $h => $hunk) {
            $rows[] = ['kind' => DiffRowKind::Hunk, 'hunk' => $h, 'line' => -1];
            foreach (\array_keys($hunk->lines) as $l) {
                $rows[] = ['kind' => DiffRowKind::Line, 'hunk' => $h, 'line' => $l];
            }
        }

        return $rows;
    }

    /**
     * The side-by-side render row list: 'hunk' rows as in {@see rows()}, then
     * one 'pair' row per old/new line pair — a context line occupies both
     * sides, a ± run pairs its i-th deletion with its i-th addition (the same
     * pairing the intraline emphasis uses), and surplus lines leave the other
     * side at -1 (blank). A `\ No newline` marker rides the side of the line
     * it terminates.
     *
     * @return list<array{kind: DiffRowKind, hunk: int, left: int, right: int}>
     */
    public function splitRows(): array
    {
        $rows = [];
        foreach ($this->hunks as $h => $hunk) {
            $rows[] = ['kind' => DiffRowKind::Hunk, 'hunk' => $h, 'left' => -1, 'right' => -1];
            $lines = $hunk->lines;
            $count = \count($lines);
            for ($i = 0; $i < $count; ++$i) {
                $origin = $lines[$i]->origin;
                if (' ' === $origin) {
                    $rows[] = ['kind' => DiffRowKind::Pair, 'hunk' => $h, 'left' => $i, 'right' => $i];

                    continue;
                }
                if ('\\' === $origin) {
                    // Terminates the previous line: additions end on the right,
                    // everything else (deletions, context) on the left.
                    $side = $i > 0 && '+' === $lines[$i - 1]->origin ? 'right' : 'left';
                    $rows[] = ['kind' => DiffRowKind::Pair, 'hunk' => $h, 'left' => 'left' === $side ? $i : -1, 'right' => 'right' === $side ? $i : -1];

                    continue;
                }
                if ('+' === $origin) {
                    // An addition run with no preceding deletions: right side only.
                    $rows[] = ['kind' => DiffRowKind::Pair, 'hunk' => $h, 'left' => -1, 'right' => $i];

                    continue;
                }
                // Start of a deletion run: it, the additions after it, and the markers ending them.
                [$change, $i] = self::changeRows($lines, $h, $i);
                \array_push($rows, ...$change);
            }
        }

        return $rows;
    }

    /**
     * The rows of the change starting at $i — a deletion run, the additions
     * after it — and the index of its last line.
     *
     * @param list<DiffLine> $lines
     *
     * @return array{list<array{kind: DiffRowKind, hunk: int, left: int, right: int}>, int}
     */
    private static function changeRows(array $lines, int $h, int $i): array
    {
        $count = \count($lines);
        $rows = [];
        $dels = 0;
        while (($i + $dels) < $count && '-' === $lines[$i + $dels]->origin) {
            ++$dels;
        }
        $addStart = $i + $dels;
        // The old side's `\ No newline` between the runs is set aside,
        // so the runs still pair; it and the new side's own, after the
        // additions, end the change on one row, each on its side.
        $leftEnd = $addStart < $count && '\\' === $lines[$addStart]->origin ? $addStart : -1;
        $addStart += $leftEnd >= 0 ? 1 : 0;
        $adds = 0;
        while (($addStart + $adds) < $count && '+' === $lines[$addStart + $adds]->origin) {
            ++$adds;
        }
        for ($p = 0; $p < \max($dels, $adds); ++$p) {
            $rows[] = [
                'kind' => DiffRowKind::Pair,
                'hunk' => $h,
                'left' => $p < $dels ? $i + $p : -1,
                'right' => $p < $adds ? $addStart + $p : -1,
            ];
        }
        $after = $addStart + $adds;
        $rightEnd = $leftEnd >= 0 && $adds > 0 && $after < $count && '\\' === $lines[$after]->origin ? $after : -1;
        if ($leftEnd >= 0) {
            $rows[] = ['kind' => DiffRowKind::Pair, 'hunk' => $h, 'left' => $leftEnd, 'right' => $rightEnd];
        }

        return [$rows, \max($rightEnd, $addStart + $adds - 1)];
    }
}
