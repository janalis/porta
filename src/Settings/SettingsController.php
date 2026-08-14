<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\PortaJsonWriter;
use Porta\Container\AsIdeService;
use Porta\Editor\EditorViewOptions;
use Porta\Editor\LineNumberMode;
use Porta\I18n\Translator;
use Porta\Ui\Theme\Theme;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The editor settings model: applies the persisted values on boot and
 * flips/persists/flashes them on change. Each setting's label, kind and (for
 * the live ones) applier live in a single descriptor table, which also feeds
 * the settings screen's form rows.
 *
 * Every setting applies live: its `apply` closure mutates the running UI (the
 * editor, the theme, the cursor shape, or the locale) the instant it
 * changes, and the value is persisted. (The viewer-tool paths — Graphviz,
 * SVG converter, PlantUML — live on their PLUGINS' settings pages now, with
 * the graph generator command; they were the table's only restart-required
 * rows.)
 *
 * @phpstan-type Descriptor array{label: string, kind: SettingsRowKind, group: 'general'|'interface', restart: bool, apply: (\Closure(int|bool|string): void)|null, options?: list<string>, optionLabels?: array<string, string>, min?: int, max?: int}
 */
#[AsIdeService]
final class SettingsController
{
    /** @var array<string, Descriptor> */
    private readonly array $descriptors;

    /**
     * @param array<string, int|bool|string> $settings         resolved settings (defaults merged)
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_SETTINGS)%')]
        private array $settings,
        EditorViewOptions $editor,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        private readonly PortaJsonWriter $writer,
        LiveSettingsApplier $applier,
        BreadcrumbsWidget $breadcrumbs,
    ) {
        $this->descriptors = [
            // --- Interface (the "Interface" settings page) ---
            'language' => [
                'label' => 'Language',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyLocale((string) $v),
                'options' => Translator::LOCALES,
                'optionLabels' => Translator::LOCALE_NAMES,
            ],
            'theme' => [
                'label' => 'Theme',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTheme((string) $v),
                'options' => Theme::ids(),
            ],
            'cursorShape' => [
                'label' => 'Cursor shape',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyCursorShape((string) $v),
                'options' => ['bar', 'block', 'underline'],
            ],
            'tabBarPosition' => [
                'label' => 'Tab bar position',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTabBarPosition((string) $v),
                'options' => ['top', 'bottom', 'left', 'right'],
            ],
            'lineNumbers' => [
                'label' => 'Line numbers',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $editor->setLineNumberMode(LineNumberMode::tryFrom((string) $v) ?? LineNumberMode::Absolute),
                'options' => ['off', 'absolute', 'relative'],
            ],
            'stickyScroll' => [
                'label' => 'Sticky scroll',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableStickyScroll() : $editor->disableStickyScroll(),
            ],
            'indentGuides' => [
                'label' => 'Indent guides',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableIndentGuides() : $editor->disableIndentGuides(),
            ],
            'breadcrumbs' => [
                'label' => 'Breadcrumbs bar',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $breadcrumbs->show() : $breadcrumbs->hide(),
            ],
            // --- General (editor behavior) ---
            'inlineDiagnostics' => [
                'label' => 'Inline diagnostics',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableInlineDiagnostics() : $editor->disableInlineDiagnostics(),
            ],
            'autoPairs' => [
                'label' => 'Auto-pairs',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $editor->enableAutoPairs() : $editor->disableAutoPairs(),
            ],
            'formatOnSave' => [
                'label' => 'Format on save (LSP)',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $applier->enableFormatOnSave() : $applier->disableFormatOnSave(),
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
     * The General page's rows (editor behavior), current values pulled from state.
     *
     * @return list<SettingsRow>
     */
    public function formRows(): array
    {
        return $this->rowsForGroup('general');
    }

    /**
     * The Interface page's rows (appearance), current values pulled from state.
     *
     * @return list<SettingsRow>
     */
    public function interfaceRows(): array
    {
        return $this->rowsForGroup('interface');
    }

    /**
     * @param 'general'|'interface' $group
     *
     * @return list<SettingsRow>
     */
    private function rowsForGroup(string $group): array
    {
        $rows = [];
        foreach ($this->descriptors as $key => $descriptor) {
            if ($group !== $descriptor['group']) {
                continue;
            }
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

    /** Cycle the line-numbers mode off → absolute → relative → off (the View menu command). */
    public function cycleLineNumbers(): void
    {
        $next = match ((string) ($this->settings['lineNumbers'] ?? 'absolute')) {
            'off' => 'absolute',
            'absolute' => 'relative',
            default => 'off',
        };
        $this->changeSetting('lineNumbers', $next);
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

        $persisted = $this->writer->persistSetting($this->rootDir, $key, $value);
        $state = \is_bool($value) ? $this->translator->trans($value ? 'on' : 'off') : (string) $value;
        $note = $descriptor['restart'] ? ' · '.$this->translator->trans('applies on restart') : '';
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s %s%s%s', $this->translator->trans($descriptor['label']), $state, $note, $saved));
    }
}
