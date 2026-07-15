<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Conversion between local filesystem paths and `file://` LSP document URIs.
 *
 * Stateless and root-independent: the language server and the coordinator both
 * speak URIs, so this is the single home for the encoding (previously duplicated
 * on {@see LspSession} and the coordinator).
 */
final class Uri
{
    public static function fromPath(string $path): string
    {
        $resolved = realpath($path);
        $real = $resolved ? $resolved : $path;

        return 'file://'.implode(separator: '/', array: array_map('rawurlencode', explode(separator: '/', string: str_replace(\DIRECTORY_SEPARATOR, replace: '/', subject: $real))));
    }

    public static function toPath(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = rawurldecode(substr($uri, offset: 7));

        return '' !== $path ? $path : null;
    }
}
