<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Highlight\HighlightRegistry;
use Porta\I18n\Translator;
use Porta\Support\LanguageMap;

/**
 * The Settings → Highlighting page: which syntax-highlighting backend serves
 * which language.
 *
 * The rows are **derived, not declared**. Available backends depend on which
 * plugins loaded, so this cannot live in {@see SettingsController}'s static
 * descriptor table; and the values persist to the top-level `highlight` block
 * rather than `settings`, which only holds scalars.
 *
 * Only languages where a real choice exists get a row. A backend's claim list
 * isn't introspectable — {@see \Porta\Highlight\SyntaxHighlightProvider} only
 * answers `supports()` — so the page probes every known grammar
 * ({@see LanguageMap::grammars()}) and keeps the ones two or more backends
 * claim, plus anything already configured (so an override can be seen and
 * cleared even after the plugin providing it is gone).
 */
final class SettingsHighlightController
{
    /** Row key for the global default; per-language keys are `lang:<grammar>`. */
    public const string DEFAULT_KEY = 'highlightDefault';

    /** The "no preference" option — distinct from a backend actually named ''. */
    private const string AUTO = '';

    public function __construct(
        private readonly HighlightRegistry $registry,
        private readonly Translator $translator,
        private readonly string $rootDir,
        private readonly IdeConfig $config,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $configured = $this->configuredLanguages();
        $rows = [
            new SettingsRow(
                key: self::DEFAULT_KEY,
                label: $this->translator->trans('Default highlighter'),
                kind: SettingsRowKind::Choice,
                value: $this->configuredDefault(),
                restart: false,
                options: [self::AUTO, ...$this->allBackendIds()],
                optionLabels: [self::AUTO => $this->translator->trans('auto (first available)')],
                hint: $this->translator->trans('Used for any language without its own choice below'),
            ),
        ];

        foreach ($this->contestedLanguages($configured) as $language) {
            $ids = $this->backendIdsFor($language);
            $rows[] = new SettingsRow(
                key: 'lang:'.$language,
                label: $language,
                kind: SettingsRowKind::Choice,
                value: $configured[$language] ?? self::AUTO,
                restart: false,
                options: [self::AUTO, ...$ids],
                optionLabels: [self::AUTO => $this->translator->trans('default')],
            );
        }

        return $rows;
    }

    /**
     * Persist a row change. Takes effect for files opened afterwards — the
     * backend is resolved once per buffer, like the plugin caveat on default
     * keybindings.
     */
    public function change(string $key, string $value): void
    {
        $language = self::DEFAULT_KEY === $key ? null : substr($key, offset: 5);

        IdeConfig::persistHighlighter($this->rootDir, $language, $value);
    }

    /**
     * Every backend id offered anywhere, in plugin load order with the plain
     * fallback last.
     *
     * Collected by probing rather than asked for: a provider only answers
     * `supports()`. The fallback is pinned to the end because it claims every
     * language and would otherwise sort into the middle of the list, reading
     * like a real engine rather than the "no colouring" choice it is.
     *
     * @return list<string>
     */
    private function allBackendIds(): array
    {
        $ids = [];
        foreach (LanguageMap::grammars() as $grammar) {
            foreach ($this->backendIdsFor($grammar) as $id) {
                $ids[$id] = true;
            }
        }
        $plain = $this->plainId();
        unset($ids[$plain]);

        return [...array_keys($ids), $plain];
    }

    private function plainId(): string
    {
        // Nothing claims the empty language except the always-present fallback.
        return $this->registry->available('')[0]->id();
    }

    /** @return list<string> */
    private function backendIdsFor(string $language): array
    {
        $ids = [];
        foreach ($this->registry->available($language) as $provider) {
            $ids[] = $provider->id();
        }

        return $ids;
    }

    /**
     * Languages worth a row: two or more backends claim them (the plain
     * fallback always claims, so this means at least one real engine plus a
     * choice), or the user already configured one.
     *
     * @param array<string, string> $configured
     *
     * @return list<string>
     */
    private function contestedLanguages(array $configured): array
    {
        $languages = [];
        foreach (LanguageMap::grammars() as $grammar) {
            if (\count($this->backendIdsFor($grammar)) <= 2 && !\array_key_exists($grammar, $configured)) {
                continue;
            }

            $languages[] = $grammar;
        }

        return $languages;
    }

    private function configuredDefault(): string
    {
        return $this->config->highlight['default'];
    }

    /** @return array<string, string> */
    private function configuredLanguages(): array
    {
        return $this->config->highlight['languages'];
    }
}
