<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Support\AsyncProcess;
use Porta\Support\Outcome;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs `git` subprocesses for the working tree at $rootDir, on the shared event
 * loop, and tracks the ones still in flight.
 *
 * This is the only mutable state in the git layer: {@see $running} and the key
 * counter that indexes it. {@see GitInfo}'s thirty-odd porcelain verbs are, once
 * this is out of the way, stateless wrappers that build an argv and parse an
 * answer — which is the reason for the split.
 */
final class GitProcessRunner
{
    private readonly ?string $git;
    private readonly bool $available;

    /** @var array<int, AsyncProcess> in-flight processes */
    private array $running = [];
    private int $nextKey = 0;

    public function __construct(
        private readonly string $rootDir,
        ?string $gitPath = null,
    ) {
        $this->git = self::findGit($gitPath);
        // file_exists, not is_dir: a linked worktree's and a submodule's .git is a
        // FILE holding `gitdir: …`. is_dir left the whole feature silently dead
        // in both — git ran nowhere and the view said "not a repository".
        $this->available = null !== $this->git && file_exists($rootDir.'/.git');
    }

    /**
     * The resolved `git` binary: the explicitly configured path when one is set,
     * else whatever discovery found, else null. Exposed so the path-resolution
     * rule is testable without reflecting on a private field.
     */
    public function binary(): ?string
    {
        return $this->git;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function cancelAll(): void
    {
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }

    /**
     * @param list<string>            $args
     * @param callable(string): void  $onDone receives stdout
     */
    public function run(array $args, callable $onDone): void
    {
        $this->runStatus($args, static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * @param list<string>                 $args
     * @param callable(int, string): void  $onDone receives (exit code, stdout)
     * @param string|null                  $stdin  payload piped to the command
     */
    public function runStatus(array $args, callable $onDone, ?string $stdin = null): void
    {
        // No git, or no repository: answer as a failed run with no output. Every
        // caller already handles that shape — the Outcome verbs read the code,
        // and every parser returns its empty value for an empty string — so this
        // is the whole availability rule, in the one place that knows it.
        if (!$this->available) {
            $empty = '';
            $onDone(1, $empty);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$this->git, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $key = $this->nextKey++;
        $process = new AsyncProcess($command, $this->rootDir, $stdin);
        $started = $process->start(function (int $code, string $out) use ($key, $onDone): void {
            unset($this->running[$key]);
            $onDone($code, $out);
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $empty = '';
            $onDone(1, $empty);

            return;

            // @codeCoverageIgnoreEnd
        }

        $this->running[$key] = $process;
    }

    /**
     * Like runStatus, but with stderr captured — for verbs whose failure
     * message the user must see verbatim (checkout, branch creation).
     *
     * @param list<string>                 $args
     * @param callable(Outcome, string): void $onDone (ok, trimmed first stderr line)
     */
    public function runWithErrors(array $args, callable $onDone): void
    {
        if (!$this->available) {
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $reason);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$this->git, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $key = $this->nextKey++;
        $process = new AsyncProcess($command, $this->rootDir, captureStderr: true);
        $started = $process->start(function (int $code) use ($key, $process, $onDone): void {
            unset($this->running[$key]);
            // @mago-expect lint:no-literal-password -- strtok's token: is a newline delimiter, not a credential
            $firstLine = strtok(trim($process->errorOutput()), token: "\n");
            $onDone(Outcome::of(0 === $code), \is_string($firstLine) ? $firstLine : '');
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $reason = 'could not start git';
            $onDone(Outcome::Failed, $reason);

            return;

            // @codeCoverageIgnoreEnd
        }
        $this->running[$key] = $process;
    }

    private static function findGit(?string $gitPath): ?string
    {
        // An explicit path (the "Git executable path" setting) wins over discovery.
        if (null !== $gitPath && '' !== $gitPath) {
            return $gitPath;
        }

        return new ExecutableFinder()->find('git');
    }
}
