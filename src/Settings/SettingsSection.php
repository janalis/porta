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

    /** @return list<SettingsRow> */
    public function rows(string $section): array;

    public function change(string $section, string $key, bool|int|string $value): void;
}
