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
     * Every changed/untracked file in the working tree, for the changes list.
     *
     * @param callable(list<ChangeEntry>, string): void $onDone entries plus the raw
     *                                                  porcelain output (for cheap
     *                                                  change-detection between polls)
     */
    public function status(callable $onDone): void
    {
        if (!$this->available) {
            $onDone([], '');

            return;
        }
        $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all'], static fn (string $out) => $onDone(StatusParser::parse($out), $out));
    }

    /**
     * The staged (index vs HEAD) diff of a file, for viewing/unstaging.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function cachedFileDiff(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone('');

            return;
        }
        $this->run(['diff', '--no-color', '--cached', '--', $absPath], $onDone);
    }

    /**
     * The worktree-vs-index diff of a file (what line-staging operates on).
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function worktreeFileDiff(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone('');

            return;
        }
        $this->run(['diff', '--no-color', '--', $absPath], $onDone);
    }

    /**
     * A whole-file "diff" for an untracked file (everything is an addition).
     * `--no-index` exits 1 when the files differ, which is the success case.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function untrackedFileDiff(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone('');

            return;
        }
        $this->runStatus(['diff', '--no-color', '--no-index', '--', '/dev/null', $absPath], static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * Take a file's changes out of the index (`git restore --staged`).
     *
     * @param callable(bool): void $onDone
     */
    public function unstageFile(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(false);

            return;
        }
        $this->runStatus(['restore', '--staged', '--', $absPath], static fn (int $code) => $onDone(0 === $code));
    }

    /**
     * Throw away a tracked file's worktree changes (`git restore`).
     * Untracked files are simply unlinked by the caller — no git verb needed.
     *
     * @param callable(bool): void $onDone
     */
    public function discardFile(string $absPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(false);

            return;
        }
        $this->runStatus(['restore', '--', $absPath], static fn (int $code) => $onDone(0 === $code));
    }

    /**
     * Apply a crafted partial patch to the index (`git apply --cached -`),
     * the engine behind line/hunk staging and unstaging.
     *
     * @param callable(bool): void $onDone
     */
    public function applyCached(string $patch, callable $onDone): void
    {
        if (!$this->available) {
            $onDone(false);

            return;
        }
        $this->runStatus(['apply', '--cached', '--whitespace=nowarn', '-'], static fn (int $code) => $onDone(0 === $code), stdin: $patch);
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
     * The most recent commits, for the history tab (capped; no paging).
     *
     * @param callable(list<LogEntry>): void $onDone
     */
    public function log(int $limit, callable $onDone): void
    {
        if (!$this->available) {
            $onDone([]);

            return;
        }
        $this->run(['log', '-z', '-n', (string) $limit, '--format=%H%x1f%h%x1f%an%x1f%at%x1f%s'], static fn (string $out) => $onDone(LogParser::parseLog($out)));
    }

    /**
     * The files a commit touched, with their status letters.
     *
     * @param callable(list<array{status: string, path: string}>): void $onDone
     */
    public function commitFiles(string $sha, callable $onDone): void
    {
        if (!$this->available) {
            $onDone([]);

            return;
        }
        $this->run(['show', '-z', '--name-status', '--format=', $sha], static fn (string $out) => $onDone(LogParser::parseNameStatus($out)));
    }

    /**
     * One file's patch within a commit, for the read-only history diff.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function commitFileDiff(string $sha, string $relPath, callable $onDone): void
    {
        if (!$this->available) {
            $onDone('');

            return;
        }
        $this->run(['show', '--no-color', '--format=', $sha, '--', $relPath], $onDone);
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
     * @param string|null                  $stdin  payload piped to the command
     */
    private function runStatus(array $args, callable $onDone, ?string $stdin = null): void
    {
        /** @var list<string> $command */
        $command = [$this->git, '-C', $this->rootDir, ...$args];
        $key = $this->nextKey++;
        $process = new AsyncProcess($command, $this->rootDir, $stdin);
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
