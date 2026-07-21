<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * Picks the highlighting backend for a language.
 *
 * Resolution order, first match wins:
 *
 * 1. the id configured for that grammar, else the global default
 *    ({@see HighlightPreferences}) — an id that doesn't exist, doesn't claim
 *    the language, or isn't available warns and falls through rather than
 *    leaving the file uncolored;
 * 2. the first plugin provider in load order that claims it — mirroring
 *    `PluginManager::languageServers()`'s "first available in load order wins",
 *    so installing a highlighter plugin does something without editing config;
 * 3. the built-in Tempest backend;
 * 4. {@see PlainTextHighlightProvider}, which claims everything and is
 *    constructed here rather than resolved, so it can never be removed.
 *
 * Every call into a provider — `id()`, `supports()` — is fault-barriered here,
 * in the caller. A provider that throws while being *interrogated* is treated
 * as not claiming the language; one that throws while *highlighting* is caught
 * by {@see CodeHighlighter}, which falls back to plain text.
 */
final class HighlightRegistry
{
    private readonly PlainTextHighlightProvider $plain;

    /** @var list<string> ids that threw while being interrogated, reported once each */
    private array $broken = [];

    public function __construct(
        private readonly SyntaxHighlightProviderSource $plugins,
        private readonly TempestHighlightProvider $tempest,
        private readonly HighlightPreferences $preferences,
    ) {
        $this->plain = new PlainTextHighlightProvider();
    }

    public function providerFor(string $language): SyntaxHighlightProvider
    {
        $candidates = [...$this->plugins->highlightProviders(), $this->tempest];

        $preferred = $this->preferences->preferredFor($language);
        if (null !== $preferred) {
            foreach ($candidates as $candidate) {
                if ($preferred === $this->idOf($candidate) && $this->claims($candidate, $language)) {
                    return $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->claims($candidate, $language)) {
                return $candidate;
            }
        }

        return $this->plain;
    }

    /** Every backend that claims $language, for the Settings page. */
    public function available(string $language): array
    {
        $claiming = [];
        foreach ([...$this->plugins->highlightProviders(), $this->tempest, $this->plain] as $candidate) {
            if (!$this->claims($candidate, $language)) {
                continue;
            }

            $claiming[] = $candidate;
        }

        return $claiming;
    }

    private function idOf(SyntaxHighlightProvider $provider): ?string
    {
        try {
            return $provider->id();
        } catch (\Throwable) {
            return null;
        }
    }

    private function claims(SyntaxHighlightProvider $provider, string $language): bool
    {
        try {
            return $provider->supports($language);
        } catch (\Throwable) {
            $id = $this->idOf($provider) ?? '?';
            if (!\in_array($id, $this->broken, strict: true)) {
                $this->broken[] = $id;
            }

            return false;
        }
    }
}
