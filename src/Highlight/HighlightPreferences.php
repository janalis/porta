<?php

declare(strict_types=1);

namespace Porta\Highlight;

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
 */
final readonly class HighlightPreferences
{
    /** @param array<string, string> $languages grammar name => provider id */
    public function __construct(
        public string $default = '',
        public array $languages = [],
    ) {}

    /**
     * Build from {@see \Porta\Config\IdeConfig}'s parsed `highlight` block, as
     * it arrives through the container's `PORTA_HIGHLIGHT` env channel.
     *
     * @param array{default?: string, languages?: array<string, string>} $highlight
     */
    public static function fromConfig(array $highlight): self
    {
        return new self($highlight['default'] ?? '', $highlight['languages'] ?? []);
    }

    /** The preferred provider id for $language, or null when none is configured. */
    public function preferredFor(string $language): ?string
    {
        $id = $this->languages[$language] ?? $this->default;

        return '' === $id ? null : $id;
    }
}
