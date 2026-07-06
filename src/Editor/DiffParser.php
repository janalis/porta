<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Classify the lines of a `git diff` into typed rows for the diff viewer:
 * file/index headers (`meta`), hunk headers (`hunk`), additions (`add`),
 * deletions (`del`) and unchanged context (`context`).
 */
final class DiffParser
{
    private const array META_PREFIXES = [
        'diff ',
        'index ',
        '--- ',
        '+++ ',
        'new file',
        'deleted file',
        'similarity ',
        'rename ',
        'old mode',
        'new mode',
        '\\ ',
    ];

    /**
     * @return list<array{kind: string, text: string}>
     */
    public static function parse(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            $rows[] = ['kind' => self::classify($line), 'text' => $line];
        }

        return $rows;
    }

    private static function classify(string $line): string
    {
        if (str_starts_with($line, '@@')) {
            return 'hunk';
        }
        if (array_any(self::META_PREFIXES, static fn ($prefix) => str_starts_with($line, $prefix))) {
            return 'meta';
        }
        if (str_starts_with($line, '+')) {
            return 'add';
        }
        if (str_starts_with($line, '-')) {
            return 'del';
        }

        return 'context';
    }
}
