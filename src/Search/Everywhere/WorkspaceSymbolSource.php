<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\OpenFocus;
use Porta\Support\ProjectPaths;

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
    // @mago-expect lint:no-literal-password -- $token is a request-sequence counter, not a credential
    private int $token = 0;

    /**
     * @param \Closure(string, \Closure(list<array{path: string, line: int, name: string, kind: int}>): void): void $searchSymbols
     * @param list<int>                    $kinds    allowed LSP SymbolKinds; empty = all
     * @param \Closure(string, OpenFocus): void $openFile fn(absolutePath, focus)
     * @param \Closure(int, int): void     $jumpTo   fn(line, col)
     */
    public function __construct(
        private readonly \Closure $searchSymbols,
        private readonly SearchCategory $category,
        private readonly array $kinds,
        private readonly ProjectPaths $paths,
        private readonly \Closure $openFile,
        private readonly \Closure $jumpTo,
        /** @var (\Closure(): ?string)|null why there is no server to ask right now, or null when there is one */
        private readonly ?\Closure $unavailable = null,
    ) {}

    #[\Override]
    public function category(): SearchCategory
    {
        return $this->category;
    }

    #[\Override]
    public function query(string $query, \Closure $emit): void
    {
        $why = null === $this->unavailable ? null : ($this->unavailable)();
        if (null !== $why) {
            $emit([], $why); // nothing, and why — not "no results"

            return;
        }
        $token = ++$this->token;
        ($this->searchSymbols)($query, function (array $symbols) use ($emit, $token): void {
            // @mago-expect lint:no-insecure-comparison -- $token is a request-sequence counter, not sensitive data
            if ($token !== $this->token) {
                return; // a newer query or a cancel superseded this response
            }
            $hits = [];
            foreach ($symbols as $symbol) {
                if ([] !== $this->kinds && !\in_array($symbol['kind'], $this->kinds, strict: true)) {
                    continue;
                }
                $absolute = $this->paths->absolute($symbol['path']);
                $line = $symbol['line'];
                $hits[] = new SearchHit(
                    $this->category,
                    $symbol['name'],
                    $symbol['path'].':'.($line + 1),
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

    #[\Override]
    public function cancel(): void
    {
        ++$this->token;
    }
}
