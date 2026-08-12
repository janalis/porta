<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Whether a real git binary is reachable on the current PATH — the skip
 * guard for E2e suites that drive git subprocesses. Extracted with the
 * IdeEdgeCasesTest split (it also absorbed the pristine-PATH variant, which
 * was equivalent at every call site: they all run before any PATH mutation).
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
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }
}
