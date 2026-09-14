<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * Reaps a terminated child without ever blocking the loop: `proc_close`
 * waits for the child, and a child that outlives its pipes (or shrugs off a
 * terminate) would park the whole event loop inside it. Already-dead — the
 * overwhelmingly common case, a child's pipes close when it exits — reaps
 * instantly; a straggler is polled dead on a timer. Shared by
 * {@see AsyncProcess} and {@see JsonRpcTransport}.
 */
final class ProcessReaper
{
    /**
     * @param resource                   $process
     * @param (\Closure(int): void)|null $onReaped receives proc_get_status's exit code
     */
    public static function closeWhenDead($process, ?\Closure $onReaped = null): void
    {
        $status = \proc_get_status($process);
        if (!$status['running']) {
            \proc_close($process); // the status read above already reaped it — instant
            if (null !== $onReaped) {
                $onReaped($status['exitcode']);
            }

            return;
        }
        $timer = EventLoop::repeat(0.05, static function (string $timerId) use ($process, $onReaped): void {
            $status = \proc_get_status($process);
            if ($status['running']) {
                return;
            }
            EventLoop::cancel($timerId);
            \proc_close($process);
            if (null !== $onReaped) {
                $onReaped($status['exitcode']);
            }
        });
        // Never keeps an otherwise-finished loop alive just to reap a zombie.
        EventLoop::unreference($timer);
    }
}
