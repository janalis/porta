<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * What `dot` says is wrong with a source, as the editor's diagnostics: its
 * `Error: <stdin>: syntax error in line 3 near '->'` becomes an error on that
 * line, at the token it names. A source error belongs beside the text that
 * caused it, like any linter's, not in a banner over the picture.
 *
 * Only what names a line is the source's: "graphviz not found" or output
 * that does not parse is the tool's failure, and says nothing about a line.
 */
final class DotDiagnostics
{
    /** One report: its kind, then its text, in which `line N` and a `near 'token'` may appear. */
    private const string REPORT = '/^(Error|Warning): (?:<stdin>: ?)?(.*\bline (\d+)\b.*)$/m';

    /**
     * The reports in $stderr that name a line of $dot, 0-based, at the column
     * of the token they name when that line has it, else its start.
     *
     * @return list<array{line: int, message: string, severity: int, column: int}>
     */
    public static function parse(string $stderr, string $dot): array
    {
        $lines = \explode("\n", $dot);
        $last = \max(0, \count($lines) - 1);
        $reports = [];
        \preg_match_all(self::REPORT, $stderr, $reports, \PREG_SET_ORDER);
        $diagnostics = [];
        foreach ($reports as [, $kind, $text, $number]) {
            // Past the end — a brace never closed — is reported one line on.
            $line = \min($last, \max(0, (int) $number - 1));
            $near = [];
            $token = 1 === \preg_match("/ near '(.+)'/", $text, $near) ? $near[1] : '';
            $column = '' === $token ? false : \strpos($lines[$line], $token);
            $diagnostics[] = [
                'line' => $line,
                'message' => \trim((string) \preg_replace('/ in line \d+/', replacement: '', subject: $text)),
                'severity' => 'Error' === $kind ? 1 : 2,
                'column' => false === $column ? 0 : $column,
            ];
        }

        return $diagnostics;
    }
}
