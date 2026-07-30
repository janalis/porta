<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * The drain for plugin-contributed list tools — the {@see \Porta\Editor\PaneRegistrar}
 * shape, with one difference that matters: registrations are looked up LAZILY,
 * per call, instead of being drained once at the constructor tail. A tool has no
 * object to build (its tab is created the first time it publishes), so there is
 * nothing to construct early, and a lazy lookup is what makes a live-installed
 * plugin's tool work without a restart.
 *
 * Rows are normalised HERE, on core's side of the boundary, because plugin
 * files sit outside mago's analyzer: whatever shape a plugin's row arrives in,
 * the widget must be handed the one it renders.
 */
#[AsIdeService]
final class DockToolController
{
    public function __construct(
        private readonly DockToolSource $source,
        private readonly BottomDock $dock,
        private readonly string $rootDir,
    ) {}

    /**
     * Open (or reuse) $id's tab, show $rows and focus it — the plugin asked for
     * the user's attention.
     *
     * @param list<mixed> $rows
     */
    public function open(string $id, array $rows, ToolRun $run = ToolRun::Finished): void
    {
        $tool = $this->find($id);
        if (null === $tool) {
            return;
        }
        $tab = $this->dock->showTool(
            new DockToolHandlers($id, $tool['activate'], $tool['rerun']),
            $tool['title'],
            $this->normalize($rows),
        );
        if (ToolRun::Running === $run) {
            $this->dock->markSearching($tab);
        }
    }

    /**
     * Replace $id's rows in place: no open, no focus steal, the user's
     * selection and collapse preserved.
     *
     * A silent no-op when the tab is gone — that is the async-completion
     * staleness guard, and it is why a plugin publishes by id and never holds a
     * tab: the user may × a tool while its work is still in flight, and a late
     * answer must not resurrect it.
     *
     * @param list<mixed> $rows
     */
    public function update(string $id, array $rows, ToolRun $run = ToolRun::Finished): void
    {
        $tab = $this->dock->findTool($id);
        if (null === $tab) {
            return;
        }
        $tab->rows = $this->normalize($rows);
        $tab->searching = ToolRun::Running === $run;
        $this->dock->refreshIfBound($tab);
    }

    /** @return array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}|null */
    private function find(string $id): ?array
    {
        foreach ($this->source->listTools() as $tool) {
            if ($tool['id'] === $id) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * A row as the results panel needs it: a project-relative path, a 0-based
     * line, some text. A row missing any of the three is dropped rather than
     * rendered as a blank — and an absolute path inside the project is
     * relativised, because a plugin naturally has absolute paths in hand and
     * the panel prints what it is given.
     *
     * @param  list<mixed>                                                                                      $rows
     * @return list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}>
     */
    private function normalize(array $rows): array
    {
        $clean = [];
        /** @var mixed $row */
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['path'] ?? null) || !\is_int($row['line'] ?? null) || !\is_string($row['text'] ?? null)) {
                continue;
            }
            $clean[] = [
                'path' => str_starts_with($row['path'], '/') ? ProjectPaths::relative($this->rootDir, $row['path']) : $row['path'],
                'line' => max(0, $row['line']),
                'text' => $row['text'],
                // Match spans, in DISPLAY columns: what makes the matched
                // keyword bold. Optional, and dropped whole if it is malformed —
                // a half-decoded span would mis-highlight silently.
                'ranges' => self::ranges($row['ranges'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @return list<array{start: int, length: int}>
     */
    private static function ranges(mixed $ranges): array
    {
        if (!\is_array($ranges)) {
            return [];
        }
        $clean = [];
        /** @var mixed $range */
        foreach ($ranges as $range) {
            if (!\is_array($range) || !\is_int($range['start'] ?? null) || !\is_int($range['length'] ?? null)) {
                return [];
            }
            $clean[] = ['start' => max(0, $range['start']), 'length' => max(0, $range['length'])];
        }

        return $clean;
    }
}
