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
        $this->dir = sys_get_temp_dir().'/'.$prefix.getmypid().'-'.bin2hex(random_bytes(4));
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
        $process = new Process([self::gitBinary(), '-C', $this->dir, ...$args], $this->dir, ['PATH' => PORTA_TEST_PRISTINE_PATH]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** git init + a deterministic identity, so `commit` never needs global config. */
    public function initGit(): void
    {
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@porta.dev');
        $this->git('config', 'user.name', 'Porta Test');
        $this->git('config', 'commit.gpgsign', 'false');
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
        return self::$git ??= new ExecutableFinder()->find('git', null, explode(\PATH_SEPARATOR, PORTA_TEST_PRISTINE_PATH)) ?? throw new \RuntimeException('git not found on the pristine PATH');
    }

    public function __destruct()
    {
        $this->fs->remove($this->dir);
    }
}
