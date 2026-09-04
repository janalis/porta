<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;

/**
 * The enclosing scopes of the file on screen, as the language server sees them.
 *
 * PHP has an offline scanner ({@see \Porta\Editor\BreadcrumbScanner}) that is
 * instant and works with no server at all, so this exists for every OTHER
 * language, whose breadcrumbs bar was simply blank however much the server knew.
 *
 * One file's worth, not a cache: the bar only ever shows the active buffer, and
 * an answer for a file you have navigated away from is not worth keeping.
 */
#[AsIdeService]
final class DocumentScopes
{
    private string $path = '';

    /** @var list<array{label: string, startLine: int, endLine: int, kind: string}> */
    private array $scopes = [];

    /**
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     */
    public function set(string $path, array $scopes): void
    {
        $this->path = $path;
        $this->scopes = $scopes;
    }

    /**
     * The scopes for $path, or [] when the stored answer belongs to another
     * file — a stale trail naming another file's classes is worse than none.
     *
     * @return list<array{label: string, startLine: int, endLine: int, kind: string}>
     */
    public function forPath(?string $path): array
    {
        return null !== $path && $path === $this->path ? $this->scopes : [];
    }
}
