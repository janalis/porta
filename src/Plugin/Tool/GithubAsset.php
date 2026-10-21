<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * How one repository names its release assets, so a single installer can
 * fetch any of them.
 *
 * Two shapes are in use and both are covered:
 *
 *   - an archive holding the binary — symfony/language-tools ships
 *     `symfony-lsp-{tag}-{os}-{arch}.tar.gz` containing
 *     `symfony-lsp-{tag}-{os}-{arch}/symfony-lsp`, so $member names it;
 *   - the bare binary — janalis/custos ships `custos_{os}_{arch}` itself, so
 *     $member is null and the asset IS what lands.
 *
 * Repositories disagree on the platform words too (`macos-x64` beside
 * `darwin_amd64`), which is what the two maps are for: PHP_OS_FAMILY and
 * `php_uname('m')` on the left, the repository's own token on the right. A
 * host neither map names has no prebuilt binary, and says so before a byte
 * moves.
 */
final readonly class GithubAsset
{
    /**
     * @param string $asset the asset name, with `{tag}`, `{version}` (the tag without its `v`), `{os}` and `{arch}`
     * @param array<string, string> $os PHP_OS_FAMILY => the repository's OS token
     * @param array<string, string> $arch `php_uname('m')` => the repository's architecture token
     * @param string|null $member the binary's path inside a `.tar.gz` asset, same placeholders; null when the asset is the binary
     * @param string $sums the release's checksum file, coreutils `sha256sum` shape
     */
    public function __construct(
        public string $asset,
        public array $os,
        public array $arch,
        public ?string $member = null,
        public string $sums = 'SHA256SUMS',
    ) {}

    /** The asset for this host at $tag, or null when the repository builds none for it. */
    public function assetFor(string $tag, string $osFamily, string $machine): ?string
    {
        return $this->fill($this->asset, $tag, $osFamily, $machine);
    }

    /** Where the binary sits inside the extracted asset; null when the asset is the binary, or the host has none. */
    public function memberFor(string $tag, string $osFamily, string $machine): ?string
    {
        return null === $this->member ? null : $this->fill($this->member, $tag, $osFamily, $machine);
    }

    private function fill(string $template, string $tag, string $osFamily, string $machine): ?string
    {
        $os = $this->os[$osFamily] ?? null;
        $arch = $this->arch[$machine] ?? null;
        if (null === $os || null === $arch) {
            return null;
        }

        return \strtr($template, ['{tag}' => $tag, '{version}' => \ltrim($tag, characters: 'v'), '{os}' => $os, '{arch}' => $arch]);
    }

    /**
     * The rules out of a manifest's third source element, or null when they
     * are not usable: an asset with no name, or maps that are not string to
     * string, would build URLs out of nothing.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromManifest(array $data): ?self
    {
        $asset = self::text($data['asset'] ?? null);
        $member = self::text($data['member'] ?? null);
        $sums = self::text($data['sums'] ?? 'SHA256SUMS');
        $os = self::map($data['os'] ?? null);
        $arch = self::map($data['arch'] ?? null);
        // Each becomes a path under the download's scratch directory: a file
        // name, or for the member a relative path that never climbs out.
        $safe = self::isName($asset) && self::isName($sums) && ('' === $member || self::isRelative($member));

        return !$safe || [] === $os || [] === $arch ? null : new self($asset, $os, $arch, '' === $member ? null : $member, $sums);
    }

    /** One file name: no directory, not `.` or `..`. */
    private static function isName(string $name): bool
    {
        return 1 === \preg_match('/^[A-Za-z0-9_{}+-][A-Za-z0-9_.{}+-]*$/', $name) && !\str_contains($name, needle: '..');
    }

    /** A path of file names below the directory it is joined to. */
    private static function isRelative(string $path): bool
    {
        return [] === \array_filter(\explode('/', $path), static fn (string $part): bool => !self::isName($part));
    }

    /** A string field, or '' for anything else. */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @return array<string, string> */
    private static function map(mixed $value): array
    {
        $map = [];
        /** @var mixed $token */
        foreach (\is_array($value) ? $value : [] as $key => $token) {
            // Spliced into the asset's name, so a file name's characters only.
            if (!\is_string($key) || !\is_string($token) || !self::isName($token)) {
                continue;
            }
            $map[$key] = $token;
        }

        return $map;
    }
}
