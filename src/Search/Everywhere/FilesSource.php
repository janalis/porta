<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Search\FileIndex;

/**
 * Files source: fuzzy project-file paths via the shared {@see FileIndex},
 * already ranked best-first. Activating a hit opens the file focused.
 */
final class FilesSource implements SearchSource
{
    private const int LIMIT = 20;

    /** @param \Closure(string, bool): void $openFile fn(absolutePath, focusEditor) */
    public function __construct(
        private readonly FileIndex $index,
        private readonly string $rootDir,
        private readonly \Closure $openFile,
    ) {}

    public function category(): SearchCategory
    {
        return SearchCategory::Files;
    }

    public function query(string $query, \Closure $emit): void
    {
        $hits = [];
        foreach ($this->index->search($query, self::LIMIT) as $path) {
            $absolute = $this->rootDir.'/'.$path;
            $dir = \dirname($path);
            $hits[] = new SearchHit(
                SearchCategory::Files,
                basename($path),
                '.' === $dir ? '' : $dir,
                function () use ($absolute): void {
                    ($this->openFile)($absolute, true);
                },
            );
        }
        $emit($hits);
    }

    public function cancel(): void {}
}
