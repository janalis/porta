<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Widget\EditorPaneWidget;

/**
 * Every pane that can occupy the editor area, keyed by the routing id a viewer
 * claim returns. {@see BufferService} asks it what to mount, the workbench asks
 * it what to focus, sweep and status-bar, and the pointer routers ask it which
 * widgets are hit-testable panes.
 *
 * The registry lives in CORE and registrants push into it — deliberately not the
 * consumer-owned-source shape {@see ViewerProviderSource} uses. Core registers
 * its own help pane here, and doing that through a `Porta\Plugin` service would
 * be an Editor→Plugin edge pointing the wrong way; the plugin subsystem instead
 * drains its registrations in through {@see \Porta\Plugin\Runtime\PluginPanes}.
 *
 * A pane arrives with a LOADER: the closure that turns a path into the payload
 * that pane understands. That is what keeps `BufferService` from reading image
 * bytes or parsing DOT — it asks the pane's loader and carries the answer as an
 * opaque value.
 *
 * A locator member because it is otherwise reachable only by autowiring, and a
 * test that wants to see what a booted IDE registered would need a getter in
 * `src/` to get at it — the test-only API round 12 spent its time deleting.
 */
#[AsIdeService]
final class EditorPanes
{
    /** @var array<string, EditorPaneWidget> by pane id, in registration order */
    private array $panes = [];

    /** @var array<string, \Closure(string): mixed> the payload loader for each pane id */
    private array $loaders = [];

    /** @var array<string, string> pane id => the plugin that registered it ('' for core) */
    private array $owners = [];

    /**
     * @param \Closure(string): mixed $load reads $path into this pane's payload; a throw declines the file
     */
    public function register(EditorPaneWidget $pane, \Closure $load, string $owner = ''): void
    {
        $id = $pane->paneId();
        $this->panes[$id] = $pane;
        $this->loaders[$id] = $load;
        $this->owners[$id] = $owner;
    }

    /** The pane that answers to $id, or null when nothing does — the text path's cue. */
    public function paneFor(string $id): ?EditorPaneWidget
    {
        return $this->panes[$id] ?? null;
    }

    /** @return \Closure(string): mixed|null */
    public function loaderFor(string $id): ?\Closure
    {
        return $this->loaders[$id] ?? null;
    }

    /** @return list<EditorPaneWidget> every registered pane, for the sweeps that range over all of them */
    public function all(): array
    {
        return array_values($this->panes);
    }

    /**
     * Drop everything $plugin registered — the deactivate half of the
     * commit/deactivate symmetry every extension point here keeps, and what
     * makes a disabled viewer plugin's pane genuinely stop answering.
     */
    public function forget(string $plugin): void
    {
        foreach ($this->owners as $id => $owner) {
            if ($owner !== $plugin) {
                continue;
            }
            unset($this->panes[$id], $this->loaders[$id], $this->owners[$id]);
        }
    }
}
