<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The per-language indent model behind the settings view's Languages section:
 * exposes each language's indent-style/size rows, persists a change into
 * .porta.json's `languages` block, and pushes the resolved map to the editor
 * (which re-applies it to the current document and every buffer opened after).
 *
 * @phpstan-type FormRow array{key: string, label: string, kind: string, value: bool|int|string, restart: bool, options?: list<string>, min?: int, max?: int}
 */
final class SettingsLanguageController
{
    private const array STYLE_OPTIONS = ['auto', 'spaces', 'tabs'];
    private const array SIZE_OPTIONS = ['auto', '1', '2', '3', '4', '5', '6', '7', '8'];

    /** @var array<string, LanguageIndent> every catalog language, defaults filled */
    private array $config = [];

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string}> $overrides from {@see IdeConfig::$languages}
     */
    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly string $rootDir,
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
     * @return list<FormRow>
     */
    public function rows(string $language): array
    {
        $entry = $this->config[$language] ?? new LanguageIndent();

        return [
            ['key' => 'indentStyle', 'label' => 'Indent style', 'kind' => 'choice', 'value' => $entry->indentStyle, 'restart' => false, 'options' => self::STYLE_OPTIONS],
            ['key' => 'indentSize', 'label' => 'Indent size', 'kind' => 'choice', 'value' => (string) $entry->indentSize, 'restart' => false, 'options' => self::SIZE_OPTIONS],
        ];
    }

    /**
     * Set a language's indent style or size: persist it, push the map to the
     * editor (applies live), and flash. An unknown language/key flashes instead.
     */
    public function changeLanguageSetting(string $language, string $key, int|bool|string $value): void
    {
        $current = $this->config[$language] ?? null;
        if (null === $current || ('indentStyle' !== $key && 'indentSize' !== $key)) {
            $this->statusBar->flash(\sprintf('Unknown language setting "%s.%s"', $language, $key));
            $this->tui->requestRender();

            return;
        }

        $stored = 'indentSize' === $key && 'auto' !== $value && is_numeric((string) $value) ? (int) $value : (string) $value;
        $this->config[$language] = 'indentStyle' === $key
            ? new LanguageIndent((string) $stored, $current->indentSize)
            : new LanguageIndent($current->indentStyle, $stored);

        $persisted = IdeConfig::persistLanguageSetting($this->rootDir, $language, $key, $stored);
        $this->applyToEditor();
        $label = 'indentSize' === $key ? 'indent size' : 'indent style';
        $this->statusBar->flash(\sprintf('%s %s: %s%s', LanguageCatalog::displayName($language), $label, $stored, $persisted ? '' : ' (not saved)'));
        $this->tui->requestRender();
    }
}
