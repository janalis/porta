<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Container\AsIdeService;
use Porta\Support\ThreeWay;

/**
 * Hydrates and saves the debugger's persistent state at the session
 * boundaries: {@see \Porta\Ide::run()} restores before the first buffer
 * opens (so the boot-time BufferSwitched pass paints the gutter dots) and
 * saves in its teardown, mirroring the SessionRestorer flow.
 */
#[AsIdeService]
final class DebugStatePersistence
{
    /**
     * What this window restored, in the shape it saves: the base its changes
     * are told from when another window on the project wrote the file since.
     *
     * @var array{0: array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>>, 1: list<string>, 2: ?list<string>}
     */
    private array $loaded = [[], [], null];

    public function __construct(
        private readonly DebugStateStore $store,
        private readonly BreakpointStore $breakpoints,
        private readonly WatchStore $watches,
        private readonly ExceptionFilterStore $filters,
    ) {}

    public function restore(): void
    {
        $this->hydrate();
        $this->loaded = $this->now();
    }

    private function hydrate(): void
    {
        $state = $this->store->load();
        if (null === $state) {
            return;
        }
        foreach ($state['breakpoints'] as $path => $entries) {
            if (!\is_file($path)) {
                continue; // the file vanished since the last session
            }
            foreach ($entries as $entry) {
                $this->breakpoints->setDetails($path, $entry['line'], $entry['condition'], $entry['hitCondition'], $entry['logMessage'] ?? null);
            }
        }
        foreach ($state['watches'] as $expression) {
            $this->watches->add($expression);
        }
        $this->filters->setSelection($state['exceptionFilters']);
    }

    /**
     * Save this window's changes over the file as it is now
     * ({@see ThreeWay}): breakpoints by their line, watches by their
     * expression, the exception filters only if this window chose them.
     * Written whole, an idle second window deleted every breakpoint the first
     * had set.
     */
    public function save(): void
    {
        [$breakpoints, $watches, $filters] = $this->now();
        [$loadedBreakpoints, $loadedWatches, $loadedFilters] = $this->loaded;
        $disk = $this->store->load() ?? ['breakpoints' => [], 'watches' => [], 'exceptionFilters' => null];
        $merged = [];
        foreach (\array_keys($disk['breakpoints'] + $breakpoints + $loadedBreakpoints) as $path) {
            if (!\is_file($path)) {
                continue; // the file vanished: gone for good, as restore() reads it
            }
            $entries = ThreeWay::merge(self::byLine($disk['breakpoints'][$path] ?? []), self::byLine($loadedBreakpoints[$path] ?? []), self::byLine($breakpoints[$path] ?? []));
            \ksort($entries); // ascending, the store's and the DAP payload's order
            if ([] !== $entries) {
                $merged[$path] = \array_values($entries);
            }
        }
        $expressions = \array_keys(ThreeWay::merge(\array_fill_keys($disk['watches'], value: true), \array_fill_keys($loadedWatches, value: true), \array_fill_keys($watches, value: true)));
        $this->store->save($merged, \array_map(\strval(...), $expressions), $filters === $loadedFilters ? $disk['exceptionFilters'] : $filters);
    }

    /** @return array{0: array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>>, 1: list<string>, 2: ?list<string>} */
    private function now(): array
    {
        return [$this->breakpoints->all(), $this->watches->expressions, $this->filters->selection()];
    }

    /**
     * @param list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}> $entries
     *
     * @return array<int, array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>
     */
    private static function byLine(array $entries): array
    {
        return \array_column($entries, column_key: null, index_key: 'line');
    }
}
