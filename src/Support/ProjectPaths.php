<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The project root and the path arithmetic against it. One shared instance,
 * bound to `$rootDir` by the container, replaces the `(rootDir, path)` pair
 * that fifteen classes threaded through their constructors just to spell
 * `relative($rootDir, $path)` — the pairing now cannot be mismatched. Bare
 * test construction stays one `new ProjectPaths($dir)`.
 *
 * The path-only helpers (extension, languageId) stay static: they need no
 * root.
 */
final readonly class ProjectPaths
{
    public function __construct(
        private string $rootDir,
    ) {}

    /** The project root this instance is bound to, for the callers that hand it on raw. */
    public function root(): string
    {
        return $this->rootDir;
    }

    /** Path relative to the project root, or the absolute path when outside it. */
    public function relative(string $path): string
    {
        $realPath = realpath($path);
        $real = $realPath ? $realPath : $path;
        $realRoot = realpath($this->rootDir);
        $root = rtrim($realRoot ? $realRoot : $this->rootDir, characters: '/');

        return str_starts_with($real, $root.'/') ? substr($real, \strlen($root) + 1) : $real;
    }

    /** The absolute path of a project-relative one. */
    public function absolute(string $relative): string
    {
        return $this->rootDir.'/'.$relative;
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
