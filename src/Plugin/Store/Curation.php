<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Plugin\Runtime\PluginLoader;

/**
 * Porta's own word on the store's packages: `store/plugins.json`, the file a
 * registration pull request edits.
 *
 * The store lists every `porta-plugin` package on Packagist whether or not it
 * is here — this file is curation, not a gate. An entry makes its package
 * **verified** (CI checked the pull request's author owns it), may set its
 * category or feature it, and — for the owner of a tool a bundled plugin
 * drives — may name the bundled plugin it **replaces**, which the store then
 * offers in its place.
 *
 * It arrives over the network, so nothing here throws: an entry that is not
 * shaped right is dropped, and the rest stand.
 */
final readonly class Curation
{
    /** Composer's own package-name shape. */
    private const string PACKAGE = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#';

    /** @param array<string, Curated> $entries package => what Porta says of it */
    public function __construct(
        private array $entries = [],
    ) {}

    /** The curation $json describes; anything unreadable is no curation at all. */
    public static function parse(string $json): self
    {
        // @noinspection JsonEncodingApiUsageInspection -- a file off the network that is not JSON is dropped, not thrown
        /** @var mixed $data */
        $data = \json_decode($json, associative: true);
        $entries = [];
        /** @var mixed $entry */
        foreach (\is_array($data) ? $data : [] as $package => $entry) {
            if (!\is_array($entry) || !\is_string($package) || 1 !== \preg_match(self::PACKAGE, $package)) {
                continue;
            }
            /** @var mixed $replaces */
            $replaces = $entry['replaces'] ?? null;
            $entries[$package] = new Curated(
                \array_key_exists('category', $entry) ? StoreCategory::read($entry['category']) : null,
                true === ($entry['featured'] ?? false),
                \is_string($replaces) && 1 === \preg_match(PluginLoader::NAME_PATTERN, $replaces) ? $replaces : null,
            );
        }

        return new self($entries);
    }

    /** What Porta says of $package, or null when it is not curated. */
    public function of(string $package): ?Curated
    {
        return $this->entries[$package] ?? null;
    }

    /** The curated package that replaces the bundled plugin $name, or null. */
    public function replacing(string $name): ?string
    {
        return \array_find_key($this->entries, static fn (Curated $entry): bool => $name === $entry->replaces);
    }
}
