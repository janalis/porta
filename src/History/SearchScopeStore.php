<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the saved Find-in-Files scopes to `<root>/.porta/search-scopes.json`:
 * folder searches record a **recent** entry (capped, evicted oldest-first) and
 * the tree "Add to Search Scopes" action records a **pinned** entry (never
 * evicted). Both are surfaced in the panel's Alt+S cycle after the built-in
 * kinds. Entries are keyed by label, most-recent-first.
 */
final class SearchScopeStore
{
    private const int MAX_RECENT = 10;

    private readonly string $file;
    private readonly Filesystem $fs;
    /** @var list<array{label: string, paths: list<string>, pinned: bool}> */
    private array $scopes;

    public function __construct(string $rootDir)
    {
        $this->file = $rootDir.'/.porta/search-scopes.json';
        $this->fs = new Filesystem();
        $this->scopes = $this->load();
    }

    /** @return list<array{label: string, paths: list<string>, pinned: bool}> most-recent-first */
    public function all(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $paths */
    public function addRecent(string $label, array $paths): void
    {
        $this->promote($label, $paths, pinned: false);
    }

    /** @param list<string> $paths */
    public function addPinned(string $label, array $paths): void
    {
        $this->promote($label, $paths, pinned: true);
    }

    /** @param list<string> $paths */
    private function promote(string $label, array $paths, bool $pinned): void
    {
        $existing = null;
        foreach ($this->scopes as $scope) {
            if ($scope['label'] !== $label) {
                continue;
            }

            $existing = $scope;

            break;
        }
        $this->scopes = array_values(array_filter($this->scopes, static fn (array $s): bool => $s['label'] !== $label));
        array_unshift($this->scopes, [
            'label' => $label,
            'paths' => $paths,
            'pinned' => $pinned || ($existing['pinned'] ?? false), // a pin sticks once set
        ]);
        $this->evictRecent();
        $this->save();
    }

    /** Keep every pinned entry (first) plus the most-recent {@see MAX_RECENT} unpinned ones. */
    private function evictRecent(): void
    {
        $pinned = array_values(array_filter($this->scopes, static fn (array $s): bool => $s['pinned']));
        $recent = array_values(array_filter($this->scopes, static fn (array $s): bool => !$s['pinned']));

        $this->scopes = array_merge($pinned, \array_slice($recent, offset: 0, length: self::MAX_RECENT));
    }

    /** @return list<array{label: string, paths: list<string>, pinned: bool}> */
    private function load(): array
    {
        $data = JsonFile::read($this->file);
        if (null === $data || !\is_array($data['scopes'] ?? null)) {
            return [];
        }
        /** @var list<scalar|array<mixed>|null> $entries */
        $entries = $data['scopes'];
        $scopes = [];
        foreach ($entries as $entry) {
            if (!(\is_array($entry) && \is_string($entry['label'] ?? null) && \is_array($entry['paths'] ?? null))) {
                continue;
            }

            $paths = array_values(array_filter($entry['paths'], static fn (mixed $p): bool => \is_string($p) && '' !== $p));
            if ([] !== $paths) {
                /** @var list<string> $paths */
                $scopes[] = ['label' => $entry['label'], 'paths' => $paths, 'pinned' => true === ($entry['pinned'] ?? null)];
            }
        }

        return $scopes;
    }

    private function save(): void
    {
        try {
            $this->fs->dumpFile($this->file, json_encode(['scopes' => $this->scopes], JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } catch (IOExceptionInterface) {
            // best-effort persistence; saved scopes are non-critical
            return;
        }
    }
}
