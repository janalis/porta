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

    /** What the destructor removes: the project, or the unique parent a named one sits in. */
    private readonly string $root;

    /** @var list<string> symlinks made by {@see alias()}, removed with the project */
    private array $aliases = [];

    /** @var list<string> directories {@see lock()} made unreadable, readable again before the project goes */
    private array $locked = [];

    /** The git binary, resolved once against the pristine PATH. */
    private static ?string $git = null;

    /**
     * @param array<array-key, string> $files relative path => content — a name in digits keys as an int
     * @param string|null              $name  the project directory's own name, for a screen that shows it
     *                                        (a docs screenshot); it then sits in a unique parent
     */
    public function __construct(array $files = [], string $prefix = 'porta-test-', ?string $name = null)
    {
        $this->fs = new Filesystem();
        $this->root = \sys_get_temp_dir().'/'.$prefix.(int) \getmypid().'-'.\bin2hex(\random_bytes(4));
        $this->dir = null === $name ? $this->root : $this->root.'/'.$name;
        $this->fs->mkdir($this->dir);
        foreach ($files as $path => $content) {
            $this->write((string) $path, $content);
        }
    }

    public function write(string $relativePath, string $content): string
    {
        $abs = $this->dir.'/'.$relativePath;
        $this->fs->dumpFile($abs, $content);

        return $abs;
    }

    /**
     * A directory nobody may list — a root-owned Docker volume, say — with a
     * file in it, so a walk that went in would find something. False where
     * the lock does not hold (root reads everything): the test has no premise.
     */
    public function lock(string $relativePath): bool
    {
        $dir = \dirname($this->write($relativePath.'/inside.php', "<?php\n// needle\n"));
        \chmod($dir, permissions: 0);
        $this->locked[] = $dir;

        return !\is_readable($dir);
    }

    public function path(string $relativePath = ''): string
    {
        return '' === $relativePath ? $this->dir : $this->dir.'/'.$relativePath;
    }

    /**
     * A symlink to this project's directory: a spelling of the root that
     * realpath() rewrites, the way a language server's view of the workspace
     * can differ from ours. macOS gives every temp dir one for free (/var →
     * /private/var); Linux gives none, so a test that needs one makes it.
     * Removed with the project.
     */
    public function alias(): string
    {
        $link = $this->dir.'-alias';
        \symlink($this->dir, $link);
        $this->aliases[] = $link;

        return $link;
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
        $process = new Process([self::gitBinary(), '--no-optional-locks', '-C', $this->dir, ...$args], $this->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH]);
        $process->mustRun();

        return \trim($process->getOutput());
    }

    /** git init + a deterministic identity, so `commit` never needs global config. Pass $name/$email when a test asserts on the author. */
    public function initGit(?string $branch = null, string $name = 'Porta Test', string $email = 'test@porta.dev'): void
    {
        $this->git('init', '-q', ...null !== $branch ? ['-b', $branch] : []);
        // Written into .git/config, not set by three `git config` runs: each is
        // a process, and this runs for every git fixture in the suite.
        $quote = static fn (string $value): string => '"'.\addcslashes($value, characters: '"\\').'"';
        \file_put_contents(
            $this->dir.'/.git/config',
            "[user]\n\temail = {$quote($email)}\n\tname = {$quote($name)}\n[commit]\n\tgpgsign = false\n",
            \FILE_APPEND,
        );

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
        // The hash read from commit's own summary line — `[main 1a2b3c4] msg` —
        // rather than one more process to ask rev-parse for it.
        $said = $this->git('commit', '--no-gpg-sign', '-m', $message);
        $hash = [];

        return 1 === \preg_match('/^\[.* ([0-9a-f]{7,})\]/m', $said, $hash) ? $hash[1] : $this->git('rev-parse', '--short', 'HEAD');
    }

    private static function gitBinary(): string
    {
        return self::$git ??= new ExecutableFinder()->find('git', null, \explode(\PATH_SEPARATOR, (string) \PORTA_TEST_PRISTINE_PATH)) ?? throw new \RuntimeException(
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
        foreach ($this->aliases as $link) {
            if (!\is_link($link)) {
                continue;
            }
            \unlink($link); // the link only, never its target
        }
        foreach ($this->locked as $dir) {
            \chmod($dir, permissions: 0o755);
        }
        try {
            $this->fs->remove($this->root);
        } catch (\Throwable) {
            try {
                $this->fs->remove($this->root);

                // @mago-expect lint:no-empty-catch-clause -- deliberately abandoned to /var/folders cleanup: a leftover dir is harmless, a crashed worker fails the suite.
            } catch (\Throwable) {
            }
        }
    }
}
