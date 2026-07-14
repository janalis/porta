<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * The Shift+F11/F12 resize nudge for whichever resizable pane holds the
 * focus. Sidebars move 2 columns per press, bottom panels 1 row; a
 * non-resizable focus answers false so the chord falls through to the widget.
 */
final class PaneResizer
{
    public function __construct(
        private readonly Tui $tui,
        private readonly LayoutController $layout,
        private readonly FileTreeWidget $tree,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly ChangesListWidget $gitChanges,
        private readonly HistoryListWidget $gitHistory,
        private readonly CommitBoxWidget $gitCommitBox,
        private readonly SettingsNavWidget $settingsNav,
        private readonly SettingsFormWidget $settingsForm,
        private readonly KeybindingsListWidget $settingsKeys,
    ) {}

    public function canResize(): bool
    {
        return null !== $this->resizer(0);
    }

    public function resize(int $direction): void
    {
        if (null !== ($resize = $this->resizer($direction))) {
            $resize();
        }
    }

    private function resizer(int $direction): ?\Closure
    {
        $focus = $this->tui->getFocus();

        return match (true) {
            $focus === $this->tree => fn () => $this->layout->nudgeSidebar(2 * $direction),
            $focus === $this->terminalPanel, $focus === $this->resultsPanel, $focus === $this->debugPanel => fn () => $this->layout->nudgeBottomPanel($direction),
            \in_array($focus, [$this->gitChanges, $this->gitHistory, $this->gitCommitBox], true) => fn () => $this->layout->nudgeGitSidebar(2 * $direction),
            \in_array($focus, [$this->settingsNav, $this->settingsForm, $this->settingsKeys], true) => fn () => $this->layout->nudgeSettingsSidebar(2 * $direction),
            default => null,
        };
    }
}
