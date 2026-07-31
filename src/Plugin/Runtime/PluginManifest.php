<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Composer\InstalledVersions;

/**
 * What a plugin declares about ITSELF, in an optional `plugin.json` beside its
 * `plugin.php`:
 *
 * ```json
 * { "namespace": "Porta\\GraphViewer", "autoload": "src", "requires": ["image-viewer"] }
 * ```
 *
 * It is read **before any of the plugin's PHP is required**, and that ordering
 * is a requirement rather than a preference: `requires` decides LOAD ORDER, so
 * it cannot itself be discovered by loading. The namespace is registered with
 * {@see PluginAutoloader} for the same reason — a plugin's own classes must
 * resolve from the first line of its `plugin.php`.
 *
 * Every field is optional, and a plugin with no manifest at all behaves exactly
 * as it did before there was one: no classes of its own, no dependencies.
 */
final readonly class PluginManifest
{
    /**
     * @param string  $name      the plugin's directory name, which is its identity everywhere else
     * @param ?string $namespace the PSR-4 prefix its classes live under, when it ships any
     * @param string  $autoload  the directory that prefix maps to, relative to the plugin
     * @param list<string> $requires plugin names that must load, and load FIRST
     * @param list<string> $packages the INSTALLABLE composer packages it requires — `vendor/name`, never a platform requirement
     * @param bool $dumpsAutoload whether its composer.json declares an autoload mode only a dump can satisfy
     */
    public function __construct(
        public string $name,
        public string $directory,
        public ?string $namespace = null,
        public string $autoload = 'src',
        public array $requires = [],
        public array $packages = [],
        public bool $dumpsAutoload = false,
    ) {}

    /** The absolute directory $namespace maps to, or null when the plugin ships no classes. */
    public function autoloadDir(): ?string
    {
        if (null === $this->namespace) {
            return null;
        }
        $dir = $this->directory.'/'.trim($this->autoload, characters: '/');

        return is_dir($dir) ? $dir : null;
    }

    /**
     * The plugin's own composer vendor directory, or null when it has none.
     *
     * Only the generated MAPS under it are ever read
     * ({@see PluginAutoloader}) — composer's autoloader is not run.
     */
    public function vendorDir(): ?string
    {
        $dir = $this->directory.'/vendor';

        return is_dir($dir) ? $dir : null;
    }

    /**
     * The names of vendored packages Porta itself ships.
     *
     * Each one is a package whose plugin copy will NEVER be used — Porta's
     * loader is ahead of the plugin map — so a plugin pinning a different major
     * of it gets Porta's version instead, and the symptom is a TypeError deep
     * in a render pass with nothing pointing at the cause. Naming them at boot
     * is the cheapest way to make that diagnosable.
     *
     * @return list<string>
     */
    public function shadowedPackages(): array
    {
        $vendorDir = $this->vendorDir();
        $path = null === $vendorDir ? null : $vendorDir.'/composer/installed.json';
        /** @var array<array-key, mixed>|scalar|null $data */
        $data = null !== $path && is_file($path) ? json_decode((string) file_get_contents($path), associative: true) : null;
        $packages = \is_array($data) && \is_array($data['packages'] ?? null) ? $data['packages'] : [];

        $ours = array_fill_keys(InstalledVersions::getInstalledPackages(), value: true);
        $shadowed = [];
        /** @var mixed $package */
        foreach ($packages as $package) {
            /** @var mixed $name */
            $name = \is_array($package) ? $package['name'] ?? null : null;
            if (\is_string($name) && \array_key_exists($name, $ours)) {
                $shadowed[] = $name;
            }
        }

        return $shadowed;
    }

    /**
     * Whether the plugin declares something an install would produce, and has
     * not installed it. Such a plugin's classes would load and then fatal on
     * the first missing symbol, somewhere far from the cause, so it is declined
     * by name at boot the way a missing `requires` entry is.
     *
     * "Declares something" is deliberately narrower than "has a composer.json".
     * A PLATFORM requirement (`php`, `ext-*`, `composer-runtime-api`) installs
     * nothing, so a plugin whose composer.json says only `php: >=8.4` has
     * nothing missing — which matters because that file is on its way to being
     * every plugin's manifest, not just a vendoring one's.
     */
    public function needsInstall(): bool
    {
        return ([] !== $this->packages || $this->dumpsAutoload) && null === $this->vendorDir();
    }

    /**
     * The manifest beside $pluginFile — the defaults when there is none, and
     * also when there is one that is unreadable or malformed: a plugin that
     * worked yesterday must not vanish because its metadata has a typo, and the
     * failure is visible either way (its classes will not resolve).
     */
    public static function beside(string $pluginFile): self
    {
        $directory = \dirname($pluginFile);
        $name = basename($directory);
        $path = $directory.'/plugin.json';
        /** @var array<array-key, mixed>|scalar|null $data */
        $data = is_file($path) ? json_decode((string) file_get_contents($path), associative: true) : null;
        if (!\is_array($data)) {
            // No plugin.json, or an unreadable one — but its composer.json is a
            // separate file with a separate question to answer, so it is read
            // either way.
            return new self($name, $directory, ...self::composerFacts($directory));
        }

        $namespace = \is_string($data['namespace'] ?? null) ? trim($data['namespace'], characters: '\\') : null;
        $autoload = \is_string($data['autoload'] ?? null) ? $data['autoload'] : 'src';
        $requires = [];
        /** @var mixed $required */
        foreach (\is_array($data['requires'] ?? null) ? $data['requires'] : [] as $required) {
            if (!\is_string($required) || '' === $required) {
                continue;
            }
            $requires[] = $required;
        }

        return new self($name, $directory, '' === $namespace ? null : $namespace, $autoload, $requires, ...self::composerFacts($directory));
    }

    /**
     * What the plugin's own `composer.json` says about installing it.
     *
     * @return array{packages: list<string>, dumpsAutoload: bool}
     */
    private static function composerFacts(string $directory): array
    {
        $path = $directory.'/composer.json';
        /** @var array<array-key, mixed>|scalar|null $data */
        $data = is_file($path) ? json_decode((string) file_get_contents($path), associative: true) : null;
        if (!\is_array($data)) {
            return ['packages' => [], 'dumpsAutoload' => false];
        }
        $autoload = \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];

        return [
            'packages' => self::packages($data),
            // `classmap` and `files` are dumped, never read from the manifest:
            // Porta reads a plugin's own psr-4 and nothing else, and everything
            // else through composer's generated maps. Declaring one is
            // therefore a request for an install, even with no dependencies.
            'dumpsAutoload' => [] !== ($autoload['classmap'] ?? []) || [] !== ($autoload['files'] ?? []),
        ];
    }

    /**
     * The INSTALLABLE packages in `require`: every requirement whose name
     * contains a `/`.
     *
     * That is composer's own discriminator, not an approximation — no platform
     * requirement has one (`php`, `php-64bit`, `ext-*`, `lib-*`, `composer`,
     * `composer-plugin-api`, `composer-runtime-api`), so the rule stays right
     * when composer invents another. `require-dev` is excluded because the
     * installer runs `--no-dev`: a dev requirement installs nothing, so
     * declining the plugin over one would be a refusal nothing can satisfy.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function packages(array $data): array
    {
        $packages = [];
        foreach (array_keys(\is_array($data['require'] ?? null) ? $data['require'] : []) as $package) {
            if (!\is_string($package) || !str_contains($package, needle: '/')) {
                continue;
            }
            $packages[] = $package;
        }

        return $packages;
    }
}
