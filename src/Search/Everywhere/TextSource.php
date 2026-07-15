<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\OpenFocus;
use Porta\Search\ProjectSearch;

/**
 * Text source: project-wide grep via the shared {@see ProjectSearch} (async,
 * self-cancelling on a new query). Activating a hit opens the file and jumps to
 * the matching line.
 */
final class TextSource implements SearchSource
{
    /**
     * @param \Closure(string, OpenFocus): void $openFile fn(absolutePath, focus)
     * @param \Closure(int, int): void     $jumpTo   fn(line, col)
     */
    public function __construct(
        private readonly ProjectSearch $search,
        private readonly string $rootDir,
        private readonly \Closure $openFile,
        private readonly \Closure $jumpTo,
    ) {}

    public function category(): SearchCategory
    {
        return SearchCategory::Text;
    }

    public function query(string $query, \Closure $emit): void
    {
        $this->search->search($query, function (array $results) use ($emit): void {
            $hits = [];
            foreach ($results as $result) {
                $absolute = $this->rootDir.'/'.$result['path'];
                $line = $result['line'];
                $hits[] = new SearchHit(
                    SearchCategory::Text,
                    trim($result['text']),
                    $result['path'].':'.($line + 1),
                    function () use ($absolute, $line): void {
                        $column = 0;
                        ($this->openFile)($absolute, OpenFocus::Editor);
                        ($this->jumpTo)($line, $column);
                    },
                );
            }
            $emit($hits);
        });
    }

    public function cancel(): void
    {
        $this->search->cancel();
    }
}
