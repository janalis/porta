<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * One plugin on the store's shelves: what Packagist publishes of its newest
 * version this Porta can run, what the package says of itself in
 * `extra.porta.store`, and what Porta's {@see Curation} adds.
 *
 * Plain data, and round-trips through {@see toArray()} / {@see fromArray()}
 * so the catalog can be drawn from the cache at once, before the network
 * answers — or when it never does.
 */
final readonly class StoreEntry
{
    /**
     * @param string                    $package     `vendor/porta-name`
     * @param string                    $name        the plugin it installs as — the package's name less `porta-`
     * @param string                    $version     the newest version this Porta can run, as tagged; or the newest of all when none can
     * @param int                       $time        when that version was tagged, seconds since the epoch; 0 unknown
     * @param list<string>              $screenshots https PNG URLs, in the order the package lists them
     * @param string|null               $changelog   where its changelog is read, at that version; null when it has none Porta can fetch
     * @param string|null               $readme      where its README is read, likewise
     * @param string                    $source      the repository's web address, or ''
     * @param bool                      $compatible  whether $version runs on this Porta (plugin API, PHP, extensions)
     * @param list<array{string, int}>  $history     every tagged release, newest first: version and time
     * @param string|null               $icon        an https address of its icon, as the package lists it
     * @param list<string>              $compatibleVersions stable releases compatible with this PHP and Porta API
     * @param list<string>              $tools       where the tools it drives are published: `github:owner/name`, `composer:vendor/name`
     */
    public function __construct(
        public string $package,
        public string $name,
        public string $title,
        public string $author,
        public string $description,
        public StoreCategory $category,
        public string $version,
        public int $time = 0,
        public int $downloads = 0,
        public array $screenshots = [],
        public ?string $changelog = null,
        public ?string $readme = null,
        public string $source = '',
        public string $license = '',
        public bool $verified = false,
        public bool $featured = false,
        public ?string $replaces = null,
        public bool $compatible = true,
        public array $history = [],
        public ?string $icon = null,
        public array $tools = [],
        public array $compatibleVersions = [],
    ) {}

    /** Whether $query (lowercase, trimmed) is in what the entry is called, says or is by. */
    public function matches(string $query): bool
    {
        if ('' === $query) {
            return true;
        }
        $haystack = \mb_strtolower(\implode("\n", [$this->title, $this->package, $this->description, $this->author]));

        return \str_contains($haystack, $query);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'name' => $this->name,
            'title' => $this->title,
            'author' => $this->author,
            'description' => $this->description,
            'category' => $this->category->value,
            'version' => $this->version,
            'time' => $this->time,
            'downloads' => $this->downloads,
            'screenshots' => $this->screenshots,
            'changelog' => $this->changelog,
            'readme' => $this->readme,
            'source' => $this->source,
            'license' => $this->license,
            'verified' => $this->verified,
            'featured' => $this->featured,
            'replaces' => $this->replaces,
            'compatible' => $this->compatible,
            'history' => $this->history,
            'icon' => $this->icon,
            'tools' => $this->tools,
            'compatibleVersions' => $this->compatibleVersions,
        ];
    }

    /**
     * The entry a cached {@see toArray()} wrote, or null when the cache does
     * not hold one — a cache from another Porta version is read as far as it
     * goes, and an entry it cannot read is refetched, never trusted.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $string = static fn (string $key): string => \is_string($data[$key] ?? null) ? $data[$key] : '';
        $optional = static fn (string $key): ?string => \is_string($data[$key] ?? null) ? $data[$key] : null;
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;
        if ('' === $string('package') || '' === $string('name') || '' === $string('version')) {
            return null;
        }

        return new self(
            $string('package'),
            $string('name'),
            $string('title'),
            $string('author'),
            $string('description'),
            StoreCategory::read($data['category'] ?? null),
            $string('version'),
            $int('time'),
            $int('downloads'),
            PackagistMetadata::strings($data['screenshots'] ?? null),
            $optional('changelog'),
            $optional('readme'),
            $string('source'),
            $string('license'),
            true === ($data['verified'] ?? null),
            true === ($data['featured'] ?? null),
            $optional('replaces'),
            false !== ($data['compatible'] ?? null),
            self::history($data['history'] ?? null),
            $optional('icon'),
            PackagistMetadata::strings($data['tools'] ?? null),
            PackagistMetadata::strings($data['compatibleVersions'] ?? null),
        );
    }

    /** @return list<array{string, int}> */
    private static function history(mixed $history): array
    {
        $releases = [];
        /** @var mixed $release */
        foreach (\is_array($history) ? $history : [] as $release) {
            if (!\is_array($release) || !\is_string($release[0] ?? null) || !\is_int($release[1] ?? null)) {
                continue;
            }
            $releases[] = [$release[0], $release[1]];
        }

        return $releases;
    }
}
