<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Support\ControlChars;

/**
 * Packagist's answers, read into {@see StoreEntry}s: `search.json` for which
 * `porta-plugin` packages exist and how often each was downloaded, and each
 * package's `p2/<vendor>/<name>.json` for its releases.
 *
 * Off the network, so every reader degrades rather than throws: a field that
 * is not shaped right is left out, a package that cannot be read is not
 * listed, and an answer that is not JSON is no packages at all.
 */
final class PackagistMetadata
{
    /** Where the store reads which plugins exist: one page holds every one Porta has ever seen. */
    public const string SEARCH = 'https://packagist.org/search.json?type=porta-plugin&per_page=100';

    /** A package's releases: Packagist's composer v2 metadata, the file `composer require` reads. */
    public const string RELEASES = 'https://repo.packagist.org/p2/%s.json';

    /** The repository's own files, at the commit a release was tagged on. */
    private const string RAW = 'https://raw.githubusercontent.com/%s/%s/%s';

    /** A GitHub repository's address as composer records it, with or without `.git`. */
    private const string GITHUB = '#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$#';

    /** An `owner/name` a tool is published under: neither part a `.` or `..` step. */
    private const string REPOSITORY = '#^(?!\.)[A-Za-z0-9_.-]+/(?!\.)[A-Za-z0-9_.-]+$#';

    /** A Homebrew formula's name. */
    private const string FORMULA = '#^[a-z0-9][a-z0-9@._+-]*$#';

    /** A release tag composer calls stable: digits and dots, nothing after. */
    private const string STABLE = '/^\d+(\.\d+)*$/';

    /**
     * Every package `search.json` lists that is not abandoned, with its
     * download count.
     *
     * @return array<string, int> package => downloads
     */
    public static function search(string $json): array
    {
        $found = [];
        /** @var mixed $results */
        $results = self::decode($json)['results'] ?? null;
        /** @var mixed $result */
        foreach (\is_array($results) ? $results : [] as $result) {
            if (!\is_array($result) || !\is_string($result['name'] ?? null) || false !== ($result['abandoned'] ?? false)) {
                continue;
            }
            $found[$result['name']] = \is_int($result['downloads'] ?? null) ? $result['downloads'] : 0;
        }

        return $found;
    }

    /**
     * The store's entry for $package from its `p2` $json, or null when it
     * publishes no release of a Porta plugin, or one whose name no plugin
     * may take.
     *
     * The release shown is the newest STABLE one that runs on this Porta —
     * what Get would install. When none runs here, the newest stable one is
     * shown all the same, marked incompatible, so the store can say why there
     * is no Get rather than hide the plugin.
     */
    public static function entry(string $package, string $json, int $downloads = 0, ?Curated $curated = null): ?StoreEntry
    {
        $name = self::nameOf($package);
        /** @var mixed $minified */
        $minified = self::decode($json)['packages'][$package] ?? null;
        $releases = \array_values(\array_filter(
            self::expand(\is_array($minified) ? \array_values($minified) : []),
            static fn (array $release): bool => 'porta-plugin' === ($release['type'] ?? null) && \is_string($release['version'] ?? null),
        ));
        if (null === $name || [] === $releases) {
            return null;
        }
        $stable = \array_values(\array_filter($releases, static fn (array $release): bool => 1 === \preg_match(self::STABLE, self::string($release, 'version_normalized'))));
        $candidates = [] === $stable ? $releases : $stable;
        $runs = \array_find($candidates, static fn (array $release): bool => self::runsHere($name, $release));
        $release = $runs ?? $candidates[0];

        return self::fromRelease($package, $name, $release, $downloads, $curated, null !== $runs, $releases);
    }

    /**
     * @param array<array-key, mixed>       $release
     * @param list<array<array-key, mixed>> $releases
     */
    private static function fromRelease(string $package, string $name, array $release, int $downloads, ?Curated $curated, bool $compatible, array $releases): StoreEntry
    {
        $extra = \is_array($release['extra'] ?? null) ? $release['extra'] : [];
        $porta = \is_array($extra['porta'] ?? null) ? $extra['porta'] : [];
        $store = \is_array($porta['store'] ?? null) ? $porta['store'] : [];
        // Every string a package says of itself is drawn in the terminal: none may carry a control character.
        $title = \is_string($store['title'] ?? null) ? ControlChars::line($store['title']) : '';
        $title = '' !== $title ? $title : \ucwords(\str_replace('-', replace: ' ', subject: $name));
        $source = \is_array($release['source'] ?? null) ? $release['source'] : [];
        $match = [];
        $github = 1 === \preg_match(self::GITHUB, self::string($source, 'url'), $match) ? $match[1] : null;
        $reference = self::string($source, 'reference');
        $file = static fn (string $key, string $default): ?string => null === $github || '' === $reference ? null : self::raw($github, $reference, $store[$key] ?? $default);

        return new StoreEntry(
            $package,
            $name,
            $title,
            ControlChars::line(self::author($package, $release)),
            ControlChars::line(self::string($release, 'description')),
            $curated->category ?? StoreCategory::read($store['category'] ?? null),
            ControlChars::line(self::string($release, 'version')),
            self::time($release),
            $downloads,
            self::screenshots($store['screenshots'] ?? null),
            $file('changelog', default: 'CHANGELOG.md'),
            $file('readme', default: 'README.md'),
            null === $github ? '' : 'https://github.com/'.$github,
            ControlChars::line(\implode(', ', self::strings($release['license'] ?? null))),
            null !== $curated,
            $curated->featured ?? false,
            $curated?->replaces,
            $compatible,
            \array_map(static fn (array $release): array => [ControlChars::line(self::string($release, 'version')), self::time($release)], $releases),
            self::screenshots([$store['icon'] ?? null])[0] ?? null,
            self::tools($porta['tools'] ?? null),
        );
    }

    /**
     * The plugin $package installs as — its name less a leading `porta-`,
     * exactly as the installer names the directory — or null when that is not
     * a name a plugin may have.
     */
    public static function nameOf(string $package): ?string
    {
        $project = \substr($package, (int) \strrpos($package, needle: '/') + 1);
        $name = \str_starts_with($project, 'porta-') ? \substr($project, \strlen('porta-')) : $project;

        return 1 === \preg_match(PluginLoader::NAME_PATTERN, $name) ? $name : null;
    }

    /**
     * Whether $release would load here: the plugin API it targets and the
     * platform it requires, judged by the same manifest reading the loader
     * declines a plugin with.
     *
     * @param array<array-key, mixed> $release
     */
    private static function runsHere(string $name, array $release): bool
    {
        $manifest = PluginManifest::fromData($name, '', $release);

        return (null === $manifest->api || PluginApi::API_VERSION === $manifest->api) && [] === $manifest->unmetPlatform();
    }

    /**
     * Composer's minified `p2` list, expanded: each release after the first
     * names only what changed from the one before it, `__unset` removing a
     * key — the algorithm of `composer/metadata-minifier`.
     *
     * @param list<mixed> $minified
     *
     * @return list<array<array-key, mixed>>
     */
    private static function expand(array $minified): array
    {
        $expanded = [];
        $current = [];
        /** @var mixed $release */
        foreach ($minified as $release) {
            if (!\is_array($release)) {
                continue;
            }
            /** @var mixed $value */
            foreach ($release as $key => $value) {
                if ('__unset' === $value) {
                    unset($current[$key]);

                    continue;
                }
                $current[$key] = $value;
            }
            $expanded[] = $current;
        }

        return $expanded;
    }

    /** @param array<array-key, mixed> $release */
    private static function author(string $package, array $release): string
    {
        /** @var mixed $first */
        $first = \is_array($release['authors'] ?? null) ? $release['authors'][0] ?? null : null;
        $name = \is_array($first) && \is_string($first['name'] ?? null) ? \trim($first['name']) : '';

        return '' !== $name ? $name : (string) \strstr($package, needle: '/', before_needle: true);
    }

    /** @param array<array-key, mixed> $release */
    private static function time(array $release): int
    {
        $time = \strtotime(self::string($release, 'time'));

        return false === $time ? 0 : $time;
    }

    /**
     * The screenshots a package lists: https addresses only, and at most six —
     * a store page shows a few, and every one is a download.
     *
     * @return list<string>
     */
    private static function screenshots(mixed $listed): array
    {
        $urls = \array_filter(self::strings($listed), static fn (string $url): bool => \str_starts_with($url, 'https://') && 1 !== \preg_match('/[\s\p{Cc}]/u', $url));

        return \array_slice(\array_values($urls), offset: 0, length: 6);
    }

    /**
     * Where the tools a plugin drives are published, as `github:owner/name`
     * or `composer:vendor/name` — the sources a README can be read from; a
     * brew formula, an npm or a cargo package has none the store knows.
     *
     * @return list<string>
     */
    private static function tools(mixed $tools): array
    {
        $found = [];
        /** @var mixed $tool */
        foreach (\is_array($tools) ? $tools : [] as $tool) {
            /** @var mixed $sources */
            $sources = \is_array($tool) ? $tool['sources'] ?? null : null;
            /** @var mixed $source */
            foreach (\is_array($sources) ? $sources : [] as $source) {
                /** @var mixed $kind */
                $kind = \is_array($source) ? $source[0] ?? null : null;
                /** @var mixed $name */
                $name = \is_array($source) ? $source[1] ?? null : null;
                // A GitHub repository and a composer package name their repository; a Homebrew formula says it on its page.
                $pattern = match ($kind) {
                    'github', 'composer' => self::REPOSITORY,
                    'brew' => self::FORMULA,
                    default => null,
                };
                if (null === $pattern || !\is_string($kind) || !\is_string($name) || 1 !== \preg_match($pattern, $name)) {
                    continue;
                }
                $found[] = $kind.':'.$name;
            }
        }

        return \array_values(\array_unique($found));
    }

    /**
     * The GitHub repository a package's `p2` $json says it is published from,
     * as `owner/name`, or null.
     */
    public static function repository(string $package, string $json): ?string
    {
        /** @var mixed $first */
        $first = self::decode($json)['packages'][$package][0] ?? null;
        $source = \is_array($first) && \is_array($first['source'] ?? null) ? $first['source'] : [];
        $match = [];

        return 1 === \preg_match(self::GITHUB, self::string($source, 'url'), $match) ? $match[1] : null;
    }

    /**
     * The raw address of $path in $repository at $reference — or null when
     * $path is not a plain relative path inside the repository.
     */
    private static function raw(string $repository, string $reference, mixed $path): ?string
    {
        if (!\is_string($path) || '' === $path || \str_starts_with($path, '/') || 1 !== \preg_match('#^[A-Za-z0-9_./-]+$#', $path) || \in_array('..', \explode('/', $path), strict: true)) {
            return null;
        }

        return \sprintf(self::RAW, $repository, \rawurlencode($reference), $path);
    }

    /**
     * The strings in $list, when it is a list; nothing else.
     *
     * @return list<string>
     */
    public static function strings(mixed $list): array
    {
        $strings = [];
        /** @var mixed $item */
        foreach (\is_array($list) ? $list : [] as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        // @noinspection JsonEncodingApiUsageInspection -- an answer off the network that is not JSON is no packages, not an exception
        /** @var mixed $data */
        $data = \json_decode($json, associative: true);

        return \is_array($data) ? $data : [];
    }
}
