<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\JsonFile;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds the language servers for the current project.
 *
 * Resolution order (see {@see locateAll()}):
 *  0. an explicit $override (e.g. the `--lsp` CLI option) — serves every language
 *  1. "lsp" in the project's .porta.json: a command string/argv (serves every
 *     language, the historical form) or a {languageId: command} map for
 *     per-language servers, e.g.
 *     {"lsp": {"php": "phpactor language-server", "typescript": "typescript-language-server --stdio"}}
 *  2. PORTA_LSP environment variable — serves every language
 *  3. auto-detected defaults, each scoped to its language: phpactor
 *     (vendor/bin, then PATH) or intelephense for PHP;
 *     typescript-language-server for JS/TS; pyright/pylsp for Python
 */
final class ServerLocator
{
    /**
     * Every configured server, keyed by LSP languageId ('*' = serves any
     * language, the single-server catch-all).
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    public static function locateAll(string $rootDir, ?string $override = null): array
    {
        if (null !== $override && '' !== $override) {
            return ['*' => self::server(self::split($override))];
        }

        /** @var array{lsp?: string|array<array-key, mixed>}|null $config */
        $config = JsonFile::read($rootDir.'/.porta.json');
        $lsp = $config['lsp'] ?? null;
        if (\is_array($lsp) && [] !== $lsp && !array_is_list($lsp)) {
            // {languageId: command} map: each entry (a string or argv list) overrides
            // that language, but the rest keep their auto-detected default — so
            // configuring one language never silently unserves the others.
            $servers = self::defaults($rootDir);
            /** @var mixed $command */
            foreach ($lsp as $language => $command) {
                if (\is_string($command) && '' !== $command) {
                    $servers[(string) $language] = self::server(self::split($command));
                } elseif (\is_array($command) && [] !== $command) {
                    $servers[(string) $language] = self::server(array_values(array_map(strval(...), $command)));
                }
            }

            return $servers;
        }

        // The historical single-server sources (config string/argv, env var)
        // keep their catch-all semantics: one server sees every file.
        if (null !== ($single = self::explicitSingle($rootDir))) {
            return ['*' => $single];
        }

        return self::defaults($rootDir);
    }

    /**
     * The explicitly configured single server: .porta.json "lsp" as a command
     * string or argv list, then the PORTA_LSP env var. Null when neither is set.
     *
     * @return array{command: list<string>, name: string}|null
     */
    private static function explicitSingle(string $rootDir): ?array
    {
        /** @var array{lsp?: string|array<array-key, mixed>}|null $config */
        $config = JsonFile::read($rootDir.'/.porta.json');
        $lsp = $config['lsp'] ?? null;
        if (\is_string($lsp) && '' !== $lsp) {
            return self::server(self::split($lsp));
        }
        if (\is_array($lsp) && [] !== $lsp && array_is_list($lsp)) {
            return self::server(array_values(array_map(strval(...), $lsp)));
        }

        $env = getenv('PORTA_LSP');
        if (\is_string($env) && '' !== $env) {
            return self::server(self::split($env));
        }

        return null;
    }

    /**
     * The auto-detected per-language servers (php/js/ts/python), for the settings
     * screen to show as the effective default when no override is configured.
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    public static function autoDetected(string $rootDir): array
    {
        return self::defaults($rootDir);
    }

    /**
     * Parse a raw command string into a server descriptor (command argv + display
     * name), for previewing a user-typed LSP command.
     *
     * @return array{command: list<string>, name: string}
     */
    public static function describe(string $command): array
    {
        return self::server(self::split($command));
    }

    /**
     * Auto-detected per-language servers, each only reached when a file of its
     * language is opened (sessions start lazily).
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    private static function defaults(string $rootDir): array
    {
        $servers = [];

        $local = $rootDir.'/vendor/bin/phpactor';
        $phpactor = self::which('phpactor');
        $intelephense = self::which('intelephense');
        if (is_file($local) && is_executable($local)) {
            $servers['php'] = ['command' => [$local, 'language-server'], 'name' => 'phpactor'];
        } elseif (null !== $phpactor) {
            $servers['php'] = ['command' => [$phpactor, 'language-server'], 'name' => 'phpactor'];
        } elseif (null !== $intelephense) {
            $servers['php'] = ['command' => [$intelephense, '--stdio'], 'name' => 'intelephense'];
        }

        $tls = self::which('typescript-language-server');
        if (null !== $tls) {
            $server = ['command' => [$tls, '--stdio'], 'name' => 'typescript-language-server'];
            $servers['javascript'] = $server;
            $servers['typescript'] = $server;
        }

        $pyright = self::which('pyright-langserver');
        $pylsp = self::which('pylsp');
        if (null !== $pyright) {
            $servers['python'] = ['command' => [$pyright, '--stdio'], 'name' => 'pyright'];
        } elseif (null !== $pylsp) {
            $servers['python'] = ['command' => [$pylsp], 'name' => 'pylsp'];
        }

        return $servers;
    }

    /**
     * @param list<string> $command
     *
     * @return array{command: list<string>, name: string}
     */
    private static function server(array $command): array
    {
        return ['command' => $command, 'name' => self::displayName($command)];
    }

    /**
     * The single catch-all server from the historical sources (override,
     * config string/argv, env var, PHP defaults chain).
     *
     * @param string|null $override an explicit command (e.g. from `--lsp`) taking precedence over every other source
     *
     * @return array{command: list<string>, name: string}|null
     */
    public static function locate(string $rootDir, ?string $override = null): ?array
    {
        if (null !== $override && '' !== $override) {
            return self::server(self::split($override));
        }

        if (null !== ($single = self::explicitSingle($rootDir))) {
            return $single;
        }

        return self::defaults($rootDir)['php'] ?? null;
    }

    /**
     * Human-readable server name: skip interpreter tokens like "php" or
     * "node" and use the actual server binary/phar name.
     *
     * @param list<string> $command
     */
    private static function displayName(array $command): string
    {
        foreach ($command as $token) {
            $base = strtolower(basename($token));
            if (!\in_array(preg_replace('/\d+(\.\d+)*$/', '', $base), ['php', 'node', 'python', ''], true)) {
                return preg_replace('/\.(phar|js|php)$/', '', basename($token)) ?? basename($token);
            }
        }

        return basename($command[0] ?? 'lsp');
    }

    /** @return list<string> */
    private static function split(string $command): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($command)) ?: []));
    }

    private static function which(string $binary): ?string
    {
        return new ExecutableFinder()->find($binary);
    }
}
