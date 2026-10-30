<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Porta\Support\ProjectPaths;
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
    /** @var list<array{label: string, paths: list<string>, pinned: bool}> */
    public private(set) array $scopes;

    public function __construct(
        private readonly string $rootDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/search-scopes.json';
        $this->scopes = $this->load();
    }

    /** @param list<string> $paths */
    public function addRecent(string $label, array $paths): void
    {
        $this->promote($label, $paths, ScopePersistence::Recent);
    }

    /** @param list<string> $paths */
    public function addPinned(string $label, array $paths): void
    {
        $this->promote($label, $paths, ScopePersistence::Pinned);
    }

    /** @param list<string> $paths */
    private function promote(string $label, array $paths, ScopePersistence $persistence): void
    {
        $existing = null;
        foreach ($this->scopes as $scope) {
            if ($scope['label'] !== $label) {
                continue;
            }

            $existing = $scope;

            break;
        }
        $this->scopes = \array_values(\array_filter($this->scopes, static fn (array $s): bool => $s['label'] !== $label));
        \array_unshift($this->scopes, [
            'label' => $label,
            'paths' => $paths,
            'pinned' => ScopePersistence::Pinned === $persistence || ($existing['pinned'] ?? false), // a pin sticks once set
        ]);
        $this->evictRecent();
        $this->save();
    }

    /**
     * Follow a renamed folder, in the project-relative terms this file speaks.
     *
     * Both callers make the label the path — the tree's "Add to Search Scopes"
     * and the folder search both pass the relative directory as both — so a
     * label that WAS the path moves with it. One that is anything else is the
     * user's word for the scope and is left alone.
     */
    public function rename(string $from, string $to): void
    {
        $changed = false;
        foreach ($this->scopes as $index => $scope) {
            $paths = [];
            foreach ($scope['paths'] as $path) {
                $paths[] = ProjectPaths::moved($path, $from, $to) ?? $path;
            }
            if ($paths === $scope['paths']) {
                continue;
            }
            $this->scopes[$index] = [
                'label' => $scope['label'] === $from ? $to : $scope['label'],
                'paths' => $paths,
                'pinned' => $scope['pinned'],
            ];
            $changed = true;
        }
        if ($changed) {
            $this->save();
        }
    }

    /**
     * Drop a deleted folder from every scope, and any scope left with nowhere
     * to search — a scope with no paths searches the whole project, which is
     * the opposite of what its name promises.
     */
    public function forget(string $path): void
    {
        $kept = [];
        foreach ($this->scopes as $scope) {
            $paths = [];
            foreach ($scope['paths'] as $scoped) {
                if (ProjectPaths::isUnder($scoped, $path)) {
                    continue;
                }
                $paths[] = $scoped;
            }
            if ([] === $paths) {
                continue;
            }
            $kept[] = ['label' => $scope['label'], 'paths' => $paths, 'pinned' => $scope['pinned']];
        }
        if ($kept === $this->scopes) {
            return;
        }
        $this->scopes = $kept;
        $this->save();
    }

    /** Keep every pinned entry (first) plus the most-recent {@see MAX_RECENT} unpinned ones. */
    private function evictRecent(): void
    {
        $pinned = \array_values(\array_filter($this->scopes, static fn (array $s): bool => $s['pinned']));
        $recent = \array_values(\array_filter($this->scopes, static fn (array $s): bool => !$s['pinned']));

        $this->scopes = \array_merge($pinned, \array_slice($recent, offset: 0, length: self::MAX_RECENT));
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

            $paths = \array_values(\array_filter($entry['paths'], static fn (mixed $p): bool => \is_string($p) && '' !== $p));
            if ([] !== $paths) {
                /** @var list<string> $paths */
                $scopes[] = ['label' => $entry['label'], 'paths' => $paths, 'pinned' => true === ($entry['pinned'] ?? null)];
            }
        }

        return $scopes;
    }

    private function save(): void
    {
        // Best-effort persistence; saved scopes are non-critical.
        JsonFile::writeInProject($this->fs, $this->rootDir, $this->file, ['scopes' => $this->scopes]);
    }
}
