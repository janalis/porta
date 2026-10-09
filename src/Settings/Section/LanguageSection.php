<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\Config\LanguageCatalog;
use Porta\Settings\SettingsDebugController;
use Porta\Settings\SettingsLanguageController;
use Porta\Settings\SettingsLspController;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsScopeRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A `lang:<id>` page. One page, three controllers, three fieldsets: the
 * language's indent rows, then its language server, then running and
 * debugging — the language's run command followed by the debug adapter's
 * rows, so the group a legend heads is contiguous. This section is the seam
 * that keeps that composition out of the view.
 *
 * The edit is routed by key because the three controllers own disjoint keys of
 * a single flat form.
 */
#[AsTaggedItem(priority: 200)]
final readonly class LanguageSection implements SettingsSection
{
    private const string PREFIX = 'lang:';

    public function __construct(
        private SettingsLanguageController $languages,
        private SettingsLspController $lsp,
        private SettingsDebugController $debug,
        private SettingsScopeRow $scopeRow,
    ) {}

    #[\Override]
    public function handles(string $section): bool
    {
        return \str_starts_with($section, self::PREFIX);
    }

    /**
     * One page per catalogued language.
     *
     * Names come from {@see LanguageCatalog} and are NOT translated — 'PHP'
     * and 'JavaScript' are what they are called, which is the rule the nav
     * renders by.
     *
     * @return list<SettingsPage>
     */
    #[\Override]
    public function pages(): array
    {
        $pages = [];
        foreach (LanguageCatalog::LANGUAGES as $id => $name) {
            $pages[] = new SettingsPage(self::PREFIX.$id, $name);
        }

        return $pages;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        $language = $this->language($section);

        return $this->scopeRow->lead([...$this->languages->indentRows($language), ...$this->lsp->rows($language), ...$this->languages->runRows($language), ...$this->debug->rows($language)]);
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): bool
    {
        if ($this->scopeRow->owns($key)) {
            $this->scopeRow->change($value);

            return true;
        }
        $language = $this->language($section);

        return match ($key) {
            'lspServer' => $this->lsp->changeLspServer($language, (string) $value),
            'debugAdapter', 'debugLaunch' => $this->debug->change($language, $key, (string) $value),
            default => $this->changeLanguageSetting($language, $key, $value),
        };
    }

    /**
     * Every row on a language page already spells its own default as a blank
     * or as `auto` — an unset LSP command auto-detects, an unset adapter is
     * "not configured", an unset indent follows the file. So reset is the
     * ordinary change to that value, and the three writers behind it already
     * remove the key rather than storing the blank.
     */
    #[\Override]
    public function reset(string $section, string $key): void
    {
        $this->change($section, $key, \in_array($key, self::AUTO_KEYS, strict: true) ? 'auto' : '');
    }

    /** The two rows whose default is spelled `auto` rather than blank. */
    private const array AUTO_KEYS = ['indentStyle', 'indentSize'];

    private function changeLanguageSetting(string $language, string $key, bool|int|string $value): bool
    {
        $this->languages->changeLanguageSetting($language, $key, $value);

        return true;
    }

    private function language(string $section): string
    {
        return \substr($section, offset: \strlen(self::PREFIX));
    }
}
