<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Scratch project directory for tests; removed on destruct.
 */
final class TempProject
{
    public readonly string $dir;
    private readonly Filesystem $fs;

    /** The git binary, resolved once against the pristine PATH. */
    private static ?string $git = null;

    /** @param array<string, string> $files relative path => content */
    public function __construct(array $files = [], string $prefix = 'porta-test-')
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir().'/'.$prefix.(int) getmypid().'-'.bin2hex(random_bytes(4));
        $this->fs->mkdir($this->dir);
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
    }

    public function write(string $relativePath, string $content): string
    {
        $abs = $this->dir.'/'.$relativePath;
        $this->fs->dumpFile($abs, $content);

        return $abs;
    }

    public function path(string $relativePath = ''): string
    {
        return '' === $relativePath ? $this->dir : $this->dir.'/'.$relativePath;
    }

    /**
     * Seed the fixture by running one git command synchronously via Symfony
     * Process — `mustRun()`, so a failure throws loudly here instead of silently
     * leaving a half-built repo that flakes a later assertion. git is resolved
     * against, and run with, the pristine PATH ({@see PORTA_TEST_PRISTINE_PATH}),
     * so a test that has already set PATH=/nonexistent (to keep the Ide from
     * spawning git) can still build its repo. Returns trimmed stdout.
     */
    public function git(string ...$args): string
    {
        $process = new Process([self::gitBinary(), '--no-optional-locks', '-C', $this->dir, ...$args], $this->dir, ['PATH' => (string) PORTA_TEST_PRISTINE_PATH]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** git init + a deterministic identity, so `commit` never needs global config. Pass $name/$email when a test asserts on the author. */
    public function initGit(?string $branch = null, string $name = 'Porta Test', string $email = 'test@porta.dev'): void
    {
        $this->git('init', '-q', ...null !== $branch ? ['-b', $branch] : []);
        $this->git('config', 'user.email', $email);
        $this->git('config', 'user.name', $name);
        $this->git('config', 'commit.gpgsign', 'false');

        // `.porta/` is machine-local state (session, layout, the onboarding
        // marker). Ignore it through .git/info/exclude rather than a committed
        // .gitignore: that file is repo-local, so it adds neither an untracked
        // entry to the changes list nor a row to the file tree — both of which
        // fixtures assert on. A real project would gitignore `.porta/` instead.
        $this->write('.git/info/exclude', ".porta/\n");
    }

    /** Stage everything and commit with $message; returns the new commit's short hash. */
    public function commitAll(string $message): string
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--no-gpg-sign', '-m', $message);

        return $this->git('rev-parse', '--short', 'HEAD');
    }

    private static function gitBinary(): string
    {
        return self::$git ??= new ExecutableFinder()->find('git', null, explode(\PATH_SEPARATOR, (string) PORTA_TEST_PRISTINE_PATH)) ?? throw new \RuntimeException(
            'git not found on the pristine PATH',
        );
    }

    public function __destruct()
    {
        // macOS races the rename-then-rmdir delete against anything still
        // touching the tree (a just-reaped git subprocess, Spotlight): the
        // remove fails mid-walk ("Directory not empty" IOException, or an
        // UnexpectedValueException when the .!!xxx temp name vanishes under
        // the iterator) and the escaping exception crashes the whole paratest
        // worker. Retry once, then leave the corpse to the OS temp cleaner —
        // a leftover dir is harmless; a crashed worker fails the suite.
        try {
            $this->fs->remove($this->dir);
        } catch (\Throwable) {
            try {
                $this->fs->remove($this->dir);

                // @mago-expect lint:no-empty-catch-clause -- deliberately abandoned to /var/folders cleanup: a leftover dir is harmless, a crashed worker fails the suite.
            } catch (\Throwable) {
            }
        }
    }
}
