<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Filesystem\Path;

/**
 * Turns a CLI target (a directory, a file, or the `file:line` shorthand) into a
 * {@see Workspace}: the workspace root plus the file/line to open. A file's root
 * is the nearest ancestor directory containing `composer.json` (else the file's
 * own directory), so opening a file lands you in its whole project.
 */
final class WorkspaceResolver
{
    /**
     * @throws \InvalidArgumentException when the target does not exist
     */
    public function resolve(string $target, string $cwd): Workspace
    {
        $line = null;
        $m = [];
        // Peel a trailing ":<digits>" off (`src/Foo.php:42`) only when the rest is a file.
        if (1 === \preg_match('/^(.*):(\d+)$/', $target, $m) && \is_file($this->absolute($m[1], $cwd))) {
            $target = $m[1];
            $line = (int) $m[2];
        }

        $abs = $this->absolute($target, $cwd);
        if (\is_file($abs)) {
            $real = \realpath($abs);
            $file = $real ? $real : $abs;

            return new Workspace($this->projectRoot(\dirname($file)), $file, $line);
        }
        if (\is_dir($abs)) {
            $real = \realpath($abs);

            return new Workspace($real ? $real : $abs, null, $line);
        }

        throw new \InvalidArgumentException(\sprintf('no such file or directory: %s', $target));
    }

    /** Resolve $path against $cwd unless it is already absolute (lexically canonicalized either way). */
    private function absolute(string $path, string $cwd): string
    {
        return Path::makeAbsolute($path, $cwd);
    }

    /** Nearest ancestor of $dir (inclusive) holding a composer.json, else $dir. */
    private function projectRoot(string $dir): string
    {
        $probe = $dir;
        while ('/' !== $probe && \strlen($probe) > 1) {
            if (\is_file($probe.'/composer.json')) {
                return $probe;
            }
            $probe = \dirname($probe);
        }

        return $dir;
    }
}
