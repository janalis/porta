<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * The navigate-notification seam the two menu surfaces share: every open /
 * selection change ends in {@see afterNavigate()}, which repaints the widget
 * and pings the registered coordinator so it can re-place the floating
 * dropdown over the new geometry.
 */
trait MenuNavigation
{
    /** @var (callable(): void)|null fired whenever the open/selection state changes, so the coordinator can re-place the floating dropdown */
    private $onNavigate = null;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    public function onNavigate(callable $callback): self
    {
        $this->onNavigate = $callback;

        return $this;
    }

    private function afterNavigate(): void
    {
        $this->invalidate();
        if (null !== $this->onNavigate) {
            ($this->onNavigate)();
        }
    }
}
