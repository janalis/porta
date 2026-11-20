<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The environment the user's login shell would give Porta.
 *
 * An application started from an icon inherits the desktop session's bare
 * environment: on macOS a PATH of `/usr/bin:/bin:/usr/sbin:/sbin`, where
 * Homebrew's git, composer and every language server are missing. A terminal
 * never has this problem, so this runs for a desktop launch only.
 *
 * A shell profile can hang or print anything. It runs in a session of its own
 * with no stdin, its answer is read between two sentinels, and past the
 * deadline Porta starts on the environment it already has.
 */
final readonly class LoginEnvironment
{
    private const string SENTINEL = '__PORTA_DESKTOP_ENVIRONMENT__';

    /** Who this process is, and what would change how the bundled PHP loads: a login shell overwrites neither. */
    private const string KEPT = '/^(?:PORTA_.*|TERM.*|COLORTERM|TMUX.*|WSL_.*|PWD|OLDPWD|SHLVL|_|PHPRC|PHP_INI_SCAN_DIR|LD_.*|DYLD_.*)$/D';

    /** Starts a shell in its own session: nothing it does can reach the window's terminal. */
    private const string DETACH = 'posix_setsid(); pcntl_exec($argv[1], array_slice($argv, 2));';

    /** @var \Closure(list<string>, float, string): ?string */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, float, string): ?string)|null $run runs argv from a folder within the deadline, returning its output or null
     * @param float                                         $deadline how long a shell profile may take, in seconds
     */
    public function __construct(
        ?\Closure $run = null,
        private float $deadline = 5.0,
    ) {
        $this->run = $run ?? self::runDetached(...);
    }

    /**
     * Brings a desktop launch's environment in line with the login shell. Both
     * tables are written: Symfony Process builds a child's environment from
     * `$_SERVER`, and drops a variable only `putenv()` knows.
     */
    public static function apply(): void
    {
        if (!DesktopSession::current()->active) {
            return;
        }
        foreach (new self()->resolve(\getenv()) as $name => $value) {
            \putenv($name.'='.$value);
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string> the variables to set; empty when the shell did not answer
     */
    public function resolve(array $environment): array
    {
        $shell = $environment['SHELL'] ?? '';
        $argv = [
            \is_string($shell) && \str_starts_with($shell, '/') ? $shell : '/bin/sh',
            '-i',
            '-l',
            '-c',
            \sprintf("printf '%%s' %s; /usr/bin/env -0; printf '%%s' %s", self::SENTINEL, self::SENTINEL),
        ];

        // Never from the project: a profile running a relative command would
        // run whatever an untrusted folder holds, before anyone was asked to trust it.
        $home = $environment['HOME'] ?? '';
        $folder = \is_string($home) && \str_starts_with($home, '/') && \is_dir($home) ? $home : '/';

        return self::parse(($this->run)($argv, $this->deadline, $folder) ?? '');
    }

    /** @return array<string, string> */
    private static function parse(string $output): array
    {
        $parts = \explode(self::SENTINEL, $output);
        if (3 !== \count($parts)) {
            return [];
        }
        $variables = [];
        foreach (\explode("\0", $parts[1]) as $entry) {
            $split = \strpos($entry, needle: '=');
            if (false === $split || 0 === $split) {
                continue;
            }
            $name = \substr($entry, offset: 0, length: $split);
            if (1 !== \preg_match(self::KEPT, $name)) {
                $variables[$name] = \substr($entry, $split + 1);
            }
        }

        return $variables;
    }

    /**
     * This PHP without its ini files (`-n`, so no user's php.ini reaches the bundled PHP),
     * but with the pcntl and posix the detaching shim calls: built into the bundled PHP, they
     * are modules in $extensionDir for a distribution's, which `-n` would leave out.
     *
     * @return list<string>
     */
    public static function bareProcess(string $extensionDir): array
    {
        $modules = \array_values(\array_filter(['pcntl', 'posix'], static fn (string $name): bool => \is_file($extensionDir.'/'.$name.'.so')));
        $loads = \array_merge(...\array_map(static fn (string $name): array => ['-d', 'extension='.$name], $modules));

        return [\PHP_BINARY, '-n', ...([] === $modules ? [] : ['-d', 'extension_dir='.$extensionDir, ...$loads])];
    }

    /** @param list<string> $argv */
    private static function runDetached(array $argv, float $deadline, string $folder): ?string
    {
        $process = new Process([...self::bareProcess((string) \ini_get('extension_dir')), '-r', self::DETACH, '--', ...$argv], cwd: $folder, timeout: $deadline);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $process->getOutput();
    }
}
