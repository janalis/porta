<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Filesystem\Path;

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
final class ProjectPaths
{
    /** The root realpath'd and slash-trimmed once — relative() runs per frame, the root cannot change. */
    private readonly string $realRoot;

    /**
     * Memoized `absolute path → relative` (readonly had to go for this one
     * mutable cache): relative() does a realpath() SYSCALL per call and runs
     * per frame (the status bar, the tab strip, every search-result row).
     * Only resolved paths are cached — a path that did not exist yet must
     * re-resolve once it does. Flushed wholesale at the cap; the mapping for
     * an existing file cannot otherwise change mid-session.
     *
     * @var array<string, string>
     */
    private array $relativeMemo = [];

    private const int RELATIVE_MEMO_CAP = 1024;

    public function __construct(
        private readonly string $rootDir,
    ) {
        $realRoot = realpath($rootDir);
        $this->realRoot = rtrim($realRoot ? $realRoot : $rootDir, characters: '/');
    }

    /** The project root this instance is bound to, for the callers that hand it on raw. */
    public function root(): string
    {
        return $this->rootDir;
    }

    /** Path relative to the project root, or the absolute path when outside it. */
    public function relative(string $path): string
    {
        if (\array_key_exists($path, $this->relativeMemo)) {
            return $this->relativeMemo[$path];
        }
        $realPath = realpath($path);
        $real = $realPath ? $realPath : $path;
        $relative = str_starts_with($real, $this->realRoot.'/') ? substr($real, \strlen($this->realRoot) + 1) : $real;
        if (false !== $realPath) {
            if (\count($this->relativeMemo) >= self::RELATIVE_MEMO_CAP) {
                $this->relativeMemo = [];
            }
            $this->relativeMemo[$path] = $relative;
        }

        return $relative;
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
        return Path::getExtension($path, forceLowerCase: true);
    }

    /**
     * Whether the path's lowercased extension is one of $extensions — the
     * routing test every viewer and decoder claim hand-rolled inline.
     *
     * @param list<string> $extensions lowercase, without the dot
     */
    public static function hasExtension(string $path, array $extensions): bool
    {
        return Path::hasExtension($path, $extensions, ignoreCase: true);
    }
}
