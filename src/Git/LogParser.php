<?php

declare(strict_types=1);

namespace Porta\Git;

/**
 * Parsers for the history tab's plumbing output: NUL-record git log with
 * unit-separator fields, and `show --name-status -z` file lists.
 */
final class LogParser
{
    /**
     * Parse `log -z --format=%H%x1f%h%x1f%an%x1f%at%x1f%s` records.
     *
     * @return list<LogEntry>
     */
    public static function parseLog(string $raw): array
    {
        $entries = [];
        foreach (explode("\0", $raw) as $record) {
            $fields = explode("\x1f", trim($record, "\n"));
            if (5 !== \count($fields) || '' === $fields[0]) {
                continue;
            }
            $entries[] = new LogEntry($fields[0], $fields[1], $fields[2], (int) $fields[3], $fields[4]);
        }

        return $entries;
    }

    /**
     * Parse `show -z --name-status --format=` output: `STATUS\0path\0`
     * records, with renames/copies carrying two paths (old, then new).
     *
     * @return list<array{status: string, path: string}>
     */
    public static function parseNameStatus(string $raw): array
    {
        $tokens = explode("\0", trim($raw, "\n\0"));
        $count = \count($tokens);
        $files = [];
        $i = 0;
        while ($i < $count) {
            $status = $tokens[$i];
            if ('' === $status) {
                ++$i;
                continue;
            }
            $letter = $status[0];
            if (('R' === $letter || 'C' === $letter) && ($i + 2) < $count) {
                $files[] = ['status' => $letter, 'path' => $tokens[$i + 2]]; // old path skipped
                $i += 3;
            } elseif (($i + 1) < $count) {
                $files[] = ['status' => $letter, 'path' => $tokens[$i + 1]];
                $i += 2;
            } else {
                break; // trailing status without a path: malformed tail
            }
        }

        return $files;
    }
}
