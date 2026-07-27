<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The per-frame hook seam the floating presenters lay themselves out on.
 * Implemented by {@see BidiTui}; the plain vendored `Tui` has no such hook,
 * which is why the four presenters used to test `instanceof BidiTui` before
 * registering — an identical downcast in each.
 *
 * Presenters take it as a nullable dependency: null means "no frame hook
 * available", which is exactly what the old `else` branch did and what a
 * presenter driven with a bare `Tui` in tests still gets.
 */
interface FrameHooks
{
    /**
     * Install a hook fired before every frame renders, on both render paths.
     * Hooks accumulate; each must be cheap and idempotent — they run on every
     * render tick.
     *
     * @param callable(): void $hook
     */
    public function onBeforeFrame(callable $hook): void;
}
