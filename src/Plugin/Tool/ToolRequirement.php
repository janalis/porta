<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Plugin\Api\BinarySearch;

/**
 * One external program a plugin runs, as its manifest declares it under
 * `extra.porta.tools`:
 *
 * ```json
 * {"name": "dot", "role": "suggested", "purpose": "renders Graphviz diagrams",
 *  "setting": "dotPath", "sources": [["brew", "graphviz"]], "version": ["-V"]}
 * ```
 *
 * In the manifest rather than in `activate()`, because the questions asked of
 * it — is it there, what version, can it be installed — are asked of a
 * DISABLED plugin too: the Plugins page lists what each one needs before it is
 * switched on, which is when that is worth knowing.
 *
 * How the binary is looked for follows from where it can come from: a tool
 * composer installs has a `vendor/bin` shim, and one it does not never will,
 * so {@see search()} is derived from the sources rather than declared.
 */
final readonly class ToolRequirement
{
    /** What a binary may be called: one file name, not a path, not hidden. */
    private const string BINARY = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/';

    /**
     * @param string $plugin the declaring plugin's name
     * @param string $name what the tool is called on screen — usually its binary
     * @param string $binary the executable looked up on the PATH and in `vendor/bin`
     * @param string $purpose what it does for the plugin, in a few words; '' says nothing
     * @param string|null $setting the plugin setting that names the binary by hand, if any
     * @param list<ToolSource> $sources where it can be installed from, in preference order
     * @param list<string> $versionArgs what makes it print its version
     * @param BinarySearch|null $lookup overrides the search {@see $sources} imply — for a tool built in
     *        code, whose sources are not declared, that still has a `vendor/bin` shim
     */
    public function __construct(
        public string $plugin,
        public string $name,
        public string $binary,
        public ToolRole $role = ToolRole::Required,
        public string $purpose = '',
        public ?string $setting = null,
        public array $sources = [],
        public array $versionArgs = ['--version'],
        private ?BinarySearch $lookup = null,
    ) {}

    /** `vendor/bin` first only for what composer can put there. */
    public function search(): BinarySearch
    {
        return $this->lookup ?? (null === $this->source(ToolManager::Composer) ? BinarySearch::PathOnly : BinarySearch::VendorBinThenPath);
    }

    /** The declared source on $manager, or null. */
    public function source(ToolManager $manager): ?ToolSource
    {
        return \array_find($this->sources, static fn (ToolSource $source): bool => $manager === $source->manager);
    }

    /**
     * Every usable entry of `extra.porta.tools`.
     *
     * An entry that is not usable is DROPPED, never fatal, exactly as the rest
     * of the manifest degrades: a typo in one tool's metadata must not take
     * the plugin down with it. A source naming a manager Porta does not know is
     * dropped on its own, so a manifest written for a newer Porta still
     * installs through the sources this one understands.
     *
     * @param array<array-key, mixed> $porta the manifest's `extra.porta` object
     *
     * @return list<self>
     */
    public static function fromManifest(string $plugin, array $porta): array
    {
        $tools = [];
        /** @var mixed $entry */
        foreach (\is_array($porta['tools'] ?? null) ? $porta['tools'] : [] as $entry) {
            $tool = \is_array($entry) ? self::entry($plugin, $entry) : null;
            if (null !== $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /** @param array<array-key, mixed> $entry */
    private static function entry(string $plugin, array $entry): ?self
    {
        $name = \trim(self::text($entry['name'] ?? null));
        $binary = self::text($entry['binary'] ?? null);
        $setting = self::text($entry['setting'] ?? null);
        $version = self::strings($entry['version'] ?? null);

        // A bare file name, never a path: it is looked up on the PATH, in
        // vendor/bin and in ~/.porta/bin, and a download lands under it.
        return '' === $name || 1 !== \preg_match(self::BINARY, '' === $binary ? $name : $binary)
            ? null
            : new self(
                $plugin,
                $name,
                '' === $binary ? $name : $binary,
                ToolRole::tryFrom(self::text($entry['role'] ?? null)) ?? ToolRole::Required,
                self::text($entry['purpose'] ?? null),
                '' === $setting ? null : $setting,
                self::sources($entry['sources'] ?? null),
                [] === $version ? ['--version'] : $version,
            );
    }

    /** @return list<ToolSource> */
    private static function sources(mixed $value): array
    {
        $sources = [];
        /** @var mixed $candidate */
        foreach (\is_array($value) ? $value : [] as $candidate) {
            $source = \is_array($candidate) ? self::candidate($candidate) : null;
            if (null !== $source) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /** @param array<array-key, mixed> $candidate `[manager, package]`, plus the asset rules for github */
    private static function candidate(array $candidate): ?ToolSource
    {
        $manager = ToolManager::tryFrom(self::text($candidate[0] ?? null));
        $package = \trim(self::text($candidate[1] ?? null));
        $asset = \is_array($candidate[2] ?? null) ? GithubAsset::fromManifest($candidate[2]) : null;

        return match (true) {
            // Handed to the manager as one argument: never one it would read
            // as an option of its own.
            null === $manager || 1 !== \preg_match('/^[^\s-]\S*$/', $package) => null,
            ToolManager::Github !== $manager => new ToolSource($manager, $package),
            // owner/repo and nothing else: it is spliced into github.com URLs.
            null !== $asset && 1 === \preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $package) => new ToolSource($manager, $package, $asset),
            default => null,
        };
    }

    /** A string field, or '' for anything else. */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @return list<string> the non-empty strings of a list field */
    private static function strings(mixed $value): array
    {
        $strings = [];
        /** @var mixed $word */
        foreach (\is_array($value) ? $value : [] as $word) {
            if (!\is_string($word) || '' === $word) {
                continue;
            }
            $strings[] = $word;
        }

        return $strings;
    }
}
