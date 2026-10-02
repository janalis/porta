<?php

declare(strict_types=1);

namespace Porta\Container;

use Composer\InstalledVersions;
use Porta\Config\IdeConfig;
use Porta\Config\KeymapPlatform;
use Porta\I18n\SystemLocale;
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
 * lands in the user cache dir. On a dev checkout the guarding ConfigCache runs
 * in debug mode, whose tracked resources (services.php, the src/ glob,
 * per-class reflection) make any wiring-relevant change rebuild the dump
 * automatically. A packaged install skips the tracking: debug isFresh()
 * autoloads and re-hashes every tracked class (~480) on each warm boot — the
 * bulk of what dumping was meant to save — and under an install only the cache
 * key's inputs (Porta version, PHP, the dependency set) can change the wiring.
 * Concurrent cold-cache writers are safe — ConfigCache writes atomically and
 * last-writer-wins with identical content.
 *
 * Escape hatches: PORTA_CONTAINER_CACHE=off forces an in-memory build,
 * PORTA_CONTAINER_CACHE=debug forces source tracking on a packaged install,
 * and deleting the cache dir is always safe.
 */
final readonly class IdeContainerFactory
{
    /**
     * @param bool|null $trackSources whether the ConfigCache tracks source freshness (debug mode);
     *                                null decides from the install: a dev checkout (or
     *                                PORTA_CONTAINER_CACHE=debug) tracks, a packaged install
     *                                trusts the cache key alone
     */
    public function __construct(
        private ?bool $trackSources = null,
    ) {}

    public function build(string $rootDir, IdeConfig $config, Ansi $ansi, ?string $lspCommand = null, LspMode $lsp = LspMode::Enabled, string $docsDir = ''): ContainerInterface
    {
        // Per-boot values go through $_ENV — the env processor reads $_ENV
        // first, and unlike putenv() nothing leaks into the PTY shells spawned
        // later (PtyProcess merges getenv() into child environments). Config
        // arrays travel JSON-encoded and resolve via env()->json() args.
        $_ENV['PORTA_ROOT_DIR'] = $rootDir;
        $_ENV['PORTA_LOCALE'] = (string) ($config->settings['language'] ?? 'auto');
        $_ENV['PORTA_SYSTEM_LOCALE'] = SystemLocale::fromEnvironment(\getenv(), \extension_loaded('intl') ? \Locale::getDefault() : '');
        $_ENV['PORTA_HOST_KEYMAP'] = KeymapPlatform::detect(\PHP_OS_FAMILY, \getenv())->value;
        $_ENV['PORTA_ANSI'] = Ansi::Enabled === $ansi ? '1' : '0';
        $_ENV['PORTA_THEME'] = (string) ($config->settings['theme'] ?? 'dark');
        $_ENV['PORTA_LSP_COMMAND'] = $lspCommand ?? ''; // '' resolves to null via env(default::…)
        $_ENV['PORTA_LSP_DISABLED'] = LspMode::Disabled === $lsp ? '1' : '0';
        $_ENV['PORTA_DOCS_DIR'] = $docsDir;
        // The translator dumps its parsed XLIFF catalogs here (the container-
        // cache argument, same dir): the first trans() of a boot DOM-parsed
        // ~100KB per catalog otherwise. '' resolves to null via env(default::…).
        $_ENV['PORTA_TRANSLATION_CACHE_DIR'] = null !== ($cache = $this->cacheDir()) ? $cache.'/translations' : '';
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
            $_ENV[$key] = \json_encode($value, \JSON_THROW_ON_ERROR);
        }

        $cacheDir = $this->cacheDir();
        if (null === $cacheDir || 'off' === \getenv('PORTA_CONTAINER_CACHE')) {
            return $this->compileFresh(CompileTarget::Direct);
        }

        // The cache dir is folded into the hash so distinct cache dirs yield
        // distinct class names (each dump loads at most once per process), and
        // the installed package set so a dependency upgrade cannot be served a
        // container dumped by the previous one. The filename carries a second,
        // install-only key so GC can tell "an old generation of THIS install"
        // from "another install sharing this per-user cache dir" — without it,
        // a dev checkout beside a release would evict each other's dump on
        // every boot and permanently defeat the cache for both.
        $installKey = \hash('xxh32', \dirname(__DIR__, levels: 2));
        $hash = \hash('xxh128', Ide::VERSION.\PHP_VERSION.\dirname(__DIR__, levels: 2).'/config'.$cacheDir.self::installedPackages());
        $file = $cacheDir.'/container-'.$installKey.'-'.$hash.'.php';
        /** @var class-string<Container> $class The PhpDumper-generated class (declared by the require below). */
        $class = 'PortaCachedContainer_'.$hash;

        $trackSources = $this->trackSources ?? 'debug' === \getenv('PORTA_CONTAINER_CACHE') || \is_dir(\dirname(__DIR__, levels: 2).'/.git');
        $cache = new ConfigCache($file, $trackSources);
        if (!$cache->isFresh()) {
            // Compiled for the dump: env placeholders stay unresolved so the
            // file is project-independent. A builder served directly instead
            // must be recompiled with build-time env resolution (rare paths).
            $builder = $this->compileFresh(CompileTarget::Dump);
            try {
                $dump = new PhpDumper($builder)->dump(['class' => $class]);
                \assert(\is_string($dump), description: 'PhpDumper::dump returns a string unless as_files is requested');
                $cache->write($dump, $trackSources ? $builder->getResources() : null);
                self::pruneStaleGenerations($cacheDir, $installKey, $hash);
            } catch (IOException) {
                return $this->compileFresh(CompileTarget::Direct); // unwritable cache dir: serve this boot uncached
            }
            if (\class_exists($class, false)) {
                // The stale dump is already loaded in this process and PHP
                // cannot redeclare it. Serve a direct-use build now — the
                // rewritten file benefits the next process.
                return $this->compileFresh(CompileTarget::Direct);
            }
        }

        if (!\class_exists($class, false)) {
            require_once $file;
        }

        // @mago-expect analysis:unsafe-instantiation -- PhpDumper generates the class with a
        // parameterless constructor. Irreducible here: Symfony's Container is not final, so the
        // warning is about a base class we do not own. Re-checked 2026-09-02.
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
        \sort($versions);

        return \implode("\n", $versions);
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
     * Drop THIS install's previous generations (dump, .meta, .meta.json) once
     * a new one is written: the hash changes on every Porta/PHP/dependency-set
     * change and nothing else ever removes the old files, so a long-lived
     * machine otherwise accumulates one generation per upgrade forever. The
     * glob is scoped by $installKey — another install's generations in the
     * shared per-user dir are its own, never this one's to evict. POSIX unlink
     * is safe against a process still serving an old generation — its open
     * file lives on until that process exits.
     */
    private static function pruneStaleGenerations(string $cacheDir, string $installKey, string $hash): void
    {
        // The pre-installKey naming (container-<32hex>.php*) predates the GC
        // itself, so those generations were never anyone's to collect and
        // strand ~0.5 MB each forever; sweep them alongside this install's own.
        $all = \glob($cacheDir.'/container-*.php*');
        $legacy = \array_filter(
            \is_array($all) ? $all : [],
            static fn (string $file): bool => 1 === \preg_match('/^container-[0-9a-f]{32}\.php(\.meta(\.json)?)?$/', \basename($file)),
        );
        $mine = \glob($cacheDir.'/container-'.$installKey.'-*.php*');
        foreach ([...(\is_array($mine) ? $mine : []), ...$legacy] as $file) {
            if (\str_starts_with(\basename($file), 'container-'.$installKey.'-'.$hash.'.php')) {
                continue;
            }
            // @mago-expect lint:no-error-control-operator — best-effort GC: an unremovable sibling costs disk, not correctness.
            @\unlink($file);
        }
    }

    /** The porta cache dir: $PORTA_CACHE_DIR as-is, else XDG/HOME + /porta, else null (no caching). */
    private function cacheDir(): ?string
    {
        $explicit = \getenv('PORTA_CACHE_DIR');
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }
        $xdg = \getenv('XDG_CACHE_HOME');
        if (\is_string($xdg) && '' !== $xdg) {
            return $xdg.'/porta';
        }
        $home = \getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return $home.'/.cache/porta';
        }

        return null;
    }
}
