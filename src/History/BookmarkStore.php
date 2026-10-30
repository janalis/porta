<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists bookmarked lines to `<root>/.porta/bookmarks.json`, so the places
 * you meant to come back to are still there next launch.
 */
#[AsIdeService]
final readonly class BookmarkStore
{
    private string $file;

    public function __construct(
        private string $rootDir,
        private Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/bookmarks.json';
    }

    /** @param array<string, list<int>> $marks path => 0-based lines */
    public function save(array $marks): bool
    {
        if ([] === $marks) {
            JsonFile::removeInProject($this->fs, $this->rootDir, $this->file);

            return true;
        }

        return JsonFile::writeInProject($this->fs, $this->rootDir, $this->file, ['bookmarks' => $marks]);
    }

    /** @return array<string, list<int>> paths whose file no longer exists are dropped */
    public function load(): array
    {
        $data = JsonFile::read($this->file);
        if (!\is_array($data['bookmarks'] ?? null)) {
            return [];
        }

        $marks = [];
        $entries = $data['bookmarks'];
        foreach (\array_keys($entries) as $path) {
            if (!\is_string($path) || !\is_array($entries[$path]) || !\is_file($path)) {
                continue; // the file vanished since the last session
            }
            $clean = [];
            // Not array_values(): the walk below is keyed, so an object-shaped
            // entry (`{"1": 7}`) reads the same as a list without re-indexing.
            $lines = $entries[$path];
            foreach (\array_keys($lines) as $index) {
                if (!\is_int($lines[$index]) || $lines[$index] < 0) {
                    continue; // not a line number we could ever jump to
                }
                $clean[] = $lines[$index];
            }
            if ([] !== $clean) {
                $marks[$path] = $clean;
            }
        }

        return $marks;
    }
}
