<?php

declare(strict_types=1);

namespace Porta\Settings;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One page of the settings view: it claims a nav id, produces that page's rows,
 * and applies a row's edit. The interface-level `#[AutoconfigureTag]` tags every
 * implementation `porta.settings_section`; {@see SettingsViewController}
 * consumes the tag as a first-match-wins list.
 *
 * The nav id carries its own argument for the parameterised pages
 * (`plugin:<name>`, `lang:<id>`), which is why every method takes it: a section
 * is a *family* of pages, not a single one.
 *
 * `#[AsTaggedItem]` priority is contractual — the general page claims every id
 * as the fallback, so it must sort last.
 */
#[AutoconfigureTag('porta.settings_section')]
interface SettingsSection
{
    /** Does this section own the nav id ('interface', 'plugin:foo', 'lang:php', …)? */
    public function handles(string $section): bool;

    /**
     * Every page this section can show, display labels included.
     *
     * A section is a FAMILY of pages, so this is where the family lists itself:
     * the plugins section knows which plugins are installed and the language
     * section knows the catalog. Anything that needs the full set of pages —
     * searching across them, say — asks here rather than growing a second copy
     * of a list the nav already hardcodes, which is how a page ends up
     * reachable in one and missing from the other.
     *
     * @return list<SettingsPage>
     */
    public function pages(): array;

    /** @return list<SettingsRow> */
    public function rows(string $section): array;

    /**
     * Apply a row's edit. FALSE means the value was refused — the form keeps
     * the inline editor open with the text as typed, so a rejection is
     * something to correct rather than something that eats your work.
     */
    public function change(string $section, string $key, bool|int|string $value): bool;

    /**
     * Put one row back to its default.
     *
     * Separate from `change()` because "back to default" is not a value the
     * user can always type: for a core or plugin setting it REMOVES the key,
     * so the row follows the product's default if that ever moves. Where a
     * blank already means "unset" — an LSP command, a debug adapter, a
     * highlighter choice — this is `change($section, $key, '')`.
     */
    public function reset(string $section, string $key): void;
}
