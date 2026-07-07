<?php

declare(strict_types=1);

namespace Porta\Git;

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
        $hunks = [];

        $lines = explode("\n", $raw);
        if ('' === end($lines)) {
            array_pop($lines); // trailing newline
        }

        $current = null; // array{old: int, oldCount: int, new: int, newCount: int, heading: string, lines: list<DiffLine>, oldNo: int, newNo: int}
        $flush = static function () use (&$current, &$hunks): void {
            if (null !== $current) {
                $hunks[] = new DiffHunk($current['old'], $current['oldCount'], $current['new'], $current['newCount'], $current['heading'], $current['lines']);
                $current = null;
            }
        };

        foreach ($lines as $line) {
            $m = [];
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@ ?(.*)$/', $line, $m)) {
                $flush();
                $current = [
                    'old' => (int) $m[1],
                    'oldCount' => '' === ($m[2] ?? '') ? 1 : (int) $m[2],
                    'new' => (int) $m[3],
                    'newCount' => '' === ($m[4] ?? '') ? 1 : (int) $m[4],
                    'heading' => $m[5],
                    'lines' => [],
                ];
                $current['oldNo'] = $current['old'];
                $current['newNo'] = $current['new'];

                continue;
            }

            if (null === $current) {
                if ('' !== $line) {
                    $headerLines[] = $line;
                }

                continue;
            }

            $origin = $line[0] ?? ' ';
            $text = substr($line, 1);
            switch ($origin) {
                case '+':
                    $current['lines'][] = new DiffLine('+', $text, null, $current['newNo']++);
                    break;
                case '-':
                    $current['lines'][] = new DiffLine('-', $text, $current['oldNo']++, null);
                    break;
                case '\\':
                    $current['lines'][] = new DiffLine('\\', $text, null, null);
                    break;
                default:
                    // Context (including a fully empty line, which git emits as ' ').
                    $current['lines'][] = new DiffLine(' ', $text, $current['oldNo']++, $current['newNo']++);
            }
        }
        $flush();

        return new self($headerLines, $hunks);
    }

    public function isEmpty(): bool
    {
        return [] === $this->hunks;
    }

    /**
     * The flat render row list: one 'hunk' row per hunk header followed by
     * one 'line' row per body line (meta header lines are not rendered — the
     * view shows the file name in its own chrome).
     *
     * @return list<array{kind: 'hunk'|'line', hunk: int, line: int}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->hunks as $h => $hunk) {
            $rows[] = ['kind' => 'hunk', 'hunk' => $h, 'line' => -1];
            foreach ($hunk->lines as $l => $line) {
                $rows[] = ['kind' => 'line', 'hunk' => $h, 'line' => $l];
            }
        }

        return $rows;
    }
}
