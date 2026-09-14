<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\PortaJsonWriter;
use Porta\Config\RecommendedPlugins;
use Porta\Event\SettingsRowsStale;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginEnablement;
use Porta\Support\LanguageMap;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

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
    /**
     * Which backends claim each grammar, resolved at most once per generation.
     *
     * A provider only answers `supports()`, so the claim set is discovered by
     * probing every grammar against every backend — and {@see rows()} did that
     * walk TWICE, once in {@see allBackendIds()} and once in
     * {@see contestedLanguages()}. The settings nav rebuilds the page on every
     * arrow press, so the cost was paid per keypress.
     *
     * @var array<string, list<string>>
     */
    private array $claims = [];

    /** Row key for the global default; per-language keys are `lang:<grammar>`. */
    public const string DEFAULT_KEY = 'highlightDefault';

    /** The "no preference" option — distinct from a backend actually named ''. */
    private const string AUTO = '';

    /** Row key for the "enable the shipped engine" offer. */
    public const string ENABLE_KEY = 'enableBundledEngine';

    public function __construct(
        private readonly HighlightRegistry $registry,
        private readonly Translator $translator,
        private readonly HighlightPreferences $preferences,
        private readonly PortaJsonWriter $writer,
        private readonly SettingsScope $scope,
        private readonly SettingsProvenance $provenance,
        private readonly ?PluginEnablement $lifecycle = null,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $configured = $this->configuredLanguages();
        $rows = [];

        if ($this->canOfferTheBundledEngine()) {
            $rows[] = new SettingsRow(
                key: self::ENABLE_KEY,
                label: $this->translator->trans('No highlighting engine is enabled'),
                kind: SettingsRowKind::Action,
                value: $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugins::HIGHLIGHTER]),
                restart: false,
                hint: $this->translator->trans('Bundled plugins are opt-in; applies to files opened afterwards'),
            );
        }

        $rows = [
            ...$rows,
            new SettingsRow(
                key: self::DEFAULT_KEY,
                label: $this->translator->trans('Default highlighter'),
                kind: SettingsRowKind::Choice,
                value: $this->configuredDefault(),
                restart: false,
                default: self::AUTO,
                options: [self::AUTO, ...$this->allBackendIds()],
                optionLabels: [self::AUTO => $this->translator->trans('auto (first available)')],
                hint: $this->translator->trans('Used for any language without its own choice below'),
                origin: $this->provenance->originOf(['highlight', 'default']),
                shadowed: $this->provenance->shadowsGlobal(['highlight', 'default']),
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
                default: self::AUTO,
                options: [self::AUTO, ...$ids],
                optionLabels: [self::AUTO => $this->translator->trans('default')],
                origin: $this->provenance->originOf(['highlight', 'languages', $language]),
                shadowed: $this->provenance->shadowsGlobal(['highlight', 'languages', $language]),
            );
        }

        return $rows;
    }

    /**
     * Apply a row change to the live {@see HighlightPreferences} holder, then
     * persist it — mutate before write, like every core setting. Resolution
     * still happens once per buffer, so an already-open file keeps its backend;
     * files opened afterwards use the new choice.
     */
    public function change(string $key, string $value): void
    {
        if (self::ENABLE_KEY === $key) {
            // Enabling the engine is the one claim-set change this screen makes
            // itself, and the caller re-pulls these rows the moment it returns —
            // so drop the probe here rather than waiting for an event.
            $this->claims = [];
            $this->lifecycle?->enable(RecommendedPlugins::HIGHLIGHTER);

            return;
        }

        $language = self::DEFAULT_KEY === $key ? null : \substr($key, offset: 5);

        $this->preferences->change($language, $value);
        $this->writer->persistHighlighter($this->scope->current(), $language, $value);
    }

    /**
     * Whether to show the "enable the shipped engine" offer: nothing but the
     * plain fallback is available, and the bundled engine is installed but not
     * loaded. Without this a fresh project shows a Highlighting page whose only
     * choice is "no colouring", with no hint that an engine ships in the box.
     */
    private function canOfferTheBundledEngine(): bool
    {
        if (null === $this->lifecycle || [$this->plainId()] !== $this->allBackendIds()) {
            return false;
        }

        return \in_array(RecommendedPlugins::HIGHLIGHTER, $this->lifecycle->listedNames(), strict: true);
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

        return [...\array_keys($ids), $plain];
    }

    private function plainId(): string
    {
        // Nothing claims the empty language except the always-present fallback.
        // Through the same memo as every other grammar: this is asked once per
        // allBackendIds() call and there are several of those per page.
        return $this->backendIdsFor('')[0];
    }

    /** @return list<string> */
    private function backendIdsFor(string $language): array
    {
        if (\array_key_exists($language, $this->claims)) {
            return $this->claims[$language];
        }
        $ids = [];
        foreach ($this->registry->available($language) as $provider) {
            $ids[] = $provider->id;
        }

        return $this->claims[$language] = $ids;
    }

    /**
     * Forget which backends claim what.
     *
     * The claim set moves only when the loaded backends move, which is a
     * plugin being installed, removed, enabled or disabled — so this rides
     * {@see SettingsRowsStale} rather than a timer or a per-call re-probe.
     */
    #[AsEventListener]
    public function onSettingsRowsStale(SettingsRowsStale $event): void
    {
        $this->claims = [];
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
        return $this->preferences->defaultId();
    }

    /** @return array<string, string> */
    private function configuredLanguages(): array
    {
        return $this->preferences->languages();
    }
}
