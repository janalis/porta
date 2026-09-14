<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\ProjectPaths;

/**
 * Thin client over the LSP `workspace/symbol` request: issues the query and maps
 * each result to a project-relative `{path, line, name, kind}`. Shared by the
 * go-to-symbol panel and the Search Everywhere modal's Classes/Symbols sources
 * (which filter by `kind`). Returns an empty list when no server is ready.
 *
 * It carried a `label` — `"[class] Greeter"` — until this row shape's only
 * consumer became {@see \Porta\Search\Everywhere\WorkspaceSymbolSource},
 * which renders `name` and puts the path in the hint. Nothing had read the
 * label since; it survived because it was covered and asserted, which is what
 * dead data that LOOKS like display data does. It cost a wrong diagnosis: a
 * mutation of it left the go-to-symbol E2e green, and the test was blamed.
 */
final readonly class WorkspaceSymbolClient
{
    public function __construct(
        private LspSessionPool $lsp,
        private ProjectPaths $paths,
    ) {}

    /**
     * @param \Closure(list<array{path: string, line: int, name: string, kind: int}>): void $onResult
     */
    public function search(string $query, \Closure $onResult): void
    {
        if (!$this->lsp->isInitialized()) {
            $onResult([]);

            return;
        }

        $paths = $this->paths;
        $this->lsp->request('workspace/symbol', ['query' => $query], static function (mixed $result) use ($onResult, $paths): void {
            $onResult(self::map($result, $paths));
        });
    }

    /**
     * Map a raw `workspace/symbol` response to project-relative symbol rows.
     *
     * @return list<array{path: string, line: int, name: string, kind: int}>
     */
    public static function map(mixed $result, ProjectPaths $paths): array
    {
        /** @var list<array{location?: array{uri?: mixed, range?: array{start?: array<string, mixed>}}, kind?: mixed, name?: mixed}> $symbols */
        $symbols = \is_array($result) ? $result : [];
        $mapped = [];
        foreach ($symbols as $symbol) {
            $target = Uri::toPath((string) ($symbol['location']['uri'] ?? ''));
            if (null === $target) {
                continue;
            }
            $start = $symbol['location']['range']['start'] ?? ['line' => 0];
            $mapped[] = [
                'path' => $paths->relative($target),
                'line' => (int) ($start['line'] ?? 0),
                'name' => (string) ($symbol['name'] ?? ''),
                'kind' => (int) ($symbol['kind'] ?? 0),
            ];
        }

        return $mapped;
    }
}
