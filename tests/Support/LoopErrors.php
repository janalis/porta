<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The suite-wide Revolt error handler. Ide::run() installs a production
 * handler that turns an uncaught loop-callback throw into a toast plus an
 * error.log entry and KEEPS THE LOOP ALIVE — under test that would swallow a
 * throwing await() (the timeout mechanism every async assertion leans on)
 * into an infinite worker hang. The bootstrap installs this rethrower
 * instead, and Ide::run() defers to any handler already present; a test that
 * wants the production behavior clears the handler first and restores this
 * one in its finally.
 */
final class LoopErrors
{
    public static function rethrow(): \Closure
    {
        return static function (\Throwable $error): never {
            throw $error; // fail the test loudly — Revolt surfaces this from run()
        };
    }
}
