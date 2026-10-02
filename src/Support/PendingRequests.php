<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * Requests sent to a child process and not answered yet, each with a
 * deadline — what the language-server and debug-adapter clients share, so
 * that nothing waits forever on a peer that wedged, died or was stopped: an
 * answer takes its request back, a deadline hands it to whoever says what
 * not answering means, and stopping hands every one back to be failed.
 *
 * @template TCallback of callable
 */
final class PendingRequests
{
    /** @var array<int, array{callback: TCallback, timer: string}> */
    private array $waiting = [];

    /**
     * Wait for the answer to $id — for $timeout seconds at most, after which
     * $expired is handed its callback.
     *
     * @param TCallback               $callback
     * @param \Closure(TCallback): void $expired
     */
    public function add(int $id, callable $callback, float $timeout, \Closure $expired): void
    {
        $timer = EventLoop::unreference(EventLoop::delay($timeout, function () use ($id, $expired): void {
            // Still waiting: an answer, or a stop, cancels this timer.
            $callback = $this->waiting[$id]['callback'];
            unset($this->waiting[$id]);
            $expired($callback);
        }));
        $this->waiting[$id] = ['callback' => $callback, 'timer' => $timer];
    }

    /**
     * The callback waiting for $id's answer — taken, its deadline cancelled
     * — or null when nothing waits for it.
     *
     * @return TCallback|null
     */
    public function take(int $id): ?callable
    {
        $pending = $this->waiting[$id] ?? null;
        if (null === $pending) {
            return null;
        }
        unset($this->waiting[$id]);
        EventLoop::cancel($pending['timer']);

        return $pending['callback'];
    }

    /**
     * Every callback still waiting, taken: the peer is gone, and each is the
     * caller's to fail.
     *
     * @return list<TCallback>
     */
    public function drain(): array
    {
        $callbacks = [];
        foreach ($this->waiting as $pending) {
            EventLoop::cancel($pending['timer']);
            $callbacks[] = $pending['callback'];
        }
        $this->waiting = [];

        return $callbacks;
    }
}
