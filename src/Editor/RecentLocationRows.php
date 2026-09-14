<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * The Recent Locations tool's rows: the places you jumped away from, most
 * recent first.
 *
 * Insertion order IS the answer here — unlike {@see BookmarkRows}, which sorts
 * by path and line — so nothing is re-sorted. Consecutive repeats of the same
 * place collapse: pressing go-to-definition twice on one line records it twice,
 * and a list showing it twice tells you nothing the first row did not.
 */
#[AsIdeService]
final readonly class RecentLocationRows
{
    public function __construct(
        private JumpHistory $history,
        private LineTextReader $text,
        private ProjectPaths $paths,
    ) {}

    /** @return list<array{path: string, line: int, text: string}> */
    public function build(): array
    {
        $rows = [];
        $readers = [];
        foreach ($this->history->entries() as $entry) {
            $path = $entry['path'];
            $line = $entry['line'];
            $previous = $rows[\count($rows) - 1] ?? null;
            $readers[$path] ??= $this->text->forPath($path);
            $row = ['path' => $this->paths->relative($path), 'line' => $line, 'text' => \trim($readers[$path]($line))];
            if (null !== $previous && $previous['path'] === $row['path'] && $previous['line'] === $row['line']) {
                continue; // the same place twice running is one place
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
