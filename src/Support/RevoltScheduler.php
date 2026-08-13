<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/** The production scheduler: pass-through to the shared Revolt event loop. */
final class RevoltScheduler implements Scheduler
{
    #[\Override]
    public function delay(float $seconds, \Closure $callback): string
    {
        return EventLoop::delay($seconds, $callback);
    }

    #[\Override]
    public function cancel(string $timerId): void
    {
        EventLoop::cancel($timerId);
    }
}
