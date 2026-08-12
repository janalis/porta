<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Whether a real graphviz `dot` binary is reachable on the current PATH — the
 * skip guard for the one real-binary smoke test (everything else drives the
 * committed fake-dot stubs), the {@see GitBinary} shape.
 */
trait GraphvizBinary
{
    /** Satisfied by the host TestCase (PHPUnit's Assert::markTestSkipped). */
    abstract public static function markTestSkipped(string $message = ''): never;

    /** Skip the running test when no dot binary is reachable. */
    private function skipWithoutDot(): void
    {
        if (null === $this->whichDot()) {
            self::markTestSkipped('graphviz (dot) not available');
        }
    }

    private function whichDot(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = $dir.\DIRECTORY_SEPARATOR.'dot';
            if ('' !== $dir && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
