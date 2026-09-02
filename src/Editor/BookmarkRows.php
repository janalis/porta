<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;

/**
 * The Bookmarks tool's rows: every bookmarked line as a jumpable entry showing
 * the line's own text. Pure over {@see Bookmarks}, the way
 * {@see \Porta\Search\ProblemRows} is over the buffers.
 *
 * The text comes from the open buffer when there is one and from disk
 * otherwise, because a bookmark outlives the buffer that made it — a list of
 * bare line numbers would be no help at all.
 */
#[AsIdeService]
final readonly class BookmarkRows
{
    public function __construct(
        private Bookmarks $bookmarks,
        private BufferManager $buffers,
        private ProjectPaths $paths,
    ) {}

    /** @return list<array{path: string, line: int, text: string}> */
    public function build(): array
    {
        $rows = [];
        foreach ($this->bookmarks->all() as $path => $lines) {
            $document = $this->buffers->get($path)?->document;
            $onDisk = null === $document ? Files::readWritable($path) : null;
            $fileLines = null === $onDisk ? [] : explode("\n", $onDisk);
            foreach ($lines as $line) {
                $text = null !== $document ? $document->getLine($line) : $fileLines[$line] ?? '';
                $rows[] = ['path' => $this->paths->relative($path), 'line' => $line, 'text' => trim($text)];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $rows;
    }
}
