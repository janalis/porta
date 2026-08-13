<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists recent find-in-project queries to `<root>/.porta/search-history.json`,
 * most-recent-first, deduplicated and capped, so the panel can recall them with
 * Alt+↑/↓ across sessions.
 */
final class SearchHistoryStore
{
    private const int MAX = 20;

    private readonly string $file;
    /** @var list<string> most-recent-first */
    private array $queries;

    public function __construct(
        string $rootDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/search-history.json';
        $this->queries = $this->load();
    }

    /** @return list<string> most-recent-first */
    public function all(): array
    {
        return $this->queries;
    }

    /** Record a query as the most recent (dedup, capped), and persist. */
    public function add(string $query): void
    {
        if ('' === trim($query)) {
            return;
        }
        $this->queries = array_values(array_filter($this->queries, static fn (string $q): bool => $q !== $query));
        array_unshift($this->queries, $query);
        $this->queries = \array_slice($this->queries, offset: 0, length: self::MAX);
        $this->save();
    }

    /** @return list<string> */
    private function load(): array
    {
        $data = JsonFile::read($this->file);
        if (null === $data || !\is_array($data['queries'] ?? null)) {
            return [];
        }
        /** @var list<scalar|array<mixed>|null> $entries */
        $entries = $data['queries'];
        $queries = [];
        foreach ($entries as $entry) {
            if (!(\is_string($entry) && '' !== $entry)) {
                continue;
            }

            $queries[] = $entry;
        }

        return \array_slice($queries, offset: 0, length: self::MAX);
    }

    private function save(): void
    {
        // Best-effort persistence; history is non-critical.
        JsonFile::write($this->fs, $this->file, ['queries' => $this->queries]);
    }
}
