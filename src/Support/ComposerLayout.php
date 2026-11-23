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
 * shell that runs composer, not to the IDE's — and `~`, which composer.json
 * rarely holds.
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
        $vendor = self::absolute($root, self::text($config, key: 'vendor-dir') ?? 'vendor');
        $bin = \str_replace('{$vendor-dir}', $vendor, self::text($config, key: 'bin-dir') ?? '{$vendor-dir}/bin');

        return new self($vendor, self::absolute($root, $bin));
    }

    /** The vendor dir relative to $root, for a walk of the project — null when it is not inside it. */
    public function vendorBelow(string $root): ?string
    {
        $relative = Path::makeRelative($this->vendorDir, $root);

        return '' === $relative || \str_starts_with($relative, '../') ? null : $relative;
    }

    private static function text(mixed $config, string $key): ?string
    {
        // An offset into a string or a number is null under ??, as a missing key is.
        // @mago-expect analysis:mixed-assignment -- narrowed to a string below
        $value = $config[$key] ?? null;

        return \is_string($value) && '' !== \trim($value) ? \trim($value) : null;
    }

    private static function absolute(string $root, string $path): string
    {
        return Path::makeAbsolute($path, $root);
    }
}
