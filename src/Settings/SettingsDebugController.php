<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\LanguageCatalog;
use Porta\Config\PortaJsonWriter;
use Porta\Debug\DebugAdapters;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;

/**
 * The per-language debug rows behind the settings view's Languages section:
 * the DAP adapter command and the launch-config JSON overrides. Edits persist
 * into .porta.json's `languages.<id>.debug` block and update the live
 * {@see DebugAdapters} registry; they apply to the next debug session.
 */
final readonly class SettingsDebugController
{
    public function __construct(
        private DebugAdapters $adapters,
        private Notifier $notifier,
        private Translator $translator,
        private PortaJsonWriter $writer,
        private SettingsScope $scope,
        private SettingsProvenance $provenance,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(string $language): array
    {
        $launch = $this->adapters->launchOverrides($language);

        // @noinspection JsonEncodingApiUsageInspection -- a launch config that cannot be encoded shows as an empty field, not a crash (SettingsDebugControllerTest)
        return [
            new SettingsRow(
                key: 'debugAdapter',
                label: $this->translator->trans('Debug adapter'),
                kind: SettingsRowKind::Text,
                value: $this->adapters->adapterCommand($language),
                restart: false,
                default: '', // unset means no adapter configured
                hint: $this->translator->trans('not configured'),
                origin: $this->provenance->originOf(['languages', $language, 'debug', 'adapter']),
                shadowed: $this->provenance->shadowsGlobal(['languages', $language, 'debug', 'adapter']),
                fieldset: $this->translator->trans('Run & debug'),
            ),
            new SettingsRow(
                key: 'debugLaunch',
                label: $this->translator->trans('Launch config (JSON)'),
                kind: SettingsRowKind::Text,
                value: [] === $launch ? '' : (string) \json_encode($launch, \JSON_UNESCAPED_SLASHES),
                restart: false,
                default: '',
                hint: $this->translator->trans('JSON merged into the DAP launch request'),
                origin: $this->provenance->originOf(['languages', $language, 'debug', 'launch']),
                shadowed: $this->provenance->shadowsGlobal(['languages', $language, 'debug', 'launch']),
                fieldset: $this->translator->trans('Run & debug'),
            ),
        ];
    }

    /** Route a settings edit for one of the two debug keys. */
    public function change(string $language, string $key, string $value): bool
    {
        if ('debugAdapter' === $key) {
            $this->changeAdapter($language, \trim($value));

            return true;
        }

        return $this->changeLaunch($language, \trim($value));
    }

    private function changeAdapter(string $language, string $command): void
    {
        $persisted = $this->writer->persistLanguageDebug($this->scope->current(), $language, 'adapter', $command);
        $this->adapters->set($language, $command, $this->adapters->launchOverrides($language));

        $shown = '' === $command ? $this->translator->trans('not configured') : $command;
        $saved = SettingsFlash::savedSuffix($this->translator, $persisted);
        $this->notifier->flash(\sprintf('%s: %s%s', LanguageCatalog::displayName($language), $shown, $saved));
    }

    private function changeLaunch(string $language, string $json): bool
    {
        $launch = [];
        if ('' !== $json) {
            /** @var array<array-key, mixed>|scalar|null $decoded */
            // @noinspection JsonEncodingApiUsageInspection -- what the user typed in the field: text that is not JSON is refused below with a message, not thrown
            $decoded = \json_decode($json, associative: true);
            if (!\is_array($decoded) || \array_is_list($decoded)) {
                // FALSE, so the form keeps the editor open with what was
                // typed: the row used to snap back to the persisted value and
                // the user's text was gone with no way to correct it.
                $this->notifier->error($this->translator->trans('Invalid JSON — not saved'));

                return false;
            }
            /** @var array<string, mixed> $decoded */
            $launch = $decoded;
        }

        $persisted = $this->writer->persistLanguageDebug($this->scope->current(), $language, 'launch', [] === $launch ? null : $launch);
        $this->adapters->set($language, $this->adapters->adapterCommand($language), $launch);

        $saved = SettingsFlash::savedSuffix($this->translator, $persisted);
        $this->notifier->flash(\sprintf('%s: %s%s', LanguageCatalog::displayName($language), $this->translator->trans('Launch config (JSON)'), $saved));

        return true;
    }
}
