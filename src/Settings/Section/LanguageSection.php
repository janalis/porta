<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\Settings\SettingsDebugController;
use Porta\Settings\SettingsLanguageController;
use Porta\Settings\SettingsLspController;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A `lang:<id>` page. One page, three controllers: the language's own indent
 * and formatting rows, then its language server, then its debug adapter — so
 * this section is the seam that keeps that composition out of the view.
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
    ) {}

    #[\Override]
    public function handles(string $section): bool
    {
        return str_starts_with($section, self::PREFIX);
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        $language = $this->language($section);

        return [...$this->languages->rows($language), ...$this->lsp->rows($language), ...$this->debug->rows($language)];
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): void
    {
        $language = $this->language($section);
        if ('lspServer' === $key) {
            $this->lsp->changeLspServer($language, (string) $value);

            return;
        }
        if ('debugAdapter' === $key || 'debugLaunch' === $key) {
            $this->debug->change($language, $key, (string) $value);

            return;
        }

        $this->languages->changeLanguageSetting($language, $key, $value);
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

    private function language(string $section): string
    {
        return substr($section, offset: \strlen(self::PREFIX));
    }
}
