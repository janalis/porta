<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\OpenFocus;
use Porta\Search\FileIndex;
use Porta\Support\ProjectPaths;

/**
 * Files source: fuzzy project-file paths via the shared {@see FileIndex},
 * already ranked best-first. Activating a hit opens the file focused.
 */
final readonly class FilesSource implements SearchSource
{
    private const int LIMIT = 20;

    /** @param \Closure(string, OpenFocus): void $openFile fn(absolutePath, focus) */
    public function __construct(
        private FileIndex $index,
        private ProjectPaths $paths,
        private \Closure $openFile,
    ) {}

    #[\Override]
    public function category(): SearchCategory
    {
        return SearchCategory::Files;
    }

    #[\Override]
    public function query(string $query, \Closure $emit): void
    {
        $hits = [];
        foreach ($this->index->search($query, self::LIMIT) as $path) {
            $absolute = $this->paths->absolute($path);
            $dir = \dirname($path);
            $hits[] = new SearchHit(
                SearchCategory::Files,
                \basename($path),
                '.' === $dir ? '' : $dir,
                function () use ($absolute): void {
                    ($this->openFile)($absolute, OpenFocus::Editor);
                },
            );
        }
        $emit($hits);
    }

    #[\Override]
    public function cancel(): void {}
}
