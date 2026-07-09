<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;

/**
 * The editor settings model: applies the persisted values on boot and
 * flips/persists/flashes them on change. Each setting's label, kind and (for
 * the live ones) applier live in a single descriptor table, which also feeds
 * the settings screen's form rows.
 *
 * Every setting applies live: its `apply` closure mutates the running UI (the
 * editor, the theme, .editorconfig resolution, or the locale) the instant it
 * changes, and the value is persisted. The `restart` descriptor flag (and the
 * form's "applies on restart" note) is retained for any future setting that
 * genuinely needs a relaunch, but none do today.
 *
 * @phpstan-type Descriptor array{label: string, kind: string, restart: bool, apply: (\Closure(int|bool|string): void)|null, options?: list<string>, optionLabels?: array<string, string>, min?: int, max?: int}
 */
final class SettingsController
{
    /** @var array<string, Descriptor> */
    private readonly array $descriptors;

    /**
     * @param array<string, int|bool|string> $settings         resolved settings (defaults merged)
     * @param \Closure(string): void         $onLanguageChange     switch the UI locale + repaint
     * @param \Closure(string): void         $onThemeChange        switch the dark/light theme + repaint
     * @param \Closure(bool): void           $onEditorConfigChange toggle .editorconfig + re-resolve buffers
     */
    public function __construct(
        private array $settings,
        CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        \Closure $onLanguageChange,
        \Closure $onThemeChange,
        \Closure $onEditorConfigChange,
    ) {
        $this->descriptors = [
            'language' => [
                'label' => 'Language',
                'kind' => 'choice',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $onLanguageChange((string) $v),
                'options' => Translator::LOCALES,
                'optionLabels' => Translator::LOCALE_NAMES,
            ],
            'inlineDiagnostics' => ['label' => 'Inline diagnostics', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setInlineDiagnostics((bool) $v)],
            'relativeLineNumbers' => [
                'label' => 'Relative line numbers',
                'kind' => 'bool',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $editor->setRelativeLineNumbers((bool) $v),
            ],
            'autoPairs' => ['label' => 'Auto-pairs', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setAutoPairs((bool) $v)],
            'editorConfig' => ['label' => 'Respect .editorconfig', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $onEditorConfigChange((bool) $v)],
            'theme' => [
                'label' => 'Theme',
                'kind' => 'choice',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $onThemeChange((string) $v),
                'options' => ['dark', 'light'],
            ],
        ];
    }

    /** Apply every live (non-null-apply) setting to the editor (boot). */
    public function applyAll(): void
    {
        foreach ($this->descriptors as $key => $descriptor) {
            if (null !== $descriptor['apply']) {
                $descriptor['apply']($this->settings[$key]);
            }
        }
    }

    /**
     * The rows the settings form renders, current values pulled from state.
     *
     * @return list<SettingsRow>
     */
    public function formRows(): array
    {
        $rows = [];
        foreach ($this->descriptors as $key => $descriptor) {
            $raw = $this->settings[$key] ?? '';
            $value = 'bool' === $descriptor['kind'] ? (bool) $raw : (string) $raw;
            $rows[] = new SettingsRow(
                key: $key,
                label: $this->translator->trans($descriptor['label']),
                kind: $descriptor['kind'],
                value: $value,
                restart: $descriptor['restart'],
                options: $descriptor['options'] ?? null,
                optionLabels: $descriptor['optionLabels'] ?? null,
            );
        }

        return $rows;
    }

    /**
     * Flip a boolean setting to its opposite. Kept for the View-menu toggle
     * commands; an unknown key flashes instead of touching anything else.
     */
    public function toggle(string $key): void
    {
        if (!isset($this->descriptors[$key])) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return;
        }

        $this->changeSetting($key, !(bool) ($this->settings[$key] ?? false));
    }

    /**
     * Set a setting to a specific value from the settings form: live ones apply
     * to the editor immediately; the rest persist and take effect next launch.
     */
    public function changeSetting(string $key, int|bool|string $value): void
    {
        $descriptor = $this->descriptors[$key] ?? null;
        if (null === $descriptor) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return;
        }

        $this->settings[$key] = $value;
        if (null !== $descriptor['apply']) {
            $descriptor['apply']($value);
        }

        $persisted = IdeConfig::persistSetting($this->rootDir, $key, $value);
        $state = \is_bool($value) ? $this->translator->trans($value ? 'on' : 'off') : (string) $value;
        $note = $descriptor['restart'] ? ' · '.$this->translator->trans('applies on restart') : '';
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s %s%s%s', $this->translator->trans($descriptor['label']), $state, $note, $saved));
    }
}
