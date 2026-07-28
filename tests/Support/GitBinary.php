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
    private function gitAvailable(): bool
    {
        return null !== $this->whichGit();
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
