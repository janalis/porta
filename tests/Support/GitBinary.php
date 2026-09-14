<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Whether a real git binary is reachable — the skip guard for E2e suites
 * that drive git subprocesses. Resolution walks PORTA_TEST_PRISTINE_PATH
 * (the bootstrap's pre-mutation snapshot), never the live PATH: the answer
 * must not depend on where in a test — or in a randomized order — the call
 * happens relative to a `putenv('PATH=/nonexistent')`.
 */
trait GitBinary
{
    /** Satisfied by the host TestCase (PHPUnit's Assert::markTestSkipped). */
    abstract public static function markTestSkipped(string $message = ''): never;

    private function gitAvailable(): bool
    {
        return null !== $this->whichGit();
    }

    /** Skip the running test when no git binary is reachable. */
    private function skipWithoutGit(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git not available');
        }
    }

    private function whichGit(): ?string
    {
        foreach (\explode(\PATH_SEPARATOR, (string) \PORTA_TEST_PRISTINE_PATH) as $d) {
            if (\is_file($d.'/git') && \is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }
}
