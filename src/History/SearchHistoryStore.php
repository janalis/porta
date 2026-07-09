<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
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
    private readonly Filesystem $fs;
    /** @var list<string> most-recent-first */
    private array $queries;

    public function __construct(string $rootDir)
    {
        $this->file = $rootDir.'/.porta/search-history.json';
        $this->fs = new Filesystem();
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
        $this->queries = \array_slice($this->queries, 0, self::MAX);
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
            if (\is_string($entry) && '' !== $entry) {
                $queries[] = $entry;
            }
        }

        return \array_slice($queries, 0, self::MAX);
    }

    private function save(): void
    {
        try {
            $this->fs->dumpFile($this->file, json_encode(['queries' => $this->queries], JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } catch (IOExceptionInterface) {
            // best-effort persistence; history is non-critical
        }
    }
}
