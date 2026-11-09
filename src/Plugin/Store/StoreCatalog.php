<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Config\PortaHome;
use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The store's shelves: every `porta-plugin` package on Packagist, read into
 * {@see StoreEntry}s with Porta's {@see Curation} applied, and kept under
 * `~/.porta/cache/store` so the store opens on what it last saw — at once,
 * offline too — while a refresh runs behind it.
 *
 * A refresh is three requests deep and costs two curl processes: the package
 * list and the curation together, then every package's releases together.
 * One runs at a time; a second asker waits for the first's answer rather than
 * starting its own.
 *
 * A bundled plugin's own split (`janalis/porta-<name>`) leaves the shelves
 * when a curated package {@see Curated::$replaces} it: there is one plugin by
 * that name to offer, and it is the one its tool's owners publish.
 */
#[Autoconfigure(constructor: 'create')]
final class StoreCatalog
{
    /** Porta's curation, as merged on main: a registration shows up without a release. */
    public const string CURATION = 'https://raw.githubusercontent.com/janalis/porta/main/store/plugins.json';

    /** How long what was fetched is fresh enough to open the store on without asking again. */
    public const int FRESH_FOR = 3600;

    /** The vendor whose packages are the bundled plugins' own read-only splits. */
    private const string BUNDLED_VENDOR = 'janalis/porta-';

    /** @var list<StoreEntry>|null read from the cache on first use */
    private ?array $entries = null;

    private int $fetchedAt = 0;

    /** @var list<\Closure(bool): void>|null the askers of the refresh in flight; null when none is */
    private ?array $waiting = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string|null             $cacheDir  where the catalog is kept, or null on a machine with no home
     * @param string                  $shipped   the curation file this Porta shipped with, used when main cannot be reached
     * @param (\Closure(): int)|null  $clock     seconds since the epoch; inject to test
     */
    public function __construct(
        private readonly HttpFetch $http,
        private readonly ?string $cacheDir,
        private readonly string $shipped,
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? \time(...);
    }

    /** Production wiring: the cache under the user's home, the curation beside the sources. */
    public static function create(HttpFetch $http): self
    {
        $home = PortaHome::base();

        return new self($http, null === $home ? null : $home.'/.porta/cache/store', \dirname(__DIR__, levels: 3).'/store/plugins.json');
    }

    /** @return list<StoreEntry> every plugin on the shelves, most downloaded first */
    public function entries(): array
    {
        return $this->entries ??= $this->readCache();
    }

    /** The entry for $package, or null when the store does not list it. */
    public function find(string $package): ?StoreEntry
    {
        return \array_find($this->entries(), static fn (StoreEntry $entry): bool => $package === $entry->package);
    }

    /** The listed package that takes over from the bundled plugin $name, or null. */
    public function replacing(string $name): ?StoreEntry
    {
        return \array_find($this->entries(), static fn (StoreEntry $entry): bool => $name === $entry->replaces);
    }

    /** When the shelves were last filled, in seconds since the epoch; 0 never. */
    public function fetchedAt(): int
    {
        $this->entries();

        return $this->fetchedAt;
    }

    /** Whether what is shown is older than {@see FRESH_FOR} (or there is nothing). */
    public function stale(): bool
    {
        return (($this->clock)() - $this->fetchedAt()) >= self::FRESH_FOR;
    }

    public function refreshing(): bool
    {
        return null !== $this->waiting;
    }

    /**
     * Ask Packagist again.
     *
     * @param \Closure(bool): void $onDone exactly once: whether the shelves were refilled; on false they keep what they had
     */
    public function refresh(\Closure $onDone): void
    {
        if (null !== $this->waiting) {
            $this->waiting[] = $onDone;

            return;
        }
        $this->waiting = [$onDone];
        $this->http->get([PackagistMetadata::SEARCH, self::CURATION], function (array $bodies): void {
            $search = $bodies[PackagistMetadata::SEARCH] ?? null;
            if (null === $search) {
                $this->finish(false);

                return;
            }
            $curation = Curation::parse($bodies[self::CURATION] ?? (\is_file($this->shipped) ? (string) \file_get_contents($this->shipped) : ''));
            $downloads = PackagistMetadata::search($search);
            $urls = \array_map(static fn (string $package): string => \sprintf(PackagistMetadata::RELEASES, $package), \array_keys($downloads));
            $this->http->get($urls, function (array $releases) use ($downloads, $curation): void {
                // No release list came back at all: Packagist's metadata host
                // is down, and that is a failed look — not an empty store
                // stamped fresh, which would also hold off the update check.
                if ([] !== $releases && [] === \array_filter($releases)) {
                    $this->finish(false);

                    return;
                }
                $entries = [];
                foreach ($downloads as $package => $count) {
                    $json = $releases[\sprintf(PackagistMetadata::RELEASES, $package)] ?? null;
                    // One that did not come back keeps what was seen of it last.
                    $entry = null === $json ? $this->find($package) : PackagistMetadata::entry($package, $json, $count, $curation->of($package));
                    if (null !== $entry) {
                        $entries[] = $entry;
                    }
                }
                $this->fill(self::shelve($entries), ($this->clock)());
                $this->finish(true);
            });
        });
    }

    /**
     * The shelves' order, and what leaves them: a bundled plugin's split whose
     * name a listed curated package replaces.
     *
     * @param list<StoreEntry> $entries
     *
     * @return list<StoreEntry>
     */
    private static function shelve(array $entries): array
    {
        $replaced = [];
        foreach ($entries as $entry) {
            if (null === $entry->replaces) {
                continue;
            }
            $replaced[$entry->replaces] = true;
        }
        $kept = \array_values(\array_filter($entries, static fn (StoreEntry $entry): bool => !\str_starts_with($entry->package, self::BUNDLED_VENDOR) || !\array_key_exists($entry->name, $replaced)));
        \usort($kept, static fn (StoreEntry $a, StoreEntry $b): int => [$b->downloads, $a->package] <=> [$a->downloads, $b->package]);

        return $kept;
    }

    /** @param list<StoreEntry> $entries */
    private function fill(array $entries, int $now): void
    {
        $this->entries = $entries;
        $this->fetchedAt = $now;
        if (null !== $this->cacheDir) {
            JsonFile::write($this->filesystem, $this->cacheDir.'/catalog.json', [
                'fetchedAt' => $now,
                'entries' => \array_map(static fn (StoreEntry $entry): array => $entry->toArray(), $entries),
            ]);
        }
    }

    private function finish(bool $filled): void
    {
        $waiting = $this->waiting ?? [];
        $this->waiting = null;
        foreach ($waiting as $onDone) {
            $onDone($filled);
        }
    }

    /** @return list<StoreEntry> */
    private function readCache(): array
    {
        $cache = null === $this->cacheDir ? null : JsonFile::read($this->cacheDir.'/catalog.json');
        /** @var mixed $fetchedAt */
        $fetchedAt = $cache['fetchedAt'] ?? null;
        $this->fetchedAt = \is_int($fetchedAt) ? $fetchedAt : 0;
        $entries = [];
        /** @var mixed $data */
        foreach (\is_array($cache['entries'] ?? null) ? $cache['entries'] : [] as $data) {
            $entry = \is_array($data) ? StoreEntry::fromArray($data) : null;
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
