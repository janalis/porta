<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\JsonFile;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds a PHP language server for the current project.
 *
 * Resolution order:
 *  0. an explicit $override (e.g. the `--lsp` CLI option)
 *  1. "lsp" command in the project's .porta.json
 *  2. PORTA_LSP environment variable
 *  3. vendor/bin/phpactor in the project
 *  4. phpactor on PATH
 *  5. intelephense on PATH (needs `npm i -g intelephense`)
 */
final class ServerLocator
{
    /**
     * @param string|null $override an explicit command (e.g. from `--lsp`) taking precedence over every other source
     *
     * @return array{command: list<string>, name: string}|null
     */
    public static function locate(string $rootDir, ?string $override = null): ?array
    {
        if (null !== $override && '' !== $override) {
            $command = self::split($override);

            return ['command' => $command, 'name' => self::displayName($command)];
        }

        $configFile = $rootDir.'/.porta.json';
        /** @var array{lsp?: string|list<mixed>}|null $config */
        $config = JsonFile::read($configFile);
        if (null !== $config) {
            $lsp = $config['lsp'] ?? null;
            if (\is_string($lsp) && '' !== $lsp) {
                $command = self::split($lsp);

                return ['command' => $command, 'name' => self::displayName($command)];
            }
            if (\is_array($lsp) && [] !== $lsp) {
                $command = array_values(array_map('strval', $lsp));

                return ['command' => $command, 'name' => self::displayName($command)];
            }
        }

        $env = getenv('PORTA_LSP');
        if (\is_string($env) && '' !== $env) {
            $command = self::split($env);

            return ['command' => $command, 'name' => self::displayName($command)];
        }

        $local = $rootDir.'/vendor/bin/phpactor';
        if (is_file($local) && is_executable($local)) {
            return ['command' => [$local, 'language-server'], 'name' => 'phpactor'];
        }

        if (null !== ($path = self::which('phpactor'))) {
            return ['command' => [$path, 'language-server'], 'name' => 'phpactor'];
        }

        if (null !== ($path = self::which('intelephense'))) {
            return ['command' => [$path, '--stdio'], 'name' => 'intelephense'];
        }

        return null;
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
