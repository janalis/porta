<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The toggleable editor settings: applies the persisted values on boot and
 * flips/persists/flashes one on toggle. Each toggle's label and applier live
 * in a single descriptor table, replacing the two parallel match expressions
 * the coordinator kept in sync by hand.
 */
final class SettingsController
{
    /** @var array<string, array{label: string, apply: \Closure(bool): void}> */
    private readonly array $toggles;

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
        $this->toggles = [
            'inlineDiagnostics' => ['label' => 'Inline diagnostics', 'apply' => static fn (bool $on) => $editor->setInlineDiagnostics($on)],
            'relativeLineNumbers' => ['label' => 'Relative line numbers', 'apply' => static fn (bool $on) => $editor->setRelativeLineNumbers($on)],
            'autoPairs' => ['label' => 'Auto-pairs', 'apply' => static fn (bool $on) => $editor->setAutoPairs($on)],
        ];
    }

    /** Apply every persisted toggle to the editor (boot). */
    public function applyAll(): void
    {
        foreach ($this->toggles as $key => $toggle) {
            $toggle['apply']((bool) $this->settings[$key]);
        }
    }

    /**
     * Flip a boolean setting, apply it, persist to .porta.json, and flash.
     * An unknown key flashes instead of silently toggling something else.
     */
    public function toggle(string $key): void
    {
        $toggle = $this->toggles[$key] ?? null;
        if (null === $toggle) {
            $this->statusBar->flash(\sprintf('Unknown setting "%s"', $key));
            $this->tui->requestRender();

            return;
        }

        $new = !(bool) ($this->settings[$key] ?? false);
        $this->settings[$key] = $new;
        $toggle['apply']($new);

        $persisted = IdeConfig::persistSetting($this->rootDir, $key, $new);
        $this->statusBar->flash(\sprintf('%s %s%s', $toggle['label'], $new ? 'on' : 'off', $persisted ? '' : ' (not saved)'));
        $this->tui->requestRender();
    }
}
