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
 * 3. {@see PlainTextHighlightProvider}, which claims everything and is
 *    constructed here rather than resolved, so it can never be removed.
 *
 * There is no built-in engine in this chain any more: Tempest is the bundled
 * `tempest` plugin, competing on the same footing as any other backend. With
 * no highlighting plugin enabled the editor renders uncoloured rather than
 * broken. (Loose fragments — diff hunks, the empty-editor placeholder — do not
 * come through here; see {@see CodeHighlighterFactory::forFragments()}.)
 *
 * Every call into a provider — `id()`, `supports()` — is fault-barriered here,
 * in the caller. A provider that throws while being *interrogated* is treated
 * as not claiming the language; one that throws while *highlighting* is caught
 * by {@see CodeHighlighter}, which falls back to plain text.
 */
final readonly class HighlightRegistry
{
    private PlainTextHighlightProvider $plain;

    public function __construct(
        private SyntaxHighlightProviderSource $plugins,
        private HighlightPreferences $preferences,
    ) {
        $this->plain = new PlainTextHighlightProvider();
    }

    public function providerFor(string $language): SyntaxHighlightProvider
    {
        $candidates = $this->plugins->highlightProviders();

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

    /**
     * Every backend that claims $language, for the Settings page.
     *
     * @return list<SyntaxHighlightProvider>
     */
    public function available(string $language): array
    {
        $claiming = [];
        foreach ([...$this->plugins->highlightProviders(), $this->plain] as $candidate) {
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
            return $provider->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function claims(SyntaxHighlightProvider $provider, string $language): bool
    {
        try {
            return $provider->supports($language);
        } catch (\Throwable) {
            return false;
        }
    }
}
