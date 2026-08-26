<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Support\FuzzyMatcher;
use Symfony\Component\Finder\Finder;

/**
 * A lazily-built, cached index of the project's files with fuzzy search — the
 * file source shared by the go-to-file palette and the Search Everywhere modal.
 * Each candidate keeps its lowered path and basename-boundary offset precomputed
 * so per-keystroke scoring does no string prep.
 */
final class FileIndex
{
    private const int MAX_FILES = 20_000;

    /** @var list<array{path: string, lower: string, boundary: int}>|null */
    private ?array $entries = null;

    /** The "Search vendor/ files" setting; off by default — see {@see walk()}. */
    private bool $includeVendor = false;

    public function __construct(
        private readonly string $rootDir,
    ) {}

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the setting's domain, the SettingsController pattern.
    public function setIncludeVendor(bool $on): void
    {
        if ($on === $this->includeVendor) {
            return;
        }
        $this->includeVendor = $on;
        $this->entries = null; // the next query re-walks under the new scope
    }

    /** Drop the cache so the next query re-walks (files may have changed). */
    public function refresh(): void
    {
        $this->entries = null;
    }

    /** @return list<array{path: string, lower: string, boundary: int}> sorted by path */
    public function entries(): array
    {
        return $this->entries ??= $this->walk();
    }

    /**
     * Relative paths matching $query, best first, capped at $limit. An empty
     * query returns the first $limit files in sorted order.
     *
     * @return list<string>
     */
    public function search(string $query, int $limit): array
    {
        $entries = $this->entries();
        if ('' === $query) {
            return array_map(static fn (array $entry): string => $entry['path'], \array_slice($entries, offset: 0, length: $limit));
        }

        /** @var list<array{0: int, 1: string}> $scored */
        $scored = [];
        $lower = strtolower($query);
        foreach ($entries as $entry) {
            // Pre-lowered haystack (no camelCase noise on paths, by design);
            // the basename offset is the primary segment.
            $score = FuzzyMatcher::scoreLowered($entry['lower'], $lower, $entry['boundary']);
            if (null !== $score) {
                $scored[] = [$score, $entry['path']];
            }
        }
        usort($scored, static fn (array $a, array $b) => $b[0] <=> $a[0] ? $b[0] <=> $a[0] : \strlen($a[1]) <=> \strlen($b[1]));

        return array_map(static fn (array $row): string => $row[1], \array_slice($scored, offset: 0, length: $limit));
    }

    /** @return list<array{path: string, lower: string, boundary: int}> sorted by path */
    private function walk(): array
    {
        // vendor/ is excluded by default, like ProjectSearch::EXCLUDES: in a
        // PHP project it can eat the whole MAX_FILES budget by itself, so the
        // project's own files were the ones truncated away. The setting
        // re-includes it for people who go-to-file into dependencies.
        $excludes = ['.git', 'node_modules', '.idea', '.vscode'];
        if (!$this->includeVendor) {
            $excludes[] = 'vendor';
        }
        $finder = new Finder()
            ->files()
            ->in($this->rootDir)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->exclude($excludes)
            ->notName('.DS_Store');

        $paths = [];
        foreach (new \LimitIterator($finder->getIterator(), 0, self::MAX_FILES) as $file) {
            $paths[] = $file->getRelativePathname();
        }
        sort($paths);

        return array_map(static function (string $path): array {
            $lower = strtolower($path);

            return ['path' => $path, 'lower' => $lower, 'boundary' => \strlen($lower) - \strlen(basename($lower))];
        }, $paths);
    }
}
