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
    private const float POLL = 0.05;

    /** Seconds a terminated child has to exit before it is killed. */
    public const float GRACE = 2.0;

    /**
     * Terminate the child and reap it — killed outright when it has not gone
     * {@see GRACE} seconds later: a child that shrugs off a terminate would
     * otherwise outlive the IDE. With $after, the child was asked to exit and
     * has that long to do it its own way — a language server saving its
     * index — before it is terminated at all.
     *
     * @param resource $process
     */
    public static function terminate($process, float $after = 0.0): void
    {
        if ($after <= 0.0) {
            \proc_terminate($process);
        }
        self::reap($process, null, \max(0.0, $after), \max(0.0, $after) + self::GRACE);
    }

    /**
     * @param resource                   $process
     * @param (\Closure(int): void)|null $onReaped receives proc_get_status's exit code
     */
    public static function closeWhenDead($process, ?\Closure $onReaped = null): void
    {
        self::reap($process, $onReaped, null, null);
    }

    /**
     * @param resource                   $process
     * @param (\Closure(int): void)|null $onReaped receives proc_get_status's exit code
     * @param float|null                 $term     seconds before the child is terminated, when it is to be at all
     * @param float|null                 $kill     seconds before it is killed — only for one told to go
     */
    private static function reap($process, ?\Closure $onReaped, ?float $term, ?float $kill): void
    {
        $status = \proc_get_status($process);
        if (!$status['running']) {
            \proc_close($process); // the status read above already reaped it — instant
            if (null !== $onReaped) {
                $onReaped($status['exitcode']);
            }

            return;
        }
        $polls = 0;
        $timer = EventLoop::repeat(self::POLL, static function (string $timerId) use ($process, $onReaped, $term, $kill, &$polls): void {
            $status = \proc_get_status($process);
            if ($status['running']) {
                ++$polls;
                // Its own exit not made in time: told to go.
                if (null !== $term && $term > 0.0 && $polls === (int) ($term / self::POLL)) {
                    \proc_terminate($process);
                }
                // A child still here this long after its terminate is ignoring
                // it, and would outlive the IDE: it is killed, which it cannot ignore.
                if (null !== $kill && $polls === (int) ($kill / self::POLL)) {
                    \proc_terminate($process, signal: 9);
                }

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
