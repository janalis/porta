<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\I18n\Translator;
use Porta\Settings\LiveSettingsApplier;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;

/**
 * The editor settings model: applies the persisted values on boot and
 * flips/persists/flashes them on change. Each setting's label, kind and (for
 * the live ones) applier live in a single descriptor table, which also feeds
 * the settings screen's form rows.
 *
 * Every setting applies live: its `apply` closure mutates the running UI (the
 * editor, the theme, the cursor shape, or the locale) the instant it
 * changes, and the value is persisted. The `restart` descriptor flag (and the
 * form's "applies on restart" note) is retained for any future setting that
 * genuinely needs a relaunch, but none do today.
 *
 * @phpstan-type Descriptor array{label: string, kind: SettingsRowKind, restart: bool, apply: (\Closure(int|bool|string): void)|null, options?: list<string>, optionLabels?: array<string, string>, min?: int, max?: int}
 */
final class SettingsController
{
    /** @var array<string, Descriptor> */
    private readonly array $descriptors;

    /**
     * @param array<string, int|bool|string> $settings         resolved settings (defaults merged)
     */
    public function __construct(
        private array $settings,
        CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        LiveSettingsApplier $applier,
    ) {
        $this->descriptors = [
            'language' => [
                'label' => 'Language',
                'kind' => SettingsRowKind::Choice,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyLocale((string) $v),
                'options' => Translator::LOCALES,
                'optionLabels' => Translator::LOCALE_NAMES,
            ],
            'inlineDiagnostics' => [
                'label' => 'Inline diagnostics',
                'kind' => SettingsRowKind::Bool,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableInlineDiagnostics() : $editor->disableInlineDiagnostics(),
            ],
            'relativeLineNumbers' => [
                'label' => 'Relative line numbers',
                'kind' => SettingsRowKind::Bool,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableRelativeLineNumbers() : $editor->disableRelativeLineNumbers(),
            ],
            'autoPairs' => [
                'label' => 'Auto-pairs',
                'kind' => SettingsRowKind::Bool,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableAutoPairs() : $editor->disableAutoPairs(),
            ],
            'formatOnSave' => [
                'label' => 'Format on save (LSP)',
                'kind' => SettingsRowKind::Bool,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $applier->enableFormatOnSave() : $applier->disableFormatOnSave(),
            ],
            'theme' => [
                'label' => 'Theme',
                'kind' => SettingsRowKind::Choice,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTheme((string) $v),
                'options' => ['dark', 'light', 'high-contrast'],
            ],
            'cursorShape' => [
                'label' => 'Cursor shape',
                'kind' => SettingsRowKind::Choice,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyCursorShape((string) $v),
                'options' => ['bar', 'block', 'underline'],
            ],
            'tabBarPosition' => [
                'label' => 'Tab bar position',
                'kind' => SettingsRowKind::Choice,
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTabBarPosition((string) $v),
                'options' => ['top', 'bottom', 'left', 'right'],
            ],
        ];
    }

    /** Apply every live (non-null-apply) setting to the editor (boot). */
    public function applyAll(): void
    {
        foreach ($this->descriptors as $key => $descriptor) {
            if (null === $descriptor['apply']) {
                continue;
            }

            $descriptor['apply']($this->settings[$key]);
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
            $value = SettingsRowKind::Bool === $descriptor['kind'] ? (bool) $raw : (string) $raw;
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
        if (($this->descriptors[$key] ?? null) === null) {
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
