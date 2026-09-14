<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Container\AsIdeService;

/**
 * Hydrates and saves the debugger's persistent state at the session
 * boundaries: {@see \Porta\Ide::run()} restores before the first buffer
 * opens (so the boot-time BufferSwitched pass paints the gutter dots) and
 * saves in its teardown, mirroring the SessionRestorer flow.
 */
#[AsIdeService]
final readonly class DebugStatePersistence
{
    public function __construct(
        private DebugStateStore $store,
        private BreakpointStore $breakpoints,
        private WatchStore $watches,
        private ExceptionFilterStore $filters,
    ) {}

    public function restore(): void
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

    public function save(): void
    {
        $this->store->save($this->breakpoints->all(), $this->watches->expressions, $this->filters->selection());
    }
}
