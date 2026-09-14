<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * The Bookmarks tool's rows: every bookmarked line as a jumpable entry showing
 * the line's own text. Pure over {@see Bookmarks}, the way
 * {@see \Porta\Search\ProblemRows} is over the buffers.
 *
 * The text comes through {@see LineTextReader}, because a bookmark outlives
 * the buffer that made it — a list of bare line numbers would be no help at
 * all.
 */
#[AsIdeService]
final readonly class BookmarkRows
{
    public function __construct(
        private Bookmarks $bookmarks,
        private LineTextReader $text,
        private ProjectPaths $paths,
    ) {}

    /** @return list<array{path: string, line: int, text: string}> */
    public function build(): array
    {
        $rows = [];
        foreach ($this->bookmarks->all() as $path => $lines) {
            $read = $this->text->forPath($path);
            foreach ($lines as $line) {
                $rows[] = ['path' => $this->paths->relative($path), 'line' => $line, 'text' => \trim($read($line))];
            }
        }
        \usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $rows;
    }
}
