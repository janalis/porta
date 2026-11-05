<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\IdeConfig;
use Porta\Config\WorkspaceTrust;
use Porta\Support\JsonFile;

/**
 * One-time adoption of the core settings a plugin's keys replaced.
 *
 * When a core-owned setting moves to `plugins.<name>.<key>` — git's eight
 * went first, the graph/image viewers' four followed — the rename is the one
 * irreversible user-visible part of the move, and the failure without
 * adoption is silent: a value written years ago sits inertly under the old
 * key while the new one answers with the default. So the pre-move key is
 * read once, for whichever new key has never been written.
 *
 * Reads `.porta.json` directly rather than going through `PluginApi`: the
 * question is "was this key ever written?", and `setting()` cannot answer
 * it — an absent key and a key explicitly set to the default are the same
 * VALUE, and only the file distinguishes them. The old keys always lived in
 * the `settings` block (the pre-move writer put every core setting there;
 * the top level never held one), so only that location can hold a value
 * worth adopting. Adoption is per-key, not all-or-nothing: a user who
 * changed one setting and left the rest keeps that one and gets current
 * defaults for the others, which is what they had.
 */
final readonly class LegacyCoreSettings
{
    /** @var array<array-key, mixed> the decoded `.porta.json`, or [] when there is none */
    private array $file;

    /** @param array<string, string> $renamed new plugin key => the core key it replaced */
    public function __construct(
        string $rootDir,
        private string $plugin,
        private array $renamed,
    ) {
        // Core must skip each adopted key without warning: one missing from its
        // list warned "Unknown setting" at every load, about a value this read.
        \assert([] === \array_diff($renamed, IdeConfig::LEGACY_SETTINGS), description: 'a core key a plugin adopts is one IdeConfig::LEGACY_SETTINGS skips');
        $file = JsonFile::read($rootDir.'/.porta.json') ?? [];
        // The pre-plugin keys named binaries too (`gitPath`, `dotPath`): an
        // untrusted project's are not adopted.
        $this->file = WorkspaceTrust::fromEnvironment()->isTrusted($rootDir) ? $file : WorkspaceTrust::restricted($file);
    }

    /**
     * The values in force: the plugin's own where written, the pre-move key's
     * where not, `$current`'s otherwise.
     *
     * @param \Closure(string): (bool|int|string) $current the plugin's resolved value for a key
     *
     * @return array<string, bool|int|string>
     */
    public function seed(\Closure $current): array
    {
        $seed = [];
        foreach ($this->renamed as $key => $legacy) {
            $adopted = $this->adopt($key, $legacy);
            $seed[$key] = $adopted ?? $current($key);
        }

        return $seed;
    }

    /**
     * The keys whose value came from a pre-move one, so the caller can write
     * them through and stop the file answering the same question twice.
     *
     * @return array<string, bool|int|string>
     */
    public function adoptions(): array
    {
        $adopted = [];
        foreach ($this->renamed as $key => $legacy) {
            $value = $this->adopt($key, $legacy);
            if (null !== $value) {
                $adopted[$key] = $value;
            }
        }

        return $adopted;
    }

    /** The pre-move value for $key, or null when there is nothing to adopt. */
    private function adopt(string $key, string $legacy): bool|int|string|null
    {
        /** @var mixed $plugins */
        $plugins = $this->file['plugins'] ?? null;
        /** @var mixed $mine */
        $mine = \is_array($plugins) ? $plugins[$this->plugin] ?? null : null;
        if (\is_array($mine) && \array_key_exists($key, $mine)) {
            return null; // already answered under the new key; nothing to adopt
        }
        /** @var mixed $settings */
        $settings = $this->file['settings'] ?? null;
        /** @var mixed $value */
        $value = \is_array($settings) ? $settings[$legacy] ?? null : null;

        return \is_bool($value) || \is_int($value) || \is_string($value) ? $value : null;
    }
}
