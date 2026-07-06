<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Asynchronous git integration: current branch name and per-line change
 * marks (added / modified / deleted) computed from `git diff` against HEAD.
 *
 * Everything is best-effort and silently disabled when the project is not a
 * git working tree or the `git` binary is unavailable.
 */
final class GitInfo
{
    private readonly bool $available;
    private readonly ?string $git;

    /** @var array<int, AsyncProcess> in-flight processes */
    private array $running = [];
    private int $nextKey = 0;

    public function __construct(
        private readonly string $rootDir,
    ) {
        $this->git = self::findGit();
        $this->available = null !== $this->git && is_dir($rootDir.'/.git');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @param callable(?string): void $onDone branch name, or null when detached/unavailable
     */
    public function branch(callable $onDone): void
    {
        if (!$this->available) {
            $onDone(null);

            return;
        }

        $this->run(['rev-parse', '--abbrev-ref', 'HEAD'], function (string $out) use ($onDone): void {
            $branch = trim($out);
            $onDone('' === $branch || 'HEAD' === $branch ? null : $branch);
        });
    }

    /**
     * Compute change marks for a file relative to HEAD.
     *
     * @param callable(array<int, string>): void $onDone 0-based line => 'added'|'modified'|'deleted'
     */
    public function diff(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone([]);

            return;
        }

        $this->run(['diff', '--no-color', '--unified=0', 'HEAD', '--', $absPath], function (string $out) use ($onDone): void {
            $onDone(self::parseHunks($out));
        });
    }

    /**
     * The full unified diff of a file against HEAD (default context), as raw
     * text for a diff viewer. Empty when the file is unchanged or untracked.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function fileDiff(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone('');

            return;
        }

        $this->run(['diff', '--no-color', 'HEAD', '--', $absPath], $onDone);
    }

    /**
     * Stage a file (`git add`). Reports whether git exited cleanly.
     *
     * @param callable(bool): void $onDone
     */
    public function stage(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(false);

            return;
        }
        $this->runStatus(['add', '--', $absPath], static fn (int $code) => $onDone(0 === $code));
    }

    /**
     * Commit the index (`git commit -m`). Reports success and git's output.
     *
     * @param callable(bool, string): void $onDone
     */
    public function commit(string $message, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(false, '');

            return;
        }
        $this->runStatus(['commit', '-m', $message], static fn (int $code, string $out) => $onDone(0 === $code, trim($out)));
    }

    /**
     * Blame a single line (1-based externally, 0-based $line here).
     *
     * @param callable(array{sha: string, author: string, summary: string}|null): void $onDone
     */
    public function blameLine(string $absPath, int $line, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(null);

            return;
        }
        $n = $line + 1;
        $this->run(['blame', '-L', $n.','.$n, '--line-porcelain', '--', $absPath], static fn (string $out) => $onDone(self::parseBlameLine($out)));
    }

    /**
     * @return array{sha: string, author: string, summary: string}|null
     */
    public static function parseBlameLine(string $out): ?array
    {
        $sha = '';
        $author = '';
        $summary = '';
        foreach (explode("\n", $out) as $line) {
            $m = [];
            if (1 === preg_match('/^([0-9a-f]{40}) /', $line, $m)) {
                $sha = substr($m[1], 0, 8);
            } elseif (str_starts_with($line, 'author ')) {
                $author = substr($line, 7);
            } elseif (str_starts_with($line, 'summary ')) {
                $summary = substr($line, 8);
            }
        }

        return '' === $sha ? null : ['sha' => $sha, 'author' => $author, 'summary' => $summary];
    }

    public function cancelAll(): void
    {
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }

    /**
     * Parse `git diff --unified=0` output into per-line change marks.
     *
     * @return array<int, string> 0-based line => 'added'|'modified'|'deleted'
     */
    public static function parseHunks(string $diff): array
    {
        $marks = [];
        foreach (explode("\n", $diff) as $line) {
            $m = [];
            if (!preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                continue;
            }
            $oldCount = '' === ($m[2] ?? '') ? 1 : (int) $m[2];
            $newStart = (int) $m[3];
            $newCount = '' === ($m[4] ?? '') ? 1 : (int) $m[4];

            if (0 === $newCount) {
                // Pure deletion: flag the surviving line the removal sits on.
                $marks[max(0, $newStart - 1)] = 'deleted';
                continue;
            }

            for ($i = 0; $i < $newCount; ++$i) {
                $index = $newStart - 1 + $i;
                // Lines beyond the overlap with the old hunk are new additions;
                // the overlapping ones are modifications.
                $marks[$index] = $i < $oldCount ? 'modified' : 'added';
            }
            if (0 === $oldCount) {
                for ($i = 0; $i < $newCount; ++$i) {
                    $marks[$newStart - 1 + $i] = 'added';
                }
            }
        }

        return $marks;
    }

    /**
     * @param list<string>            $args
     * @param callable(string): void  $onDone receives stdout
     */
    private function run(array $args, callable $onDone): void
    {
        $this->runStatus($args, static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * @param list<string>                 $args
     * @param callable(int, string): void  $onDone receives (exit code, stdout)
     */
    private function runStatus(array $args, callable $onDone): void
    {
        /** @var list<string> $command */
        $command = [$this->git, '-C', $this->rootDir, ...$args];
        $key = $this->nextKey++;
        $process = new AsyncProcess($command, $this->rootDir);
        $started = $process->start(function (int $code, string $out) use ($key, $onDone): void {
            unset($this->running[$key]);
            $onDone($code, $out);
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $onDone(1, '');

            return;

            // @codeCoverageIgnoreEnd
        }

        $this->running[$key] = $process;
    }

    private static function findGit(): ?string
    {
        return new ExecutableFinder()->find('git');
    }
}
