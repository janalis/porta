<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The editor settings model: applies the persisted values on boot and
 * flips/persists/flashes them on change. Each setting's label, kind and (for
 * the live ones) applier live in a single descriptor table, which also feeds
 * the settings screen's form rows.
 *
 * Three booleans apply live (their `apply` closure mutates the editor); the
 * rest (`editorConfig`, `theme`, `tabWidth`) are persist-only and take effect
 * next launch, flagged with a "applies on restart" note.
 *
 * @phpstan-type Descriptor array{label: string, kind: string, restart: bool, apply: (\Closure(int|bool|string): void)|null, options?: list<string>, min?: int, max?: int}
 * @phpstan-type FormRow array{key: string, label: string, kind: string, value: bool|int|string, restart: bool, options?: list<string>, min?: int, max?: int}
 */
final class SettingsController
{
    /** @var array<string, Descriptor> */
    private readonly array $descriptors;

    /**
     * @param array<string, int|bool|string> $settings resolved settings (defaults merged)
     */
    public function __construct(
        private array $settings,
        CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly string $rootDir,
    ) {
        $this->descriptors = [
            'inlineDiagnostics' => ['label' => 'Inline diagnostics', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setInlineDiagnostics((bool) $v)],
            'relativeLineNumbers' => ['label' => 'Relative line numbers', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setRelativeLineNumbers((bool) $v)],
            'autoPairs' => ['label' => 'Auto-pairs', 'kind' => 'bool', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setAutoPairs((bool) $v)],
            'indentStyle' => ['label' => 'Indent style', 'kind' => 'choice', 'restart' => false, 'apply' => static fn (int|bool|string $v) => $editor->setIndentStyle((string) $v), 'options' => ['auto', 'spaces', 'tabs']],
            'editorConfig' => ['label' => 'Respect .editorconfig', 'kind' => 'bool', 'restart' => true, 'apply' => null],
            'theme' => ['label' => 'Theme', 'kind' => 'choice', 'restart' => true, 'apply' => null, 'options' => ['dark', 'light']],
            'tabWidth' => ['label' => 'Tab width', 'kind' => 'int', 'restart' => true, 'apply' => null, 'min' => 1, 'max' => 16],
        ];
    }

    /** Apply every live (non-null-apply) setting to the editor (boot). */
    public function applyAll(): void
    {
        foreach ($this->descriptors as $key => $descriptor) {
            if (null !== $descriptor['apply']) {
                ($descriptor['apply'])($this->settings[$key]);
            }
        }
    }

    /**
     * The rows the settings form renders, current values pulled from state.
     *
     * @return list<FormRow>
     */
    public function formRows(): array
    {
        $rows = [];
        foreach ($this->descriptors as $key => $descriptor) {
            $raw = $this->settings[$key] ?? '';
            $value = match ($descriptor['kind']) {
                'bool' => (bool) $raw,
                'int' => (int) $raw,
                default => (string) $raw,
            };
            $row = ['key' => $key, 'label' => $descriptor['label'], 'kind' => $descriptor['kind'], 'value' => $value, 'restart' => $descriptor['restart']];
            if (isset($descriptor['options'])) {
                $row['options'] = $descriptor['options'];
            }
            if (isset($descriptor['min'], $descriptor['max'])) {
                $row['min'] = $descriptor['min'];
                $row['max'] = $descriptor['max'];
            }
            $rows[] = $row;
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
            $this->statusBar->flash(\sprintf('Unknown setting "%s"', $key));
            $this->tui->requestRender();

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
            $this->statusBar->flash(\sprintf('Unknown setting "%s"', $key));
            $this->tui->requestRender();

            return;
        }

        $this->settings[$key] = $value;
        if (null !== $descriptor['apply']) {
            ($descriptor['apply'])($value);
        }

        $persisted = IdeConfig::persistSetting($this->rootDir, $key, $value);
        $state = \is_bool($value) ? ($value ? 'on' : 'off') : (string) $value;
        $note = $descriptor['restart'] ? ' · applies on restart' : '';
        $this->statusBar->flash(\sprintf('%s %s%s%s', $descriptor['label'], $state, $note, $persisted ? '' : ' (not saved)'));
        $this->tui->requestRender();
    }
}
