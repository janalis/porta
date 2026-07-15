<?php

declare(strict_types=1);

namespace Porta\Git;

/**
 * Parses whole-file `git blame --line-porcelain` output into per-line
 * annotations. Porcelain emits a commit's metadata (author, author-time)
 * only on its FIRST block; later blocks reference the sha alone — so the
 * metadata is cached per full sha and each header resolves from the cache.
 * Uncommitted lines arrive under the zero sha with author "Not Committed
 * Yet" and are displayed verbatim.
 */
final class BlameParser
{
    /** @return array<int, array{sha: string, author: string, date: string}> 0-based line => entry */
    public static function parse(string $porcelain): array
    {
        /** @var array<string, string> $authors */
        $authors = [];
        /** @var array<string, int> $times */
        $times = [];
        /** @var array<int, string> $lines */
        $lines = [];
        $sha = '';
        foreach (explode("\n", $porcelain) as $line) {
            $m = [];
            if (1 === preg_match('/^([0-9a-f]{40}) \d+ (\d+)/', $line, $m)) {
                $sha = $m[1];
                $lines[(int) $m[2] - 1] = $sha; // porcelain lines are 1-based
            } elseif (str_starts_with($line, 'author ') && '' !== $sha) {
                $authors[$sha] = substr($line, 7);
            } elseif (str_starts_with($line, 'author-time ') && '' !== $sha) {
                $times[$sha] = (int) substr($line, 12);
            }
        }

        $entries = [];
        foreach ($lines as $no => $lineSha) {
            $time = $times[$lineSha] ?? 0;
            $entries[$no] = [
                'sha' => substr($lineSha, 0, 8),
                'author' => $authors[$lineSha] ?? '',
                // Uncommitted lines carry no author-time — no fake epoch date.
                'date' => 0 === $time ? '' : date('Y-m-d', $time),
            ];
        }

        return $entries;
    }
}
