<?php

declare(strict_types=1);

namespace Porta\Container;

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
        $_ENV['PORTA_GIT_PATH'] = (string) ($config->settings['gitPath'] ?? ''); // '' → auto-discover
        $_ENV['PORTA_SETTINGS'] = json_encode($config->settings, \JSON_THROW_ON_ERROR);
        $_ENV['PORTA_LANGUAGES'] = json_encode($config->languages, \JSON_THROW_ON_ERROR);
        $_ENV['PORTA_LSP_SERVERS'] = json_encode($config->lspServers, \JSON_THROW_ON_ERROR);
        $_ENV['PORTA_DISABLED_PLUGINS'] = json_encode($config->disabledPlugins, \JSON_THROW_ON_ERROR);
        $_ENV['PORTA_ENABLED_PLUGINS'] = json_encode($config->enabledPlugins, \JSON_THROW_ON_ERROR);

        $cacheDir = $this->cacheDir();
        if (null === $cacheDir || 'off' === getenv('PORTA_CONTAINER_CACHE')) {
            return $this->compileFresh(CompileTarget::Direct);
        }

        // The cache dir is folded into the hash so distinct cache dirs yield
        // distinct class names (each dump loads at most once per process).
        $hash = hash('xxh128', Ide::VERSION.\PHP_VERSION.\dirname(__DIR__, levels: 2).'/config'.$cacheDir);
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
                \assert(\is_string($dump), description: 'PhpDumper::dump returns a string when no class array is requested');
                $cache->write($dump, $builder->getResources());
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
