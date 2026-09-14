<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Support\IndexMove;
use Porta\Widget\Support\ResultsPanelFields;

/**
 * The alive list-tool tabs, in creation (= strip) order — the model of truth
 * behind the dock's Results surface, mirroring {@see \Porta\Terminal\TerminalSessions}.
 * A tool instance is identified by (mode, title): re-invoking a tool reuses
 * its tab; a different title appends. A CONTRIBUTED tool is identified by its
 * {@see DockToolHandlers} id alone — the title is the plugin's to choose and
 * two plugins may pick the same one. Tabs only leave through {@see close()} —
 * nothing is ever evicted.
 */
final class DockToolTabs
{
    /** @var list<DockToolTab> creation order */
    public private(set) array $tabs = [];

    private int $active = 0;

    private int $nextId = 1;

    /** Find the (mode, title) — or, for a contributed tool, the id — tab and make it active, or append a fresh one. */
    public function openOrReuse(?ResultsPanelMode $mode, string $title, ResultsPanelFields $fields, ?DockToolHandlers $handlers = null): DockToolTab
    {
        $existing = null === $handlers ? $this->find($mode, $title) : $this->findByToolId($handlers->id);
        if (null !== $existing) {
            $existing->fields = $fields;
            $this->makeActive($existing);

            return $existing;
        }
        $tab = new DockToolTab($this->nextId++, $mode, $title, $fields, $handlers);
        $this->tabs[] = $tab;
        $this->active = \count($this->tabs) - 1;

        return $tab;
    }

    private function find(?ResultsPanelMode $mode, string $title): ?DockToolTab
    {
        foreach ($this->tabs as $tab) {
            if (null === $tab->handlers && $tab->mode === $mode && $tab->title === $title) {
                return $tab;
            }
        }

        return null;
    }

    /** The tab a contributed tool owns, or null when the user has ×'d it. */
    public function findByToolId(string $id): ?DockToolTab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->handlers?->id === $id) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * The first tab serving $mode — for the constant-title live-refresh tools.
     * Never returns a contributed tab: those carry a null mode, which no enum
     * case can equal.
     */
    public function findByMode(ResultsPanelMode $mode): ?DockToolTab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->mode === $mode) {
                return $tab;
            }
        }

        return null;
    }

    public function findById(int $id): ?DockToolTab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->id === $id) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Move the tab at $from to position $to (strip drag-reorder), keeping the
     * active pointer on the same tab.
     */
    public function move(int $from, int $to): void
    {
        $to = IndexMove::target($from, $to, \count($this->tabs));
        if (null === $to) {
            return;
        }
        $this->active = IndexMove::reindexActive($this->active, $from, $to);
        $this->tabs = IndexMove::apply($this->tabs, $from, $to);
    }

    public function active(): ?DockToolTab
    {
        return $this->tabs[$this->active] ?? null;
    }

    public function makeActive(DockToolTab $tab): void
    {
        $index = \array_search($tab, $this->tabs, strict: true);
        if (false !== $index) {
            $this->active = $index;
        }
    }

    public function contains(DockToolTab $tab): bool
    {
        return \in_array($tab, $this->tabs, strict: true);
    }

    /** Remove a tab; the active pointer moves to the nearest surviving neighbour. Returns the new active tab, or null when emptied. */
    public function close(DockToolTab $tab): ?DockToolTab
    {
        $index = \array_search($tab, $this->tabs, strict: true);
        if (false === $index) {
            return $this->active();
        }
        \array_splice($this->tabs, $index, length: 1);
        $this->active = \min($this->active <= $index ? $this->active : $this->active - 1, \count($this->tabs) - 1);

        return $this->active();
    }
}
