<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the debugger's cross-session state — breakpoints (with their
 * conditions), watch expressions and the exception-filter selection — to
 * `<root>/.porta/debug.json`. A sibling of {@see \Porta\History\SessionStore}
 * rather than a tenant of session.json: that store deletes its file when the
 * buffer list empties, which must never take breakpoints with it. Lines are
 * 0-based (the internal convention; {@see DapSession} converts at the wire).
 */
final readonly class DebugStateStore
{
    private string $file;

    public function __construct(
        string $rootDir,
        private Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/debug.json';
    }

    /**
     * @param array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>> $breakpoints
     * @param list<string>                                                                     $watches
     * @param list<string>|null                                                                $exceptionFilters null = never chosen
     */
    public function save(array $breakpoints, array $watches, ?array $exceptionFilters): bool
    {
        if ([] === $breakpoints && [] === $watches && null === $exceptionFilters) {
            $this->fs->remove($this->file);

            return true;
        }

        $data = ['breakpoints' => (object) $breakpoints, 'watches' => $watches];
        if (null !== $exceptionFilters) {
            $data['exceptionFilters'] = $exceptionFilters;
        }

        return JsonFile::write($this->fs, $this->file, $data);
    }

    /**
     * @return array{breakpoints: array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>>, watches: list<string>, exceptionFilters: ?list<string>}|null
     */
    public function load(): ?array
    {
        $data = JsonFile::read($this->file);
        if (null === $data) {
            return null;
        }

        $breakpoints = [];
        /** @var array<array-key, array<array-key, mixed>|scalar|null> $files */
        $files = \is_array($data['breakpoints'] ?? null) ? $data['breakpoints'] : [];
        foreach ($files as $path => $rawEntries) {
            if (!\is_string($path) || !\is_array($rawEntries)) {
                continue;
            }
            /** @var list<array<string, mixed>|scalar|null> $entries */
            $entries = \array_values($rawEntries);
            foreach ($entries as $entry) {
                if (!\is_array($entry) || !\is_int($entry['line'] ?? null)) {
                    continue;
                }
                $breakpoints[$path][] = [
                    'line' => $entry['line'],
                    'condition' => \is_string($entry['condition'] ?? null) ? $entry['condition'] : null,
                    'hitCondition' => \is_string($entry['hitCondition'] ?? null) ? $entry['hitCondition'] : null,
                    'logMessage' => \is_string($entry['logMessage'] ?? null) ? $entry['logMessage'] : null,
                ];
            }
        }

        $watches = [];
        /** @var list<scalar|array<array-key, mixed>|null> $rawWatches */
        $rawWatches = \is_array($data['watches'] ?? null) ? $data['watches'] : [];
        foreach ($rawWatches as $watch) {
            if (!(\is_string($watch) && '' !== $watch)) {
                continue;
            }

            $watches[] = $watch;
        }

        $filters = null;
        if (\is_array($data['exceptionFilters'] ?? null)) {
            $filters = \array_values(\array_filter($data['exceptionFilters'], static fn (mixed $id): bool => \is_string($id)));
        }

        return ['breakpoints' => $breakpoints, 'watches' => $watches, 'exceptionFilters' => $filters];
    }
}
