<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Support\Argv;
use Porta\Support\JsonFile;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds the language servers for the current project.
 *
 * Resolution order (see {@see locateAll()}):
 *  0. an explicit $override (e.g. the `--lsp` CLI option) — serves every language
 *  1. "lsp" in the project's .porta.json as a command string/argv — serves
 *     every language, the historical form
 *  2. "lsp" as a {languageId: command} map for per-language servers, in the
 *     global ~/.porta/config.json and the project's .porta.json, merged per
 *     language with the project's on top, e.g.
 *     {"lsp": {"php": "phpactor language-server", "typescript": "typescript-language-server --stdio"}}
 *  2b. "lsp" in the global file as a command string/argv, then the PORTA_LSP
 *     environment variable — serves every language
 *  3. plugin-provided servers, each scoped to its language (PHP comes from the
 *     phpantom/phpactor bundled plugins)
 *  4. auto-detected defaults for the remaining languages:
 *     typescript-language-server for JS/TS; pyright/pylsp for Python
 */
final class ServerLocator
{
    /**
     * Every configured server, keyed by LSP languageId ('*' = serves any
     * language, the single-server catch-all). $pluginServers are the servers
     * registered by LSP-provider plugins (php now comes from here, not a
     * hardcoded default); they sit below the user's config in precedence.
     *
     * Each language maps to a LIST, its own server first. Only plugins
     * contribute more than one: the config layers each name a single command,
     * and a `{languageId: command}` entry REPLACES that language's list —
     * naming a server by hand is a decision, and silently running someone
     * else's beside it would not be honouring it.
     *
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $pluginServers
     *
     * @return array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>
     */
    public static function locateAll(string $rootDir, ?string $override = null, array $pluginServers = []): array
    {
        if (null !== $override && '' !== $override) {
            return ['*' => [self::server(self::split($override))]];
        }

        $files = ConfigFiles::for($rootDir);
        // @mago-expect analysis:mixed-assignment -- a raw config value, narrowed by single() and map()
        $project = self::lspIn($files->path(ConfigScope::Project));
        // @mago-expect analysis:mixed-assignment -- likewise
        $global = self::lspIn($files->path(ConfigScope::Global));
        // The project's single server is its whole answer; below it, the
        // per-language maps of both files, the project's winning per language
        // — as the Settings view shows them — then the global single server.
        $single = self::single($project);
        if (null !== $single) {
            return ['*' => [$single]];
        }
        $map = [...self::map($global), ...self::map($project)];
        if ([] !== $map) {
            // {languageId: command} map: each entry (a string or argv list) overrides
            // that language, but the rest keep their default (plugin or auto-detected)
            // — so configuring one language never silently unserves the others.
            $servers = self::effectiveDefaults($pluginServers);
            // The `(string)` casts below are for the ANALYZER, not the
            // runtime: a JSON object key like "0" arrives from json_decode as
            // an int, and PHP normalises a numeric string array key back to
            // an int anyway — so dropping the cast changes nothing that runs,
            // and changes the inferred key type from string to array-key.
            // Mutating them therefore produces equivalent mutants; they are
            // not a coverage gap.
            /** @var mixed $command */
            foreach ($map as $language => $command) {
                if (\is_string($command) && '' !== $command) {
                    $servers[(string) $language] = [self::server(self::split($command))];
                    continue;
                }
                if (\is_array($command) && [] !== $command) {
                    $servers[(string) $language] = [self::server(\array_values(\array_map(strval(...), $command)))];
                }
            }

            return $servers;
        }

        // The historical single-server sources keep their catch-all
        // semantics: one server sees every file.
        $single = self::single($global) ?? self::single(\getenv('PORTA_LSP'));
        if (null !== $single) {
            return ['*' => [$single]];
        }

        return self::effectiveDefaults($pluginServers);
    }

    /** A config file's raw "lsp" value; null when the file, or the key, is absent. */
    private static function lspIn(?string $file): mixed
    {
        /** @var array{lsp?: mixed}|null $config */
        $config = null === $file ? null : JsonFile::read($file);

        return $config['lsp'] ?? null;
    }

    /**
     * An "lsp" value in its per-language map form, else empty.
     *
     * @return array<array-key, mixed>
     */
    private static function map(mixed $lsp): array
    {
        return \is_array($lsp) && !\array_is_list($lsp) ? $lsp : [];
    }

    /**
     * The default per-language servers: core auto-detect (JS/TS, Python)
     * overlaid with the plugin-provided servers (which win, and are the only
     * source of the PHP server now).
     *
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $pluginServers
     *
     * @return array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>
     */
    private static function effectiveDefaults(array $pluginServers): array
    {
        $detected = [];
        foreach (self::defaults() as $language => $server) {
            $detected[$language] = [$server];
        }

        // A plugin claim REPLACES the auto-detected one rather than joining
        // it: the detected server is a guess at what the machine has, and a
        // plugin naming one for the same language has been told.
        return [...$detected, ...$pluginServers];
    }

    /**
     * A single-server "lsp" value — a command string or argv list, or the
     * PORTA_LSP env var's string — as the server; null for anything else.
     *
     * @return array{command: list<string>, name: string}|null
     */
    private static function single(mixed $lsp): ?array
    {
        if (\is_string($lsp) && '' !== $lsp) {
            return self::server(self::split($lsp));
        }
        if (\is_array($lsp) && [] !== $lsp && \array_is_list($lsp)) {
            return self::server(\array_values(\array_map(strval(...), $lsp)));
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
        return self::defaults();
    }

    /**
     * Auto-detected per-language servers, each only reached when a file of its
     * language is opened (sessions start lazily).
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    private static function defaults(): array
    {
        // PHP is served by an LSP-provider plugin (phpantom/phpactor), not a
        // hardcoded default — see the phpantom/phpactor bundled plugins.
        $servers = [];
        $tls = self::which('typescript-language-server');
        if (null !== $tls) {
            $server = ['command' => [$tls, '--stdio'], 'name' => 'typescript-language-server'];
            $servers['javascript'] = $server;
            $servers['typescript'] = $server;
        }
        $pyright = self::which('pyright-langserver');
        $pylsp = self::which('pylsp');
        $python = match (true) {
            null !== $pyright => ['command' => [$pyright, '--stdio'], 'name' => 'pyright'],
            null !== $pylsp => ['command' => [$pylsp], 'name' => 'pylsp'],
            default => null,
        };
        if (null !== $python) {
            $servers['python'] = $python;
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
     * Human-readable server name: skip interpreter tokens like "php" or
     * "node" and use the actual server binary/phar name.
     *
     * @param list<string> $command
     */
    private static function displayName(array $command): string
    {
        foreach ($command as $token) {
            $base = \strtolower(\basename($token));
            if (!\in_array(\preg_replace('/\d+(\.\d+)*$/', replacement: '', subject: $base), ['php', 'node', 'python', ''], strict: true)) {
                return \preg_replace('/\.(phar|js|php)$/', replacement: '', subject: \basename($token)) ?? \basename($token);
            }
        }

        return \basename($command[0] ?? 'lsp');
    }

    /**
     * Tokenize a configured server command with the shared quote-aware
     * tokenizer, so a server path containing spaces survives.
     *
     * @return list<string>
     */
    private static function split(string $command): array
    {
        return Argv::split($command);
    }

    private static function which(string $binary): ?string
    {
        return new ExecutableFinder()->find($binary);
    }
}
