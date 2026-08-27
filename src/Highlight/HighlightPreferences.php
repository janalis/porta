<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which highlighting backend the user wants, globally and per language.
 *
 * Parsed from `.porta.json`'s top-level `highlight` block:
 *
 * ```json
 * "highlight": { "default": "tempest", "languages": { "rust": "chroma" } }
 * ```
 *
 * It is a **top-level** block rather than part of `settings` or `languages` for
 * two concrete reasons: `settings` coerces every value to bool/int/string so it
 * cannot hold a nested map, and `languages` is keyed by LSP languageId while
 * highlighting is keyed by grammar name — so keying off it would leave `jsonc`,
 * `terraform`, `blade`, `sql`, `bash`, `diff` and `ini` permanently
 * unconfigurable, since none of them has a language server.
 *
 * An empty id means "no preference", which is not the same as a *bad* one: an
 * unknown id is a user error the registry warns about and falls through.
 *
 * This is a **live holder**, not a boot snapshot: the Settings → Highlighting
 * page pushes each edit through {@see change()} so resolution follows without a
 * restart, the same shared-mutable-holder shape every core setting uses.
 */
#[Autoconfigure(constructor: 'fromConfig')]
final class HighlightPreferences
{
    /** @param array<string, string> $languages grammar name => provider id */
    public function __construct(
        private string $default = '',
        private array $languages = [],
    ) {}

    /**
     * Build from {@see \Porta\Config\IdeConfig}'s parsed `highlight` block, as
     * it arrives through the container's `PORTA_HIGHLIGHT` env channel.
     *
     * @param array{default?: string, languages?: array<string, string>} $highlight
     */
    public static function fromConfig(#[Autowire('%env(json:PORTA_HIGHLIGHT)%')] array $highlight): self
    {
        return new self($highlight['default'] ?? '', $highlight['languages'] ?? []);
    }

    /** The preferred provider id for $language, or null when none is configured. */
    public function preferredFor(string $language): ?string
    {
        $id = $this->languages[$language] ?? $this->default;

        return '' === $id ? null : $id;
    }

    /** The configured global default id, '' when none. */
    public function defaultId(): string
    {
        return $this->default;
    }

    /** @return array<string, string> grammar name => provider id */
    public function languages(): array
    {
        return $this->languages;
    }

    /**
     * Apply one Settings-page edit: null $language is the global default, and
     * an empty $id clears the entry — mirroring
     * {@see \Porta\Config\PortaJsonWriter::persistHighlighter()}, so what
     * resolves and what persists cannot drift. Unsetting (rather than storing
     * '') is what keeps a cleared language falling through to the default.
     */
    public function change(?string $language, string $id): void
    {
        if (null === $language) {
            $this->default = $id;

            return;
        }

        $this->languages[$language] = $id;
        if ('' === $id) {
            unset($this->languages[$language]);
        }
    }
}
