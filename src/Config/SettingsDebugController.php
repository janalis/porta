<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Debug\DebugAdapters;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;

/**
 * The per-language debug rows behind the settings view's Languages section:
 * the DAP adapter command and the launch-config JSON overrides. Edits persist
 * into .porta.json's `languages.<id>.debug` block and update the live
 * {@see DebugAdapters} registry; they apply to the next debug session.
 */
final class SettingsDebugController
{
    public function __construct(
        private readonly string $rootDir,
        private readonly DebugAdapters $adapters,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(string $language): array
    {
        $launch = $this->adapters->launchOverrides($language);

        return [
            new SettingsRow(
                key: 'debugAdapter',
                label: $this->translator->trans('Debug adapter'),
                kind: 'text',
                value: $this->adapters->adapterCommand($language),
                restart: false,
                hint: $this->translator->trans('not configured'),
            ),
            new SettingsRow(
                key: 'debugLaunch',
                label: $this->translator->trans('Launch config (JSON)'),
                kind: 'text',
                value: [] === $launch ? '' : (string) json_encode($launch, \JSON_UNESCAPED_SLASHES),
                restart: false,
                hint: $this->translator->trans('JSON merged into the DAP launch request'),
            ),
        ];
    }

    /** Route a settings edit for one of the two debug keys. */
    public function change(string $language, string $key, string $value): void
    {
        if ('debugAdapter' === $key) {
            $this->changeAdapter($language, trim($value));

            return;
        }
        $this->changeLaunch($language, trim($value));
    }

    private function changeAdapter(string $language, string $command): void
    {
        $persisted = IdeConfig::persistLanguageDebug($this->rootDir, $language, 'adapter', $command);
        $this->adapters->set($language, $command, $this->adapters->launchOverrides($language));

        $shown = '' === $command ? $this->translator->trans('not configured') : $command;
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s: %s%s', LanguageCatalog::displayName($language), $shown, $saved));
    }

    private function changeLaunch(string $language, string $json): void
    {
        $launch = [];
        if ('' !== $json) {
            /** @var array<array-key, mixed>|scalar|null $decoded */
            $decoded = json_decode($json, associative: true);
            if (!\is_array($decoded) || array_is_list($decoded)) {
                $this->notifier->flash($this->translator->trans('Invalid JSON — not saved'));

                return;
            }
            /** @var array<string, mixed> $decoded */
            $launch = $decoded;
        }

        $persisted = IdeConfig::persistLanguageDebug($this->rootDir, $language, 'launch', [] === $launch ? null : $launch);
        $this->adapters->set($language, $this->adapters->adapterCommand($language), $launch);

        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s: %s%s', LanguageCatalog::displayName($language), $this->translator->trans('Launch config (JSON)'), $saved));
    }
}
