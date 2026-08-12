<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Per-test environment isolation, the {@see LoopIsolationExtension} pattern
 * applied to the other process-global paratest reuses.
 *
 * The environment is per WORKER, not per test, so one test's
 * `putenv('PATH=/nonexistent')` or `PORTA_HOME=…` silently steers every later
 * test in that worker — and the symptom lands somewhere else entirely: a stub
 * with a `#!/usr/bin/env php` shebang fails with "env: php: No such file or
 * directory", a suite renders uncoloured because the global tempest plugin
 * stopped resolving, a boot skips the dumped container. Each reads as machine
 * load, because it depends on which tests shared a worker, and each passes in
 * isolation.
 *
 * After every test (Finished, plus the two Preparation* ends a setUp-abort
 * takes instead) this restores the snapshot taken when the worker booted, in
 * BOTH channels — `putenv`/`getenv` and `$_ENV` — because Porta reads the
 * second (`%env()%` container placeholders) while subprocess tests inherit the
 * first, and a value restored in one channel only is a subtler version of the
 * same bug.
 *
 * It also REPORTS what it had to restore, as `[env-leak]` on STDERR. The
 * restore keeps the suite deterministic; the report is what keeps the leak
 * fixable at its source, since a silent cure would make every future test free
 * to leak.
 *
 * The per-class rigs stay: {@see PreservesPath} and {@see ClearsPortaLsp}
 * document the intent at the test that needs it and run at tearDown, before
 * anything else in that class can observe the leak. This is the net beneath
 * them.
 */
final class EnvIsolationExtension implements Extension
{
    /** @var array<string, string> the worker's environment, as it was before the first test */
    private static array $pristineEnv = [];
    /** @var array<string, string> the $_ENV superglobal, likewise */
    private static array $pristineSuperglobal = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $env = getenv();
        self::$pristineEnv = $env;
        /** @var array<string, string> $superglobal */
        $superglobal = $_ENV;
        self::$pristineSuperglobal = $superglobal;

        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                EnvIsolationExtension::restore($event->test()->id());
            }
        });
        $facade->registerSubscriber(new class implements PreparationFailedSubscriber {
            public function notify(PreparationFailed $event): void
            {
                EnvIsolationExtension::restore($event->test()->id());
            }
        });
        $facade->registerSubscriber(new class implements PreparationErroredSubscriber {
            public function notify(PreparationErrored $event): void
            {
                EnvIsolationExtension::restore($event->test()->id());
            }
        });
    }

    /** Report what drifted, then hand the next test the worker's boot environment. Idempotent. */
    public static function restore(string $testId): void
    {
        $current = getenv();
        $leaked = self::drift($current, self::$pristineEnv);

        foreach (self::$pristineEnv as $name => $value) {
            if (($current[$name] ?? null) === $value) {
                continue;
            }

            putenv($name.'='.$value);
        }
        foreach (array_keys($current) as $name) {
            if (array_key_exists($name, self::$pristineEnv)) {
                continue;
            }

            putenv($name);
        }

        $_ENV = self::$pristineSuperglobal;

        if ([] !== $leaked) {
            fwrite(STDERR, \sprintf("[env-leak] %s: %s\n", $testId, implode(', ', $leaked)));
        }
    }

    /**
     * The variable names $current differs from $pristine in, added or changed
     * or removed — the report's payload, computed before anything is put back.
     *
     * @param  array<string, string> $current
     * @param  array<string, string> $pristine
     * @return list<string>
     */
    private static function drift(array $current, array $pristine): array
    {
        $names = [];
        foreach ($current as $name => $value) {
            if (($pristine[$name] ?? null) === $value) {
                continue;
            }

            $names[] = $name;
        }
        foreach (array_keys($pristine) as $name) {
            if (array_key_exists($name, $current)) {
                continue;
            }

            $names[] = $name;
        }
        sort($names);

        return $names;
    }
}
