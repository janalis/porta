<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;

/**
 * The per-language indent model behind the settings view's Languages section:
 * exposes each language's indent-style/size rows, persists a change into
 * .porta.json's `languages` block, and pushes the resolved map to the editor
 * (which re-applies it to the current document and every buffer opened after).
 */
final class SettingsLanguageController
{
    private const array STYLE_OPTIONS = ['auto', 'spaces', 'tabs'];
    private const array SIZE_OPTIONS = ['auto', '1', '2', '3', '4', '5', '6', '7', '8'];

    /** @var array<string, LanguageIndent> every catalog language, defaults filled */
    private array $config = [];

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}}> $overrides from {@see IdeConfig::$languages}
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
            new SettingsRow(key: 'indentStyle', label: $this->translator->trans('Indent style'), kind: 'choice', value: $entry->indentStyle, restart: false, options: self::STYLE_OPTIONS),
            new SettingsRow(key: 'indentSize', label: $this->translator->trans('Indent size'), kind: 'choice', value: (string) $entry->indentSize, restart: false, options: self::SIZE_OPTIONS),
        ];
    }

    /**
     * Set a language's indent style or size: persist it, push the map to the
     * editor (applies live), and flash. An unknown language/key flashes instead.
     */
    public function changeLanguageSetting(string $language, string $key, int|bool|string $value): void
    {
        $current = $this->config[$language] ?? null;
        if (null === $current || 'indentStyle' !== $key && 'indentSize' !== $key) {
            $this->notifier->flash($this->translator->trans('Unknown language setting "%key%"', ['%key%' => $language.'.'.$key]));

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
}
