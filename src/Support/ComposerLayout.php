<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Filesystem\Path;

/**
 * Where Composer puts a project's packages and their binaries: `vendor/` and
 * `vendor/bin/` by default, or what `config.vendor-dir` and `config.bin-dir`
 * in its composer.json, or in Composer's global config.json, say. Older Symfony projects set `"bin-dir": "bin"`,
 * and a tool looked for in `vendor/bin` there is never found.
 *
 * Resolved as Composer does: the global config's (`config.json` in
 * Composer's home) beneath the project's own, relative to the project,
 * `{$vendor-dir}` in the bin dir replaced by the vendor dir, a trailing slash
 * dropped. Not read: the COMPOSER_VENDOR_DIR and COMPOSER_BIN_DIR variables,
 * which belong to the shell that runs composer, not to the IDE's; and `~`,
 * which composer.json rarely holds.
 *
 * A dir outside the project is not taken: Composer's default inside it
 * stands instead. A project's own files say where ITS tools are; one
 * naming `/usr/local/bin` would otherwise have Porta run what is there as
 * the project's, and call every brew link in it composer's to update.
 *
 * Read anew each time, never cached: a composer.json can change mid-session,
 * and the file is small.
 */
final readonly class ComposerLayout
{
    public function __construct(
        public string $vendorDir,
        public string $binDir,
    ) {}

    /**
     * The layout of the project at $root: its composer.json's config over
     * Composer's global one, and Composer's defaults where both are silent,
     * unreadable or absent.
     */
    public static function of(string $root): self
    {
        // A remote project's copy here (it holds ProjectLocation::MARKER): its
        // server's global config is not this machine's.
        $global = \is_file($root.'/.porta/remote.json') ? [] : self::config(JsonFile::read(self::home().'/config.json'));
        $project = JsonFile::read($root.'/composer.json');

        return self::from($root, [...($project ?? []), 'config' => [...$global, ...self::config($project)]]);
    }

    /**
     * Composer's home, where its global config is, found as Composer's
     * Factory::getHomeDir() finds it on Linux and macOS (Porta runs on
     * Windows through WSL): COMPOSER_HOME; else, where XDG is in use (an
     * XDG_ variable set, or /etc/xdg there), the XDG config dir's
     * `composer` when it is there; else `~/.composer`.
     */
    private static function home(): string
    {
        $set = \getenv('COMPOSER_HOME');
        if (\is_string($set) && '' !== $set) {
            return $set;
        }
        $user = \rtrim((string) \getenv('HOME'), characters: '/');
        $xdg = \getenv('XDG_CONFIG_HOME');
        $dirs = [
            ...(self::usesXdg() ? [(\is_string($xdg) && '' !== $xdg ? $xdg : $user.'/.config').'/composer'] : []),
            $user.'/.composer',
        ];

        return \array_find($dirs, static fn (string $dir): bool => \is_dir($dir)) ?? $dirs[0];
    }

    /** Whether Composer looks in the XDG config dir: an XDG_ variable is set, or /etc/xdg is there. */
    private static function usesXdg(): bool
    {
        return \array_any(\array_keys(\getenv()), static fn (string $name): bool => \str_starts_with($name, 'XDG_')) || \is_dir('/etc/xdg');
    }

    /**
     * The `config` object of a decoded composer.json, or of Composer's
     * global config.json: [] when there is none.
     *
     * @param array<mixed>|null $file
     *
     * @return array<array-key, mixed>
     */
    private static function config(?array $file): array
    {
        // @mago-expect analysis:mixed-assignment -- decoded JSON, narrowed below
        $config = $file['config'] ?? null;

        return \is_array($config) ? $config : [];
    }

    /**
     * The layout $composer, a decoded composer.json, gives the project at
     * $root — for one read from elsewhere than $root: a remote project's
     * server, whose composer.json this machine's copy may not hold.
     *
     * @param array<mixed>|null $composer null when there is none
     */
    public static function from(string $root, ?array $composer): self
    {
        // @mago-expect analysis:mixed-assignment -- decoded JSON, narrowed in text()
        $config = $composer['config'] ?? null;
        $vendor = self::inside($root, self::text($config, key: 'vendor-dir') ?? 'vendor') ?? Path::makeAbsolute('vendor', $root);
        $bin = self::inside($root, \str_replace('{$vendor-dir}', $vendor, self::text($config, key: 'bin-dir') ?? '{$vendor-dir}/bin'));

        return new self($vendor, $bin ?? $vendor.'/bin');
    }

    /** The bin dir as a message to the user names it, relative to the project at $root: `.` for the root itself. */
    public function binDirFrom(string $root): string
    {
        $relative = Path::makeRelative($this->binDir, $root);

        return '' === $relative ? '.' : $relative;
    }

    /**
     * The vendor dir as a path from $root, for a walk to leave out alongside
     * the folders it skips by name: null when skipping every `vendor` already
     * does (the default, or any dir of that name), or when it is the root.
     */
    public function vendorPath(string $root): ?string
    {
        $relative = Path::makeRelative($this->vendorDir, $root);

        return '' === $relative || 'vendor' === \basename($relative) ? null : $relative;
    }

    private static function text(mixed $config, string $key): ?string
    {
        // An offset into a string or a number is null under ??, as a missing key is.
        // @mago-expect analysis:mixed-assignment -- narrowed to a string below
        $value = $config[$key] ?? null;

        return \is_string($value) && '' !== \trim($value) ? \trim($value) : null;
    }

    /** $path made absolute against $root, or null when that leaves the project. */
    private static function inside(string $root, string $path): ?string
    {
        $absolute = Path::makeAbsolute($path, $root);

        return Path::isBasePath($root, $absolute) ? $absolute : null;
    }
}
