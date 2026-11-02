<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Support\JsonFile;

/**
 * What a plugin declares about ITSELF, in an optional `composer.json` beside its
 * `porta.php`:
 *
 * ```json
 * {
 *     "require": { "php": ">=8.5" },
 *     "autoload": { "psr-4": { "Porta\\GraphViewer\\": "src/" } },
 *     "extra": { "porta": { "requires": ["image-viewer"] } }
 * }
 * ```
 *
 * Composer's own file, because everything this needs to say composer already
 * has a field for — and a plugin that vendors packages has one anyway, so the
 * alternative was two manifests with overlapping jobs.
 *
 * The one field with nowhere native to go is cross-plugin `requires`: those are
 * plugin DIRECTORY names, and putting them in composer's `require` would send
 * composer to Packagist for something that is not a package. `extra` is the
 * field composer guarantees to leave alone.
 *
 * `name` is read by nothing. A plugin's identity is its directory name
 * everywhere it matters — `enabledPlugins`, `disabledPlugins`, `requires`, the
 * `plugins.<name>.<key>` settings — so a composer name that disagreed would be
 * a second identity that decides nothing.
 *
 * It is read **before any of the plugin's PHP is required**, and that ordering
 * is a requirement rather than a preference: `requires` decides LOAD ORDER, so
 * it cannot itself be discovered by loading. The autoload map is registered with
 * {@see PluginAutoloader} for the same reason — a plugin's own classes must
 * resolve from the first line of its `porta.php`.
 *
 * Every field is optional, and a plugin with no manifest at all behaves exactly
 * as it did before there was one: no classes of its own, no dependencies.
 */
final readonly class PluginManifest
{
    /**
     * @param string $name the plugin's directory name, which is its identity everywhere else
     * @param array<string, list<string>> $autoload composer's `autoload.psr-4`: prefix (trailing \) => plugin-relative directories
     * @param list<string> $requires plugin names that must load, and load FIRST (`extra.porta.requires`)
     * @param list<string> $packages the INSTALLABLE composer packages it requires — `vendor/name`, never a platform requirement
     * @param bool $dumpsAutoload whether its composer.json declares an autoload mode only a dump can satisfy
     * @param array<string, string> $platform the PLATFORM requirements (`php`, `ext-*`, …) — the half {@see packages()} drops
     * @param int|null $api the plugin-API major it targets (`extra.porta.api`); null trusts the current one
     * @param array<string, string> $commands console commands it ships (`extra.porta.commands`): `porta <name>` => the command class
     * @param string $description composer's own one-liner, shown beside the plugin's name in Settings
     * @param list<ToolRequirement> $tools the external programs it runs (`extra.porta.tools`)
     */
    public function __construct(
        public string $name,
        public string $directory,
        public array $autoload = [],
        public array $requires = [],
        public array $packages = [],
        public bool $dumpsAutoload = false,
        public array $platform = [],
        public ?int $api = null,
        public array $commands = [],
        public string $description = '',
        public array $tools = [],
    ) {}

    /**
     * The absolute directories each prefix maps to — composer's own map shape,
     * so it hands straight to {@see PluginAutoloader}.
     *
     * A directory that is not there is dropped, and a prefix left with none
     * goes with it: declaring `src/` and shipping none is the same as shipping
     * no classes, which is how a manifest that is only there for `requires`
     * costs nothing.
     *
     * @return array<string, list<string>> prefix (trailing \) => existing absolute directories
     */
    public function autoloadDirs(): array
    {
        $dirs = [];
        foreach ($this->autoload as $prefix => $relatives) {
            $existing = [];
            foreach ($relatives as $relative) {
                $trimmed = \trim($relative, characters: '/');
                $dir = '' === $trimmed || '.' === $trimmed ? $this->directory : $this->directory.'/'.$trimmed;
                if (\is_dir($dir)) {
                    $existing[] = $dir;
                }
            }
            if ([] !== $existing) {
                $dirs[$prefix] = $existing;
            }
        }

        return $dirs;
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

        return \is_dir($dir) ? $dir : null;
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
        $data = null === $path ? null : JsonFile::read($path);
        $packages = \is_array($data['packages'] ?? null) ? $data['packages'] : [];

        $ours = \array_fill_keys(InstalledVersions::getInstalledPackages(), value: true);
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
     * nothing, so a plugin whose composer.json says only `php: >=8.5` has
     * nothing missing — which matters because that file is on its way to being
     * every plugin's manifest, not just a vendoring one's. A HOST-SHIPPED
     * package installs nothing either ({@see installablePackages()}), which is
     * what lets a plugin declare the symfony components it actually imports.
     */
    public function needsInstall(): bool
    {
        return ([] !== $this->installablePackages() || $this->dumpsAutoload) && null === $this->vendorDir();
    }

    /**
     * The required packages an install would actually DELIVER: {@see packages}
     * minus those Porta itself ships. Installing a host-shipped one would
     * produce exactly the vendor copy {@see shadowedPackages()} warns about —
     * a copy that is never used, because the host's autoloader prepends — so
     * declaring it asks for nothing. Self-healing by construction: if Porta
     * ever drops the package, it becomes installable here and the install
     * fires.
     *
     * @return list<string>
     */
    public function installablePackages(): array
    {
        return \array_values(\array_filter($this->packages, static fn (string $package): bool => !InstalledVersions::isInstalled($package)));
    }

    /**
     * The manifest beside $pluginFile — the defaults when there is none, and
     * also when there is one that is unreadable or malformed: a plugin that
     * worked yesterday must not vanish because its metadata has a typo, and the
     * failure is visible either way (its classes will not resolve).
     *
     * @param string|null $manifestError the decoder's reason when a composer.json exists but
     *                                   was ignored — the loader turns it into a boot warning
     */
    public static function beside(string $pluginFile, ?string &$manifestError = null): self
    {
        $directory = \dirname($pluginFile);
        $name = \basename($directory);
        $data = JsonFile::read($directory.'/composer.json', $manifestError);
        if (null === $data) {
            return new self($name, $directory);
        }
        $autoload = \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
        $facts = self::composerFacts($name, $autoload, $data);

        return new self($name, $directory, self::psr4($autoload), self::requires($data), ...$facts);
    }

    /**
     * Composer's `autoload.psr-4`, normalised to the shape the loader wants.
     *
     * The value may be a string or a list of them — composer accepts both, so
     * a manifest that used either must work. Three things are refused, each
     * for its own reason: a non-string key or directory is not a mapping at
     * all; and the EMPTY prefix (composer's fallback directory) would make
     * `str_starts_with($class, '')` true for every class in the process, so
     * that one plugin would be consulted for every unresolved name in the IDE.
     *
     * @param array<array-key, mixed> $autoload
     *
     * @return array<string, list<string>> prefix (trailing \) => plugin-relative directories
     */
    private static function psr4(array $autoload): array
    {
        $map = [];
        /** @var mixed $relatives */
        foreach (\is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $relatives) {
            if (!\is_string($prefix) || '' === \trim($prefix, characters: '\\')) {
                continue;
            }
            $directories = [];
            /** @var mixed $relative */
            foreach (\is_array($relatives) ? $relatives : [$relatives] as $relative) {
                if (!\is_string($relative)) {
                    continue;
                }
                $directories[] = $relative;
            }
            if ([] !== $directories) {
                $map[\trim($prefix, characters: '\\').'\\'] = $directories;
            }
        }

        return $map;
    }

    /**
     * The plugin names in `extra.porta.requires` — composer's own `require`
     * cannot hold them ({@see the class docblock}).
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function requires(array $data): array
    {
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $porta = \is_array($extra['porta'] ?? null) ? $extra['porta'] : [];
        $requires = [];
        /** @var mixed $required */
        foreach (\is_array($porta['requires'] ?? null) ? $porta['requires'] : [] as $required) {
            if (!\is_string($required) || '' === $required) {
                continue;
            }
            $requires[] = $required;
        }

        return $requires;
    }

    /**
     * Whether a `plugin.json` is still sitting where the manifest used to be.
     *
     * Its fields moved into composer.json and it is no longer read at all, so
     * a plugin relying on one loses its classes and its dependency order in
     * silence — the one failure this whole tier exists to prevent.
     */
    public function hasLegacyManifest(): bool
    {
        return \is_file($this->directory.'/plugin.json');
    }

    /**
     * What the manifest says about installing the plugin.
     *
     * @param array<array-key, mixed> $autoload
     * @param array<array-key, mixed> $data
     *
     * @return array{packages: list<string>, dumpsAutoload: bool, platform: array<string, string>, api: int|null, commands: array<string, string>, description: string, tools: list<ToolRequirement>}
     */
    private static function composerFacts(string $name, array $autoload, array $data): array
    {
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $description = \is_string($data['description'] ?? null) ? \trim($data['description']) : '';

        return [
            'packages' => self::packages($data),
            // `classmap` and `files` are dumped, never read from the manifest:
            // Porta reads a plugin's own psr-4 and nothing else, and everything
            // else through composer's generated maps. Declaring one is
            // therefore a request for an install, even with no dependencies.
            'dumpsAutoload' => [] !== ($autoload['classmap'] ?? []) || [] !== ($autoload['files'] ?? []),
            'platform' => self::platform($data),
            'api' => self::api($data),
            'commands' => self::commands($data),
            'description' => $description,
            'tools' => ToolRequirement::fromManifest($name, \is_array($extra['porta'] ?? null) ? $extra['porta'] : []),
        ];
    }

    /**
     * `extra.porta.commands`: a name `porta <name>` answers to, and the class
     * that answers. A name or class that is not a non-empty string is dropped.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private static function commands(array $data): array
    {
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $porta = \is_array($extra['porta'] ?? null) ? $extra['porta'] : [];
        $commands = [];
        /** @var mixed $class */
        foreach (\is_array($porta['commands'] ?? null) ? $porta['commands'] : [] as $name => $class) {
            if (!\is_string($name) || '' === $name || !\is_string($class) || '' === $class) {
                continue;
            }
            $commands[$name] = $class;
        }

        return $commands;
    }

    /**
     * The platform half of `require` — the exact complement of
     * {@see packages()}: `php`, `ext-*` and friends. Not installable, but
     * checkable, which is what {@see unmetPlatform()} does with them.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private static function platform(array $data): array
    {
        $platform = [];
        /** @var mixed $constraint */
        foreach (\is_array($data['require'] ?? null) ? $data['require'] : [] as $need => $constraint) {
            if (!\is_string($constraint) || !\is_string($need) || \str_contains($need, needle: '/')) {
                continue;
            }
            $platform[$need] = $constraint;
        }

        return $platform;
    }

    /** `extra.porta.api` — the plugin-API major the plugin targets; anything but an int reads as undeclared. */
    private static function api(array $data): ?int
    {
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $porta = \is_array($extra['porta'] ?? null) ? $extra['porta'] : [];
        /** @var mixed $api */
        $api = $porta['api'] ?? null;

        return \is_int($api) ? $api : null;
    }

    /**
     * The platform requirements this host cannot meet, as human fragments.
     *
     * Checked at load, because a plugin declaring `php: >=8.5` or `ext-gd`
     * used to load anyway and fatal on the first missing symbol, far from the
     * cause — the exact failure the rest of this tier declines by name.
     * `php-64bit`/`lib-*`/`composer-*` are not checked (none predicts a fatal
     * Porta can name), and a constraint Semver cannot parse is trusted rather
     * than refused: an unreadable manifest must degrade, never decline.
     *
     * @return list<string>
     */
    public function unmetPlatform(): array
    {
        $unmet = [];
        foreach ($this->platform as $need => $constraint) {
            if ('php' === $need && !self::phpSatisfies($constraint)) {
                $unmet[] = \sprintf('PHP %s (this is %s)', $constraint, \PHP_VERSION);

                continue;
            }
            $extension = \str_starts_with($need, 'ext-') ? \substr($need, offset: 4) : '';
            if ('' !== $extension && !\extension_loaded($extension)) {
                $unmet[] = $need;
            }
        }

        return $unmet;
    }

    private static function phpSatisfies(string $constraint): bool
    {
        try {
            return Semver::satisfies(\PHP_VERSION, $constraint);
        } catch (\UnexpectedValueException) {
            return true; // an unreadable constraint is trusted, never refused
        }
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
        foreach (\array_keys(\is_array($data['require'] ?? null) ? $data['require'] : []) as $package) {
            if (!\is_string($package) || !\str_contains($package, needle: '/')) {
                continue;
            }
            $packages[] = $package;
        }

        return $packages;
    }
}
