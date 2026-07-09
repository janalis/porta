<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\ProjectPaths;

/**
 * Thin client over the LSP `workspace/symbol` request: issues the query and maps
 * each result to a project-relative `{path, line, name, kind, label}`. Shared by
 * the go-to-symbol panel and the Search Everywhere modal's Classes/Symbols
 * sources (which filter by `kind`). Returns an empty list when no server is ready.
 */
final class WorkspaceSymbolClient
{
    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly string $rootDir,
    ) {}

    /**
     * @param \Closure(list<array{path: string, line: int, name: string, kind: int, label: string}>): void $onResult
     */
    public function search(string $query, \Closure $onResult): void
    {
        if (!$this->lsp->isInitialized()) {
            $onResult([]);

            return;
        }

        $rootDir = $this->rootDir;
        $this->lsp->request('workspace/symbol', ['query' => $query], static function (mixed $result) use ($onResult, $rootDir): void {
            $onResult(self::map($result, $rootDir));
        });
    }

    /**
     * Map a raw `workspace/symbol` response to project-relative symbol rows.
     *
     * @return list<array{path: string, line: int, name: string, kind: int, label: string}>
     */
    public static function map(mixed $result, string $rootDir): array
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
            $kind = (int) ($symbol['kind'] ?? 0);
            $kindLabel = LspMapper::SYMBOL_KINDS[$kind] ?? '';
            $name = (string) ($symbol['name'] ?? '');
            $mapped[] = [
                'path' => ProjectPaths::relative($rootDir, $target),
                'line' => (int) ($start['line'] ?? 0),
                'name' => $name,
                'kind' => $kind,
                'label' => trim(($kindLabel ? "[{$kindLabel}] " : '').$name),
            ];
        }

        return $mapped;
    }
}
