<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\IdeConfig;
use Porta\Config\LanguageCatalog;
use Porta\Config\LanguageIndent;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;

/**
 * The per-language indent model behind the settings view's Languages section:
 * exposes each language's indent-style/size rows, persists a change into
 * .porta.json's `languages` block, and pushes the resolved map to the editor
 * (which re-applies it to the current document and every buffer opened after).
 */
#[AsIdeService]
final class SettingsLanguageController
{
    private const array STYLE_OPTIONS = ['auto', 'spaces', 'tabs'];
    private const array SIZE_OPTIONS = ['auto', '1', '2', '3', '4', '5', '6', '7', '8'];

    /** @var array<string, LanguageIndent> every catalog language, defaults filled */
    private array $config = [];

    /** @var array<string, string> languageId => configured run/debug command ('' = default interpreter) */
    private array $runCommand = [];

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}> $overrides from {@see IdeConfig::$languages}
     */
    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        array $overrides,
    ) {
        foreach (LanguageCatalog::ids() as $id) {
            $entry = $overrides[$id] ?? [];
            $this->config[$id] = new LanguageIndent($entry['indentStyle'] ?? 'auto', $entry['indentSize'] ?? 'auto');
            $this->runCommand[$id] = $entry['runCommand'] ?? '';
        }
    }

    /** Push the resolved per-language config to the editor (boot + after a change). */
    public function applyToEditor(): void
    {
        $this->editor->setLanguageIndents($this->config);
    }

    /**
     * The indent rows for a language section: style and size, both choices with
     * an 'auto' option.
     *
     * @return list<SettingsRow>
     */
    public function rows(string $language): array
    {
        $entry = $this->config[$language] ?? new LanguageIndent();

        return [
            new SettingsRow(
                key: 'indentStyle',
                label: $this->translator->trans('Indent style'),
                kind: SettingsRowKind::Choice,
                value: $entry->indentStyle,
                restart: false,
                options: self::STYLE_OPTIONS,
            ),
            new SettingsRow(
                key: 'indentSize',
                label: $this->translator->trans('Indent size'),
                kind: SettingsRowKind::Choice,
                value: (string) $entry->indentSize,
                restart: false,
                options: self::SIZE_OPTIONS,
            ),
            new SettingsRow(
                key: 'runCommand',
                label: $this->translator->trans('Run/debug command'),
                kind: SettingsRowKind::Text,
                value: $this->runCommand[$language] ?? '',
                restart: true,
                hint: $this->translator->trans('e.g. frankenphp php-cli — empty = bundled php'),
            ),
        ];
    }

    /**
     * Set a language's indent style or size: persist it, push the map to the
     * editor (applies live), and flash. An unknown language/key flashes instead.
     */
    public function changeLanguageSetting(string $language, string $key, int|bool|string $value): void
    {
        $current = $this->config[$language] ?? null;
        if (null === $current || !\in_array($key, ['indentStyle', 'indentSize', 'runCommand'], strict: true)) {
            $this->notifier->flash($this->translator->trans('Unknown language setting "%key%"', ['%key%' => $language.'.'.$key]));

            return;
        }

        if ('runCommand' === $key) {
            $this->changeRunCommand($language, trim((string) $value));

            return;
        }

        $stored = 'indentSize' === $key && 'auto' !== $value && is_numeric((string) $value) ? (int) $value : (string) $value;
        $this->config[$language] = 'indentStyle' === $key
            ? new LanguageIndent((string) $stored, $current->indentSize)
            : new LanguageIndent($current->indentStyle, $stored);

        $persisted = IdeConfig::persistLanguageSetting($this->rootDir, $language, $key, $stored);
        $this->applyToEditor();
        $label = $this->translator->trans('indentSize' === $key ? 'indent size' : 'indent style');
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s %s: %s%s', LanguageCatalog::displayName($language), $label, $stored, $saved));
    }

    /**
     * Set a language's run/debug interpreter command. Persisted for the next
     * launch (RunConfigs and the debug launch are seeded at boot).
     */
    private function changeRunCommand(string $language, string $command): void
    {
        $this->runCommand[$language] = $command;
        $persisted = IdeConfig::persistLanguageSetting($this->rootDir, $language, 'runCommand', $command);
        $note = ' · '.$this->translator->trans('applies on restart');
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $shown = '' === $command ? $this->translator->trans('bundled php') : $command;
        $this->notifier->flash(\sprintf('%s run command: %s%s%s', LanguageCatalog::displayName($language), $shown, $note, $saved));
    }
}
