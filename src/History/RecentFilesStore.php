<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;
use Porta\Support\ProjectPaths;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every file this project has had open, most-recent-first, in
 * `<root>/.porta/recent-files.json`.
 *
 * {@see \Porta\Editor\BufferManager::recent()} answers a narrower question —
 * which of the CURRENTLY OPEN buffers was focused last — and closing a tab
 * erases the file from it. PhpStorm's Recent Files list outlives the tab, which
 * is most of what makes it worth a keystroke: the file you want back is usually
 * one you just closed.
 */
#[AsIdeService]
final class RecentFilesStore
{
    /**
     * Deep enough to cover a day's work, short enough that the switcher stays a
     * list you read rather than one you search. The switcher shows the open
     * buffers first and takes what it needs from here after them.
     */
    private const int MAX = 32;

    private readonly string $file;
    /** @var list<string> absolute paths, most-recent-first */
    private array $paths;

    public function __construct(
        string $rootDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/recent-files.json';
        $this->paths = $this->load();
    }

    /** @return list<string> absolute paths, most-recent-first */
    public function all(): array
    {
        return $this->paths;
    }

    /** Record a path as the most recent (dedup, capped), and persist. */
    public function record(string $path): void
    {
        if ('' === $path) {
            return;
        }
        if (($this->paths[0] ?? null) === $path) {
            return; // already at the front: a re-show is not a visit
        }

        $this->paths = \array_values(\array_filter($this->paths, static fn (string $p): bool => $p !== $path));
        \array_unshift($this->paths, $path);
        $this->paths = \array_slice($this->paths, offset: 0, length: self::MAX);
        $this->save();
    }

    /**
     * Follow a renamed file rather than lose it.
     *
     * Without this the old path simply stops existing on disk and the switcher
     * drops it silently — the file you renamed a minute ago, which is exactly
     * the one you are most likely to want back. Bookmarks and breakpoints have
     * followed renames for as long as {@see \Porta\Event\FileRenamed} has
     * existed; this is the same obligation.
     */
    public function rename(string $from, string $to): void
    {
        // Renaming ONTO a path already in the list leaves one entry, at the
        // better of the two positions: they are the same file now.
        $renamed = [];
        foreach ($this->paths as $path) {
            $candidate = ProjectPaths::moved($path, $from, $to) ?? $path;
            if (!\in_array($candidate, $renamed, strict: true)) {
                $renamed[] = $candidate;
            }
        }
        if ($renamed === $this->paths) {
            return;
        }
        $this->paths = $renamed;
        $this->save();
    }

    /**
     * Drop a deleted file from the list, and everything under a deleted
     * directory.
     *
     * The switcher would drop the entry silently anyway the moment it checked
     * disk — but it does not check, and an entry that opens nothing is worse
     * than no entry: it takes a row in a list capped at 32 and answers a
     * keystroke with an error.
     */
    public function forget(string $path): void
    {
        $kept = \array_values(\array_filter(
            $this->paths,
            static fn (string $p): bool => !ProjectPaths::isUnder($p, $path),
        ));
        if ($kept === $this->paths) {
            return;
        }
        $this->paths = $kept;
        $this->save();
    }

    /** @return list<string> */
    private function load(): array
    {
        $data = JsonFile::read($this->file);
        if (null === $data || !\is_array($data['paths'] ?? null)) {
            return [];
        }
        /** @var list<scalar|array<mixed>|null> $entries */
        $entries = $data['paths'];
        $paths = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                continue;
            }

            $paths[] = $entry;
        }

        return \array_slice($paths, offset: 0, length: self::MAX);
    }

    private function save(): void
    {
        // Best-effort persistence; a recent-files list is non-critical.
        JsonFile::write($this->fs, $this->file, ['paths' => $this->paths]);
    }
}
