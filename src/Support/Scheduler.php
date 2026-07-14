<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The injectable seam over the shared event loop's delay timers (search
 * debounces and similar); tests inject recording fakes.
 */
interface Scheduler
{
    /** Schedule $callback after $seconds; returns the timer id for cancel(). */
    public function delay(float $seconds, \Closure $callback): string;

    public function cancel(string $timerId): void;
}
