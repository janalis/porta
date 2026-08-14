<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * A single executable standing in for the `composer` binary across
 * {@see PluginInstallerTest} and the embedded-plugin E2e suite — real composer
 * is not the suites' business: network, minutes, and a resolver they do not
 * own.
 *
 * Understands three subcommands, dispatched on `$argv[1]`:
 *
 * - `install` — fabricates the `vendor/composer/autoload_psr4.php` a real
 *   `composer install --no-dev` would, in the CURRENT directory (the inner,
 *   per-plugin dependency mechanism {@see \Porta\Plugin\Lifecycle\PluginInstaller::installDependencies()}
 *   drives).
 * - `require <pkg>[:<constraint>]` — reads `$pkg`'s entry from
 *   `repositories` in the cwd's `composer.json`, if any: a `path` entry is
 *   SYMLINKED into `vendor/<pkg>` (matching real `options.symlink: true`
 *   behaviour); a `vcs` entry is `git clone`d for real — it always carries a
 *   real git URL, and only a real clone lands the repository's ACTUAL
 *   content, which a live-activation test needs; no entry at all (the
 *   Packagist-name case) materializes `vendor/<pkg>/plugin.php` +
 *   `composer.json` from $packages, since there is no real package behind
 *   it. Records the requirement in `composer.json`'s own `require`, like
 *   real composer would.
 * - `remove <pkg>` — deletes `vendor/<pkg>` (unlinking a symlink rather than
 *   recursing into its target) and drops it from `require`.
 *
 * Every subcommand exits with $exitCode without doing its normal work when
 * non-zero — a failing `composer` never leaves a landed package behind.
 */
final class FakeComposer
{
    /**
     * @param array<string, array{plugin?: string, composerJson?: string}> $packages
     *                                                                     vendor/name => what `require` should
     *                                                                     materialize for it when there is no `path`
     *                                                                     repository to symlink instead (Packagist-name
     *                                                                     or `vcs` installs)
     */
    public static function write(string $binDir, int $exitCode = 0, array $packages = []): string
    {
        if (!is_dir($binDir)) {
            mkdir($binDir, permissions: 0o777, recursive: true);
        }
        $path = $binDir.'/composer';
        file_put_contents($binDir.'/composer-recipe.json', json_encode($packages, \JSON_THROW_ON_ERROR));
        file_put_contents($path, '#!'.\PHP_BINARY.' -n'."\n<?php\n".<<<'PHP'
            $cmd = $argv[1] ?? '';
            $recipe = json_decode((string) file_get_contents(__DIR__.'/composer-recipe.json'), associative: true) ?: [];

            $rmrf = static function (string $target) use (&$rmrf): void {
                if (is_link($target) || is_file($target)) {
                    unlink($target);

                    return;
                }
                if (!is_dir($target)) {
                    return;
                }
                foreach (scandir($target) ?: [] as $entry) {
                    if ('.' !== $entry && '..' !== $entry) {
                        $rmrf($target.'/'.$entry);
                    }
                }
                rmdir($target);
            };

            if ('install' === $cmd) {
                @mkdir(getcwd().'/vendor/composer', 0o777, true);
                file_put_contents(getcwd().'/vendor/composer/autoload_psr4.php', "<?php return [];\n");
            PHP."\n                exit({$exitCode});\n".<<<'PHP'
            }

            if ('require' === $cmd) {
            PHP."\n                if (0 !== {$exitCode}) {\n                    exit({$exitCode});\n                }\n".<<<'PHP'
                $spec = $argv[2] ?? '';
                $pkg = strtok($spec, ':');
                $composerJsonPath = getcwd().'/composer.json';
                $composerJson = json_decode((string) file_get_contents($composerJsonPath), associative: true) ?: [];
                $repo = $composerJson['repositories'][$pkg] ?? null;
                $dest = getcwd().'/vendor/'.$pkg;
                @mkdir(\dirname($dest), 0o777, true);
                $rmrf($dest);
                if (\is_array($repo) && 'path' === ($repo['type'] ?? null)) {
                    symlink((string) $repo['url'], $dest);
                } elseif (\is_array($repo) && 'vcs' === ($repo['type'] ?? null)) {
                    exec('git clone --quiet -- '.escapeshellarg((string) $repo['url']).' '.escapeshellarg($dest).' 2>&1', $gitOutput, $gitCode);
                    if (0 !== $gitCode) {
                        fwrite(\STDERR, implode("\n", $gitOutput));
                        exit(1);
                    }
                } else {
                    mkdir($dest, 0o777, true);
                    file_put_contents($dest.'/plugin.php', $recipe[$pkg]['plugin'] ?? "<?php\n");
                    file_put_contents($dest.'/composer.json', $recipe[$pkg]['composerJson'] ?? json_encode(['name' => $pkg, 'type' => 'porta-plugin']));
                }
                $composerJson['require'][$pkg] = explode(':', $spec, limit: 2)[1] ?? '*';
                file_put_contents($composerJsonPath, json_encode($composerJson, \JSON_PRETTY_PRINT));
                exit(0);
            }

            if ('remove' === $cmd) {
            PHP."\n                if (0 !== {$exitCode}) {\n                    exit({$exitCode});\n                }\n".<<<'PHP'
                $pkg = $argv[2] ?? '';
                $rmrf(getcwd().'/vendor/'.$pkg);
                $composerJsonPath = getcwd().'/composer.json';
                $composerJson = json_decode((string) file_get_contents($composerJsonPath), associative: true) ?: [];
                unset($composerJson['require'][$pkg]);
                file_put_contents($composerJsonPath, json_encode($composerJson, \JSON_PRETTY_PRINT));
                exit(0);
            }

            exit(1);
            PHP."\n");
        chmod($path, permissions: 0o755);

        return $path;
    }
}
