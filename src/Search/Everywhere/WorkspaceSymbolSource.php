<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * Symbols source over LSP `workspace/symbol`: one instance for Classes (kinds
 * restricted to class/enum/interface/struct) and one for all Symbols. Async;
 * {@see cancel()} bumps a token so a superseded response is dropped. Activating a
 * hit opens the file and jumps to the symbol's line. The LSP request is injected
 * as a closure ({@see \Porta\Lsp\WorkspaceSymbolClient::search}) so it is testable
 * without a live server.
 */
final class WorkspaceSymbolSource implements SearchSource
{
    private int $token = 0;

    /**
     * @param \Closure(string, \Closure(list<array{path: string, line: int, name: string, kind: int, label: string}>): void): void $searchSymbols
     * @param list<int>                    $kinds    allowed LSP SymbolKinds; empty = all
     * @param \Closure(string, bool): void $openFile fn(absolutePath, focusEditor)
     * @param \Closure(int, int): void     $jumpTo   fn(line, col)
     */
    public function __construct(
        private readonly \Closure $searchSymbols,
        private readonly SearchCategory $category,
        private readonly array $kinds,
        private readonly string $rootDir,
        private readonly \Closure $openFile,
        private readonly \Closure $jumpTo,
    ) {}

    public function category(): SearchCategory
    {
        return $this->category;
    }

    public function query(string $query, \Closure $emit): void
    {
        $token = ++$this->token;
        ($this->searchSymbols)($query, function (array $symbols) use ($emit, $token): void {
            if ($token !== $this->token) {
                return; // a newer query or a cancel superseded this response
            }
            $hits = [];
            foreach ($symbols as $symbol) {
                if ([] !== $this->kinds && !\in_array($symbol['kind'], $this->kinds, true)) {
                    continue;
                }
                $absolute = $this->rootDir.'/'.$symbol['path'];
                $line = $symbol['line'];
                $hits[] = new SearchHit(
                    $this->category,
                    $symbol['name'],
                    $symbol['path'].':'.($line + 1),
                    function () use ($absolute, $line): void {
                        ($this->openFile)($absolute, true);
                        ($this->jumpTo)($line, 0);
                    },
                );
            }
            $emit($hits);
        });
    }

    public function cancel(): void
    {
        ++$this->token;
    }
}
