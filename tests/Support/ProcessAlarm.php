<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Borrow the process's one SIGALRM from whoever holds it — PHPUnit's per-test
 * time limit, under `composer test` — and give it back.
 *
 * A test that arms its own alarm, or watches what the freeze watchdog does
 * with it, would otherwise see PHPUnit's pending alarm as its own, and leave
 * the rest of the test with no time limit, or with someone else's handler.
 */
final class ProcessAlarm
{
    /**
     * Run $body with no alarm pending and the default disposition, then
     * restore the handler and what was left of the time.
     *
     * @template T
     *
     * @param \Closure(): T $body
     *
     * @return T
     */
    public static function borrow(\Closure $body): mixed
    {
        $left = \pcntl_alarm(0);
        /** @var mixed $handler the stub says bool|resource; PHP returns callable|int */
        $handler = \pcntl_signal_get_handler(\SIGALRM);
        $handler = \is_int($handler) || \is_callable($handler) ? $handler : \SIG_DFL;
        \pcntl_signal(\SIGALRM, \SIG_DFL);
        try {
            return $body();
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, $handler);
            if ($left > 0) {
                \pcntl_alarm($left);
            }
        }
    }
}
