<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Filesystem\Path;

/**
 * Where Composer puts a project's packages and their binaries: `vendor/` and
 * `vendor/bin/` by default, or what `config.vendor-dir` and `config.bin-dir`
 * in its composer.json say. Older Symfony projects set `"bin-dir": "bin"`,
 * and a tool looked for in `vendor/bin` there is never found.
 *
 * Resolved as Composer does: relative to the project, `{$vendor-dir}` in the
 * bin dir replaced by the vendor dir, a trailing slash dropped. Not read: the
 * COMPOSER_VENDOR_DIR and COMPOSER_BIN_DIR variables, which belong to the
 * shell that runs composer, not to the IDE's; the global config in
 * COMPOSER_HOME, for the same reason; and `~`, which composer.json rarely
 * holds.
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

    /** The layout of the project at $root, Composer's defaults where its composer.json is silent, unreadable or absent. */
    public static function of(string $root): self
    {
        // @mago-expect analysis:mixed-assignment -- decoded JSON, narrowed in text()
        $config = JsonFile::read($root.'/composer.json')['config'] ?? null;
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
