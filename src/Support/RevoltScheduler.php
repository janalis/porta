<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/** The production scheduler: pass-through to the shared Revolt event loop. */
final class RevoltScheduler implements Scheduler
{
    public function delay(float $seconds, \Closure $callback): string
    {
        return EventLoop::delay($seconds, $callback);
    }

    public function cancel(string $timerId): void
    {
        EventLoop::cancel($timerId);
    }
}
