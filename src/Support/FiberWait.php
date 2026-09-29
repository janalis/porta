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
 * The FIRST answer wins and any later one is ignored, so an operation can be
 * raced against a timeout by handing both the same callback.
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
            // @mago-expect analysis:impossible-condition -- $answered is by reference: a SECOND call sees the first one's true
            if ($answered) {
                return;
            }
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

    /**
     * {@see for()}, given up on after $seconds: $timedOut comes back when the
     * operation has not answered by then. For an operation that may never
     * answer — a language server's request has no timeout of its own, and a
     * forge can hang — so that a caller waiting on it cannot hang with it.
     *
     * The operation is not cancelled: its late answer is simply ignored.
     *
     * @template T
     * @template U
     *
     * @param \Closure(\Closure(T): void): void $start
     * @param U                                $timedOut what a timeout answers
     *
     * @return T|U
     */
    public static function within(float $seconds, \Closure $start, mixed $timedOut): mixed
    {
        $timer = '';
        try {
            // @mago-expect lint:inline-variable-return -- the @var is what types the template answer; inlined, the analyzer sees mixed
            /** @var T|U $answer */
            $answer = self::for(static function (\Closure $done) use ($seconds, $start, $timedOut, &$timer): void {
                $timer = EventLoop::delay($seconds, static fn () => $done($timedOut));
                $start($done);
            });

            return $answer;
        } finally {
            EventLoop::cancel($timer);
        }
    }
}
