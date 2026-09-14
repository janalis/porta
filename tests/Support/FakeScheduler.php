<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Support\Scheduler;

/** Captures delay timers so a test fires or asserts them deterministically. */
final class FakeScheduler implements Scheduler
{
    /** @var array<string, \Closure> pending timers by id */
    public array $scheduled = [];

    /** @var array<string, float> requested delay seconds by id, kept after firing */
    public array $delays = [];

    /** @var list<string> */
    public array $cancelled = [];

    private int $nextId = 1;

    public function delay(float $seconds, \Closure $callback): string
    {
        $id = (string) $this->nextId++;
        $this->scheduled[$id] = $callback;
        $this->delays[$id] = $seconds;

        return $id;
    }

    public function cancel(string $timerId): void
    {
        $this->cancelled[] = $timerId;
        unset($this->scheduled[$timerId]);
    }

    /** Fire one pending timer (the oldest by default) as the event loop would. */
    public function fire(?string $id = null): void
    {
        $id ??= (string) \array_key_first($this->scheduled);
        $callback = $this->scheduled[$id];
        unset($this->scheduled[$id]);
        $callback();
    }
}
