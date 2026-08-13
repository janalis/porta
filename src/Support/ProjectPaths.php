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

    /** The path's extension, lowercased; '' when it has none. */
    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, \PATHINFO_EXTENSION));
    }

    /**
     * Whether the path's lowercased extension is one of $extensions — the
     * routing test every viewer and decoder claim hand-rolled inline.
     *
     * @param list<string> $extensions lowercase, without the dot
     */
    public static function hasExtension(string $path, array $extensions): bool
    {
        return \in_array(self::extension($path), $extensions, strict: true);
    }
}
