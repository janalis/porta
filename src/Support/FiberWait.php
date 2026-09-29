<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * Wait, inside a fiber, for something that answers through a callback — a
 * subprocess's `onDone`, a source's write — and return what it answered.
 *
 * The one trap it exists for: an operation that answers AT ONCE, from inside
 * the call that starts it (a runner with no binary to spawn, a local file
 * source). Resuming a suspension before it suspended throws, so the naive
 * `resume` in the callback / `suspend` after it breaks exactly on the fast
 * path. This suspends only when the answer has not already come.
 *
 * For a plugin's {@see \Porta\Plugin\Api\PluginApi::registerTool()} handler,
 * which runs in its own fiber, and anything else that does.
 */
final class FiberWait
{
    /**
     * @template T
     *
     * @param \Closure(\Closure(T): void): void $start starts the operation, handing it the callback to answer through
     *
     * @return T
     */
    public static function for(\Closure $start): mixed
    {
        $answered = false;
        $answer = null;
        $suspension = null;
        $start(static function (mixed $value) use (&$answered, &$answer, &$suspension): void {
            $answered = true;
            // @mago-expect analysis:mixed-assignment -- the answer is the caller's T; the template on for() is what types it
            $answer = $value;
            $suspension?->resume();
        });
        if (!$answered) {
            $suspension = EventLoop::getSuspension();
            $suspension->suspend();
        }

        /** @var T */
        return $answer;
    }
}
