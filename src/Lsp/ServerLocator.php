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
     * @param array<string, array{command: list<string>, name: string}> $pluginServers
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    public static function locateAll(string $rootDir, ?string $override = null, array $pluginServers = []): array
    {
        if (null !== $override && '' !== $override) {
            return ['*' => self::server(self::split($override))];
        }

        /** @var array{lsp?: string|array<array-key, mixed>}|null $config */
        $config = JsonFile::read($rootDir.'/.porta.json');
        $lsp = $config['lsp'] ?? null;
        if (\is_array($lsp) && [] !== $lsp && !array_is_list($lsp)) {
            // {languageId: command} map: each entry (a string or argv list) overrides
            // that language, but the rest keep their default (plugin or auto-detected)
            // — so configuring one language never silently unserves the others.
            $servers = self::effectiveDefaults($rootDir, $pluginServers);
            /** @var mixed $command */
            foreach ($lsp as $language => $command) {
                if (\is_string($command) && '' !== $command) {
                    $servers[(string) $language] = self::server(self::split($command));
                    continue;
                }
                if (\is_array($command) && [] !== $command) {
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

        return self::effectiveDefaults($rootDir, $pluginServers);
    }

    /**
     * The default per-language servers: core auto-detect (JS/TS, Python)
     * overlaid with the plugin-provided servers (which win, and are the only
     * source of the PHP server now).
     *
     * @param array<string, array{command: list<string>, name: string}> $pluginServers
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    private static function effectiveDefaults(string $rootDir, array $pluginServers): array
    {
        return [...self::defaults($rootDir), ...$pluginServers];
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
     * Auto-detected per-language servers, each only reached when a file of its
     * language is opened (sessions start lazily).
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    private static function defaults(string $rootDir): array
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
            $base = strtolower(basename($token));
            if (!\in_array(preg_replace('/\d+(\.\d+)*$/', replacement: '', subject: $base), ['php', 'node', 'python', ''], strict: true)) {
                return preg_replace('/\.(phar|js|php)$/', replacement: '', subject: basename($token)) ?? basename($token);
            }
        }

        return basename($command[0] ?? 'lsp');
    }

    /**
     * Tokenize a configured command string into argv, honoring quotes so a
     * server path containing spaces survives: '…' is literal, "…" allows the
     * \" and \\ escapes, and adjacent quoted/bare runs join into one token. An
     * unterminated quote runs to the end of the string — a config seam
     * degrades, it never throws.
     *
     * @return list<string>
     */
    private static function split(string $command): array
    {
        $parts = [];
        $current = '';
        $inToken = false; // tracked apart from $current, so a quoted '' still counts as an argument
        $quote = null;
        $length = \strlen($command);
        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];
            if (null !== $quote) {
                if ('"' === $quote && '\\' === $char && ($i + 1) < $length && ('"' === $command[$i + 1] || '\\' === $command[$i + 1])) {
                    $current .= $command[++$i];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                    continue;
                }
                $current .= $char;
                continue;
            }
            if (1 === preg_match('/\s/', $char)) {
                if ($inToken) {
                    $parts[] = $current;
                    $current = '';
                    $inToken = false;
                }
                continue;
            }
            if ("'" === $char || '"' === $char) {
                $quote = $char;
                $inToken = true;
                continue;
            }
            $current .= $char;
            $inToken = true;
        }
        if ($inToken) {
            $parts[] = $current;
        }

        return $parts;
    }

    private static function which(string $binary): ?string
    {
        return new ExecutableFinder()->find($binary);
    }
}
