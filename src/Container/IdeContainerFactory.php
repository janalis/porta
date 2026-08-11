<?php

declare(strict_types=1);

namespace Porta\Container;

use Composer\InstalledVersions;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Builds the Porta service container — the composition root for every service
 * expressible as service+scalar injection; the definitions live in
 * config/services.php (outside src/, so outside the coverage gate).
 *
 * Runtime, per-boot values (rootDir, locale, ansi, theme) travel through $_ENV
 * and resolve inside the container via %env()% placeholders — the one runtime
 * channel a dumped container supports. That makes the compiled container
 * project-independent, so it is dumped once and reused across boots: the dump
 * lands in the user cache dir, guarded by a ConfigCache in debug mode whose
 * tracked resources (services.php, the src/ glob, per-class reflection) make
 * any wiring-relevant change rebuild it automatically. Concurrent cold-cache
 * writers are safe — ConfigCache writes atomically and last-writer-wins with
 * identical content.
 *
 * Escape hatches: PORTA_CONTAINER_CACHE=off forces an in-memory build, and
 * deleting the cache dir is always safe.
 */
final class IdeContainerFactory
{
    public function build(string $rootDir, IdeConfig $config, Ansi $ansi, ?string $lspCommand = null, LspMode $lsp = LspMode::Enabled, string $docsDir = ''): ContainerInterface
    {
        // Per-boot values go through $_ENV — the env processor reads $_ENV
        // first, and unlike putenv() nothing leaks into the PTY shells spawned
        // later (PtyProcess merges getenv() into child environments). Config
        // arrays travel JSON-encoded and resolve via env()->json() args.
        $_ENV['PORTA_ROOT_DIR'] = $rootDir;
        $_ENV['PORTA_LOCALE'] = (string) ($config->settings['language'] ?? 'en');
        $_ENV['PORTA_ANSI'] = Ansi::Enabled === $ansi ? '1' : '0';
        $_ENV['PORTA_THEME'] = (string) ($config->settings['theme'] ?? 'dark');
        $_ENV['PORTA_LSP_COMMAND'] = $lspCommand ?? ''; // '' resolves to null via env(default::…)
        $_ENV['PORTA_LSP_DISABLED'] = LspMode::Disabled === $lsp ? '1' : '0';
        $_ENV['PORTA_DOCS_DIR'] = $docsDir;
        $_ENV['PORTA_DOT_PATH'] = (string) ($config->settings['dotPath'] ?? ''); // '' → auto-discover
        $_ENV['PORTA_GRAPH_RENDER'] = (string) ($config->settings['graphRender'] ?? 'bitmap');
        $_ENV['PORTA_SVG_CONVERTER'] = (string) ($config->settings['svgConverter'] ?? ''); // '' → auto-discover
        $_ENV['PORTA_PLANTUML'] = (string) ($config->settings['plantuml'] ?? ''); // '' → auto-discover
        // The per-project config arrays travel JSON-encoded through the env
        // channel (each consumer reads its own via env(json:PORTA_…)); one map,
        // one encode shape.
        $jsonEnv = [
            'PORTA_SETTINGS' => $config->settings,
            'PORTA_LANGUAGES' => $config->languages,
            'PORTA_LSP_SERVERS' => $config->lspServers,
            'PORTA_DISABLED_PLUGINS' => $config->disabledPlugins,
            'PORTA_ENABLED_PLUGINS' => $config->enabledPlugins,
            'PORTA_HIGHLIGHT' => $config->highlight,
        ];
        foreach ($jsonEnv as $key => $value) {
            $_ENV[$key] = json_encode($value, \JSON_THROW_ON_ERROR);
        }

        $cacheDir = $this->cacheDir();
        if (null === $cacheDir || 'off' === getenv('PORTA_CONTAINER_CACHE')) {
            return $this->compileFresh(CompileTarget::Direct);
        }

        // The cache dir is folded into the hash so distinct cache dirs yield
        // distinct class names (each dump loads at most once per process), and
        // the installed package set so a dependency upgrade cannot be served a
        // container dumped by the previous one.
        $hash = hash('xxh128', Ide::VERSION.\PHP_VERSION.\dirname(__DIR__, levels: 2).'/config'.$cacheDir.self::installedPackages());
        $file = $cacheDir.'/container-'.$hash.'.php';
        /** @var class-string<Container> $class The PhpDumper-generated class (declared by the require below). */
        $class = 'PortaCachedContainer_'.$hash;

        $cache = new ConfigCache($file, true);
        if (!$cache->isFresh()) {
            // Compiled for the dump: env placeholders stay unresolved so the
            // file is project-independent. A builder served directly instead
            // must be recompiled with build-time env resolution (rare paths).
            $builder = $this->compileFresh(CompileTarget::Dump);
            try {
                $dump = new PhpDumper($builder)->dump(['class' => $class]);
                \assert(\is_string($dump), description: 'PhpDumper::dump returns a string unless as_files is requested');
                $cache->write($dump, $builder->getResources());
                self::pruneStaleGenerations($cacheDir, $hash);
            } catch (IOException) {
                return $this->compileFresh(CompileTarget::Direct); // unwritable cache dir: serve this boot uncached
            }
            if (class_exists($class, false)) {
                // The stale dump is already loaded in this process and PHP
                // cannot redeclare it. Serve a direct-use build now — the
                // rewritten file benefits the next process.
                return $this->compileFresh(CompileTarget::Direct);
            }
        }

        if (!class_exists($class, false)) {
            require_once $file;
        }

        // @mago-expect analysis:unsafe-instantiation — PhpDumper generates the class with a parameterless constructor.
        return new $class();
    }

    /**
     * Every installed package and its version, sorted — the dependency half of
     * the cache key.
     *
     * `ConfigCache` tracks project files (services.php, the src/ glob, per-class
     * reflection), so a wiring change rebuilds the dump on its own; nothing
     * tracks VENDOR. Without this, `composer update` could hand a container
     * dumped by the previous dependency set to the new one — the dumper's output
     * is version-specific, and freshness would rest on vendor mtimes surviving
     * whatever the deploy did. Versions rather than references, so a dev
     * checkout does not re-dump on every commit of its own root package.
     */
    private static function installedPackages(): string
    {
        $versions = [];
        foreach (InstalledVersions::getAllRawData() as $dataset) {
            foreach ($dataset['versions'] as $name => $package) {
                $versions[] = $name.'@'.($package['version'] ?? '');
            }
        }
        sort($versions);

        return implode("\n", $versions);
    }

    /**
     * @param CompileTarget $target {@see CompileTarget::Direct} resolves %env()% placeholders at build
     *                              time from the just-seeded $_ENV (required when the builder is used
     *                              directly); {@see CompileTarget::Dump} keeps them as runtime lookups
     *                              for the dumped class
     */
    private function compileFresh(CompileTarget $target): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, levels: 2).'/config'))->load('services.php');
        $container->compile(resolveEnvPlaceholders: CompileTarget::Direct === $target);

        return $container;
    }

    /**
     * Drop the previous generations' files (dump, .meta, .meta.json) once a
     * new one is written: the hash changes on every Porta/PHP/dependency-set
     * change and nothing else ever removes the old files, so a long-lived
     * machine otherwise accumulates one generation per upgrade forever. POSIX
     * unlink is safe against a process still serving an old generation — its
     * open file lives on until that process exits.
     */
    private static function pruneStaleGenerations(string $cacheDir, string $hash): void
    {
        foreach (glob($cacheDir.'/container-*.php*') ?: [] as $file) {
            if (str_starts_with(basename($file), 'container-'.$hash.'.php')) {
                continue;
            }
            // @mago-expect lint:no-error-control-operator — best-effort GC: an unremovable sibling costs disk, not correctness.
            @unlink($file);
        }
    }

    /** The porta cache dir: $PORTA_CACHE_DIR as-is, else XDG/HOME + /porta, else null (no caching). */
    private function cacheDir(): ?string
    {
        $explicit = getenv('PORTA_CACHE_DIR');
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }
        $xdg = getenv('XDG_CACHE_HOME');
        if (\is_string($xdg) && '' !== $xdg) {
            return $xdg.'/porta';
        }
        $home = getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return $home.'/.cache/porta';
        }

        return null;
    }
}
