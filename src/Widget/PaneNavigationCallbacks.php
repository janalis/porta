<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Shared "leave this pane" navigation hooks for the git and settings pane
 * widgets: Tab hands focus to the next pane, Esc leaves the view. Extracted so
 * each pane stops re-declaring the same two optional callbacks, their fluent
 * setters and the null-guarded invoker.
 */
trait PaneNavigationCallbacks
{
    /** @var (callable(): void)|null Tab: move focus to the next pane */
    private $onFocusNext;
    /** @var (callable(): void)|null Esc: leave the view */
    private $onExit;

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    /** Invoke $callback when one is set; the no-op guard the pane hooks share. */
    private function fire(?callable $callback): void
    {
        if (null !== $callback) {
            $callback();
        }
    }
}
