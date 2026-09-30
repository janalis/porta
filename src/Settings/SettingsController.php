<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Container\AsIdeService;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\LineNumberMode;
use Porta\Editor\PreviewRoutes;
use Porta\I18n\Translator;
use Porta\Overlay\State\TooltipCenter;
use Porta\Search\FileIndex;
use Porta\Ui\Theme\Theme;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\MarkdownPreviewWidget;
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
 * the graph generator command. They were the table's only restart-required
 * rows while it owned them, and are live there too: each engine resolves its
 * binary per spawn.)
 *
 * @phpstan-type Descriptor array{label: string, kind: SettingsRowKind, group: 'general'|'interface', fieldset: string, restart: bool, apply: (\Closure(int|bool|string): void)|null, options?: list<string>, optionLabels?: array<string, string>, min?: int, max?: int, step?: int}
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
        EditorDisplayOptions $editor,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly PortaJsonWriter $writer,
        private readonly SettingsScope $scope,
        private readonly SettingsProvenance $provenance,
        LiveSettingsApplier $applier,
        BreadcrumbsWidget $breadcrumbs,
        FileIndex $files,
        TooltipCenter $tooltips,
        PreviewRoutes $previews,
    ) {
        $this->descriptors = [
            // --- Interface (the "Interface" settings page) ---
            'language' => [
                'label' => 'Language',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Display',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyLocale((string) $v),
                'options' => Translator::LOCALES,
                'optionLabels' => Translator::LOCALE_NAMES,
            ],
            'theme' => [
                'label' => 'Theme',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Display',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTheme((string) $v),
                'options' => Theme::ids(),
            ],
            'cursorShape' => [
                'label' => 'Cursor shape',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Display',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyCursorShape((string) $v),
                'options' => ['bar', 'block', 'underline'],
            ],
            'tabBarPosition' => [
                'label' => 'Tab bar position',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Layout',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyTabBarPosition((string) $v),
                'options' => ['top', 'bottom', 'left', 'right'],
            ],
            'breadcrumbs' => [
                'label' => 'Breadcrumbs bar',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Layout',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $breadcrumbs->show() : $breadcrumbs->hide(),
            ],
            'lineNumbers' => [
                'label' => 'Line numbers',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setLineNumbers(LineNumberMode::tryFrom((string) $v) ?? LineNumberMode::Absolute),
                'options' => ['off', 'absolute', 'relative'],
            ],
            'stickyScroll' => [
                'label' => 'Sticky scroll',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setStickyScroll((bool) $v),
            ],
            'indentGuides' => [
                'label' => 'Indent guides',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setIndentGuides((bool) $v),
            ],
            'showWhitespace' => [
                'label' => 'Show whitespace',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setShowWhitespace((bool) $v),
            ],
            'methodSeparators' => [
                'label' => 'Method separators',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setMethodSeparators((bool) $v),
            ],
            'diagnosticMessages' => [
                'label' => 'Diagnostic messages',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setDiagnosticMessages(DiagnosticMessageMode::tryFrom((string) $v) ?? DiagnosticMessageMode::Inline),
                'options' => ['none', 'inline', 'menu'],
            ],
            'inlayHints' => [
                'label' => 'Inlay hints',
                'kind' => SettingsRowKind::Choice,
                'group' => 'interface',
                'fieldset' => 'Editor',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $applier->applyInlayHints((string) $v),
                'options' => ['none', 'inline', 'tooltip'],
            ],
            'tooltips' => [
                'label' => 'Show tooltips',
                'kind' => SettingsRowKind::Bool,
                'group' => 'interface',
                'fieldset' => 'Tooltips',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $tooltips->setEnabled((bool) $v),
            ],
            'tooltipDelay' => [
                'label' => 'Tooltip delay (ms)',
                'kind' => SettingsRowKind::Int,
                'group' => 'interface',
                'fieldset' => 'Tooltips',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $tooltips->setDelayMs((int) $v),
                'min' => 0,
                'max' => 2000,
                'step' => 100,
            ],
            // --- General (editor behavior) ---
            'autoPairs' => [
                'label' => 'Auto-pairs',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'fieldset' => 'Editing',
                'restart' => false,
                'apply' => static fn (int|bool|string $v): bool => $editor->setAutoPairs((bool) $v),
            ],
            'formatOnSave' => [
                'label' => 'Format on save (LSP)',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'fieldset' => 'Editing',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => (bool) $v ? $applier->enableFormatOnSave() : $applier->disableFormatOnSave(),
            ],
            // Files opened AFTER the change follow it, as the viewer plugins'
            // own SVG and PlantUML toggles do: an open tab keeps its preview.
            'markdownPreview' => [
                'label' => 'Markdown preview',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'fieldset' => 'Editing',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $previews->enable(MarkdownPreviewWidget::PANE_ID, (bool) $v),
            ],
            'searchIncludeVendor' => [
                'label' => 'Search vendor/ files',
                'kind' => SettingsRowKind::Bool,
                'group' => 'general',
                'fieldset' => 'Search',
                'restart' => false,
                'apply' => static fn (int|bool|string $v) => $files->setIncludeVendor((bool) $v),
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
            $rows[] = new SettingsRow(
                key: $key,
                label: $this->translator->trans($descriptor['label']),
                kind: $descriptor['kind'],
                value: self::coerce($this->settings[$key] ?? '', $descriptor['kind']),
                restart: $descriptor['restart'],
                options: $descriptor['options'] ?? null,
                min: $descriptor['min'] ?? null,
                max: $descriptor['max'] ?? null,
                optionLabels: $descriptor['optionLabels'] ?? null,
                step: $descriptor['step'] ?? null,
                // Through the SAME call as the value above, so the two operands
                // of isModified() cannot be produced differently. DEFAULT_SETTINGS
                // holds well-typed values today, so this is symmetry rather than
                // a live fix — but a loosely-typed default added later would
                // otherwise make every row on its page read as modified, and
                // nothing else would catch that.
                default: self::coerce(IdeConfig::DEFAULT_SETTINGS[$key] ?? '', $descriptor['kind']),
                origin: $this->provenance->originOf(['settings', $key]),
                shadowed: $this->provenance->shadowsGlobal(['settings', $key]),
                fieldset: $this->translator->trans($descriptor['fieldset']),
            );
        }

        return $rows;
    }

    /** A stored value as its row's kind renders it. */
    private static function coerce(bool|int|string $raw, SettingsRowKind $kind): bool|int|string
    {
        return match ($kind) {
            SettingsRowKind::Bool => (bool) $raw,
            SettingsRowKind::Int => (int) $raw,
            default => (string) $raw,
        };
    }

    /**
     * Step a choice setting to its next option, wrapping at the end — the View
     * menu's cycle commands (line numbers off → absolute → relative → off). An
     * unknown key, or one that is not a choice, flashes instead.
     */
    public function cycleChoice(string $key): void
    {
        $options = $this->descriptors[$key]['options'] ?? null;
        if (null === $options) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return;
        }
        $at = \array_search((string) ($this->settings[$key] ?? ''), $options, strict: true);
        $this->changeSetting($key, $options[false === $at ? 0 : ($at + 1) % \count($options)]);
    }

    /** A choice setting's current value — the View menu's label beside its cycle command. */
    public function choice(string $key): string
    {
        return (string) ($this->settings[$key] ?? '');
    }

    /** Whether a boolean setting is on — the View menu's ✓ beside its toggle. */
    public function isOn(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? false);
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
     * Put a setting back to the product's default.
     *
     * The asymmetry is the point: IN MEMORY the default is restored and applied
     * live, because {@see $settings} is indexed unconditionally and a missing
     * key would be a different bug; ON DISK the key is REMOVED, so a default
     * that later moves moves with it.
     */
    public function resetSetting(string $key): void
    {
        $descriptor = $this->descriptors[$key] ?? null;
        if (null === $descriptor) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return;
        }

        $default = IdeConfig::DEFAULT_SETTINGS[$key] ?? '';
        $this->settings[$key] = $default;
        if (null !== $descriptor['apply']) {
            $descriptor['apply']($default);
        }
        $persisted = $this->writer->resetSetting($this->scope->current(), $key);
        $this->notifier->flash($this->translator->trans('%label% reset to default%saved%', [
            '%label%' => $this->translator->trans($descriptor['label']),
            '%saved%' => SettingsFlash::savedSuffix($this->translator, $persisted),
        ]));
    }

    /**
     * Set a setting to a specific value from the settings form: live ones apply
     * to the editor immediately; the rest persist and take effect next launch.
     */
    public function changeSetting(string $key, int|bool|string $value): bool
    {
        $descriptor = $this->descriptors[$key] ?? null;
        if (null === $descriptor) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return false;
        }

        $this->settings[$key] = $value;
        if (null !== $descriptor['apply']) {
            $descriptor['apply']($value);
        }

        $persisted = $this->writer->persistSetting($this->scope->current(), $key, $value);
        $state = \is_bool($value) ? $this->translator->trans($value ? 'on' : 'off') : (string) $value;
        $note = $descriptor['restart'] ? ' · '.$this->translator->trans('applies on restart') : '';
        $saved = SettingsFlash::savedSuffix($this->translator, $persisted);
        $this->notifier->flash(\sprintf('%s %s%s%s', $this->translator->trans($descriptor['label']), $state, $note, $saved));

        return true;
    }
}
