<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Small pure helpers for project-relative paths and language identification,
 * shared by the coordinator and the LSP feature controllers.
 */
final class ProjectPaths
{
    /** Path relative to the project root, or the absolute path when outside it. */
    public static function relative(string $rootDir, string $path): string
    {
        $realPath = realpath($path);
        $real = $realPath ? $realPath : $path;
        $realRoot = realpath($rootDir);
        $root = rtrim($realRoot ? $realRoot : $rootDir, characters: '/');

        return str_starts_with($real, $root.'/') ? substr($real, \strlen($root) + 1) : $real;
    }

    /** LSP languageId for a file, by extension. See {@see LanguageMap}. */
    public static function languageId(string $path): string
    {
        return LanguageMap::lspForPath($path);
    }
}
