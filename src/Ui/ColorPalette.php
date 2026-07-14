<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Style\Color;

/**
 * A colored {@see Palette}. Each role maps to an SGR sequence built from
 * symfony/tui {@see Color} objects (256-color palette), so the codes are
 * declared once here instead of scattered as raw escapes across the widgets.
 * {@see dark()} reproduces the historical hardcoded colors exactly; {@see light()}
 * is its dark-on-light counterpart, selected when the `theme` setting is `light`;
 * {@see highContrast()} is the accessibility theme (`high-contrast`): near-white
 * text and saturated accents on the terminal's black, every pairing at WCAG-AAA
 * contrast.
 */
final class ColorPalette implements Palette
{
    /** @param array<string, string> $codes role name → SGR sequence */
    private function __construct(
        private readonly array $codes,
    ) {}

    public static function dark(): self
    {
        return new self([
            Role::Gutter->name => Color::from(240)->toForegroundCode(),
            Role::Added->name => Color::from(114)->toForegroundCode(),
            Role::Deleted->name => Color::from(203)->toForegroundCode(),
            Role::Hunk->name => Color::from(75)->toForegroundCode(),
            Role::AddEmphasisBg->name => Color::from(22)->toBackgroundCode(),
            Role::DelEmphasisBg->name => Color::from(88)->toBackgroundCode(),
            Role::SelectedRow->name => Color::from(31)->toBackgroundCode().Color::from(231)->toForegroundCode()."\x1b[1m",
            Role::SelectedRowUnfocused->name => Color::from(237)->toBackgroundCode(),
            Role::RowHighlightBg->name => Color::from(236)->toBackgroundCode(),
            Role::PopupRow->name => Color::from(236)->toBackgroundCode().Color::from(250)->toForegroundCode(),
            Role::HeaderBar->name => Color::from(237)->toBackgroundCode(),
            Role::Toolbar->name => Color::from(235)->toBackgroundCode(),
            Role::TagOffBg->name => Color::from(238)->toBackgroundCode(),
            Role::Modified->name => Color::from(179)->toForegroundCode(),
            Role::DangerBg->name => Color::from(88)->toBackgroundCode(),
            Role::Dim->name => Color::from(245)->toForegroundCode(),
            Role::Muted->name => Color::from(250)->toForegroundCode(),
            Role::Bright->name => Color::from(231)->toForegroundCode(),
            Role::DiagError->name => Color::from(203)->toForegroundCode(),
            Role::DiagWarn->name => Color::from(179)->toForegroundCode(),
            Role::DiagInfo->name => Color::from(75)->toForegroundCode(),
            Role::DiagHint->name => Color::from(244)->toForegroundCode(),
            Role::DiagErrorDim->name => Color::from(131)->toForegroundCode(),
            Role::DiagWarnDim->name => Color::from(101)->toForegroundCode(),
            Role::DiagInfoDim->name => Color::from(67)->toForegroundCode(),
            Role::DiagHintDim->name => Color::from(240)->toForegroundCode(),
            Role::DiagErrorUnderline->name => "\x1b[58;5;203m",
            Role::DiagWarnUnderline->name => "\x1b[58;5;179m",
            Role::DiagInfoUnderline->name => "\x1b[58;5;75m",
            Role::DiagHintUnderline->name => "\x1b[58;5;244m",
            Role::CurrentLineNumber->name => Color::from(252)->toForegroundCode(),
            Role::Placeholder->name => Color::from(238)->toForegroundCode(),
            Role::Inlay->name => Color::from(243)->toForegroundCode(),
            Role::IndentGuide->name => Color::from(238)->toForegroundCode(),
            Role::GitAdded->name => Color::from(114)->toForegroundCode(),
            Role::GitModified->name => Color::from(75)->toForegroundCode(),
            Role::GitDeleted->name => Color::from(203)->toForegroundCode(),
            Role::InlayPopupBg->name => Color::from(238)->toBackgroundCode(),
            Role::InlayPopupFg->name => Color::from(223)->toForegroundCode(),
            Role::CompletionHint->name => Color::from(244)->toForegroundCode(),
            Role::CompletionKind->name => Color::from(180)->toForegroundCode(),
            Role::SelectionBg->name => Color::from(24)->toBackgroundCode(),
            Role::SearchBg->name => Color::from(58)->toBackgroundCode(),
            Role::SearchCurrentBg->name => Color::from(130)->toBackgroundCode(),
            Role::OccurrenceBg->name => Color::from(23)->toBackgroundCode(),
            Role::Breakpoint->name => Color::from(203)->toForegroundCode(),
            Role::DebugLineBg->name => Color::from(52)->toBackgroundCode(),
            Role::TabAccent->name => Color::from(110)->toForegroundCode(),
            Role::MenuActiveBg->name => Color::from(240)->toBackgroundCode(),
            Role::Faint->name => Color::from(242)->toForegroundCode(),
            Role::Subtle->name => Color::from(246)->toForegroundCode(),
            Role::Emphasis->name => Color::from(255)->toForegroundCode(),
            Role::StatusBranch->name => Color::from(114)->toForegroundCode(),
            Role::StatusDebug->name => Color::from(203)->toForegroundCode(),
            Role::StatusError->name => Color::from(203)->toForegroundCode(),
            Role::StatusWarning->name => Color::from(179)->toForegroundCode(),
        ]);
    }

    public static function light(): self
    {
        return new self([
            Role::Gutter->name => Color::from(245)->toForegroundCode(),
            Role::Added->name => Color::from(28)->toForegroundCode(),
            Role::Deleted->name => Color::from(160)->toForegroundCode(),
            Role::Hunk->name => Color::from(25)->toForegroundCode(),
            Role::AddEmphasisBg->name => Color::from(194)->toBackgroundCode(),
            Role::DelEmphasisBg->name => Color::from(224)->toBackgroundCode(),
            Role::SelectedRow->name => Color::from(111)->toBackgroundCode().Color::from(16)->toForegroundCode()."\x1b[1m",
            Role::SelectedRowUnfocused->name => Color::from(253)->toBackgroundCode(),
            Role::RowHighlightBg->name => Color::from(253)->toBackgroundCode(),
            Role::PopupRow->name => Color::from(253)->toBackgroundCode().Color::from(240)->toForegroundCode(),
            Role::HeaderBar->name => Color::from(253)->toBackgroundCode(),
            Role::Toolbar->name => Color::from(254)->toBackgroundCode(),
            Role::TagOffBg->name => Color::from(252)->toBackgroundCode(),
            Role::Modified->name => Color::from(130)->toForegroundCode(),
            Role::DangerBg->name => Color::from(224)->toBackgroundCode(),
            Role::Dim->name => Color::from(244)->toForegroundCode(),
            Role::Muted->name => Color::from(240)->toForegroundCode(),
            Role::Bright->name => Color::from(16)->toForegroundCode(),
            Role::DiagError->name => Color::from(160)->toForegroundCode(),
            Role::DiagWarn->name => Color::from(130)->toForegroundCode(),
            Role::DiagInfo->name => Color::from(25)->toForegroundCode(),
            Role::DiagHint->name => Color::from(245)->toForegroundCode(),
            Role::DiagErrorDim->name => Color::from(124)->toForegroundCode(),
            Role::DiagWarnDim->name => Color::from(136)->toForegroundCode(),
            Role::DiagInfoDim->name => Color::from(67)->toForegroundCode(),
            Role::DiagHintDim->name => Color::from(240)->toForegroundCode(),
            Role::DiagErrorUnderline->name => "\x1b[58;5;160m",
            Role::DiagWarnUnderline->name => "\x1b[58;5;130m",
            Role::DiagInfoUnderline->name => "\x1b[58;5;25m",
            Role::DiagHintUnderline->name => "\x1b[58;5;245m",
            Role::CurrentLineNumber->name => Color::from(240)->toForegroundCode(),
            Role::Placeholder->name => Color::from(250)->toForegroundCode(),
            Role::Inlay->name => Color::from(245)->toForegroundCode(),
            Role::IndentGuide->name => Color::from(250)->toForegroundCode(),
            Role::GitAdded->name => Color::from(28)->toForegroundCode(),
            Role::GitModified->name => Color::from(25)->toForegroundCode(),
            Role::GitDeleted->name => Color::from(160)->toForegroundCode(),
            Role::InlayPopupBg->name => Color::from(254)->toBackgroundCode(),
            Role::InlayPopupFg->name => Color::from(240)->toForegroundCode(),
            Role::CompletionHint->name => Color::from(244)->toForegroundCode(),
            Role::CompletionKind->name => Color::from(97)->toForegroundCode(),
            Role::SelectionBg->name => Color::from(153)->toBackgroundCode(),
            Role::SearchBg->name => Color::from(229)->toBackgroundCode(),
            Role::SearchCurrentBg->name => Color::from(215)->toBackgroundCode(),
            Role::OccurrenceBg->name => Color::from(194)->toBackgroundCode(),
            Role::Breakpoint->name => Color::from(160)->toForegroundCode(),
            Role::DebugLineBg->name => Color::from(224)->toBackgroundCode(),
            Role::TabAccent->name => Color::from(25)->toForegroundCode(),
            Role::MenuActiveBg->name => Color::from(252)->toBackgroundCode(),
            Role::Faint->name => Color::from(245)->toForegroundCode(),
            Role::Subtle->name => Color::from(246)->toForegroundCode(),
            Role::Emphasis->name => Color::from(16)->toForegroundCode(),
            Role::StatusBranch->name => Color::from(28)->toForegroundCode(),
            Role::StatusDebug->name => Color::from(160)->toForegroundCode(),
            Role::StatusError->name => Color::from(160)->toForegroundCode(),
            Role::StatusWarning->name => Color::from(130)->toForegroundCode(),
        ]);
    }

    public static function highContrast(): self
    {
        return new self([
            Role::Gutter->name => Color::from(251)->toForegroundCode(),
            Role::Added->name => Color::from(120)->toForegroundCode(),
            Role::Deleted->name => Color::from(203)->toForegroundCode(),
            Role::Hunk->name => Color::from(51)->toForegroundCode(),
            Role::AddEmphasisBg->name => Color::from(22)->toBackgroundCode(),
            Role::DelEmphasisBg->name => Color::from(88)->toBackgroundCode(),
            Role::SelectedRow->name => Color::from(19)->toBackgroundCode().Color::from(231)->toForegroundCode()."\x1b[1m",
            Role::SelectedRowUnfocused->name => Color::from(236)->toBackgroundCode(),
            Role::RowHighlightBg->name => Color::from(234)->toBackgroundCode(),
            Role::PopupRow->name => Color::from(236)->toBackgroundCode().Color::from(231)->toForegroundCode(),
            Role::HeaderBar->name => Color::from(236)->toBackgroundCode(),
            Role::Toolbar->name => Color::from(233)->toBackgroundCode(),
            Role::TagOffBg->name => Color::from(237)->toBackgroundCode(),
            Role::Modified->name => Color::from(226)->toForegroundCode(),
            Role::DangerBg->name => Color::from(88)->toBackgroundCode(),
            Role::Dim->name => Color::from(250)->toForegroundCode(),
            Role::Muted->name => Color::from(253)->toForegroundCode(),
            Role::Bright->name => Color::from(231)->toForegroundCode(),
            Role::DiagError->name => Color::from(203)->toForegroundCode(),
            Role::DiagWarn->name => Color::from(226)->toForegroundCode(),
            Role::DiagInfo->name => Color::from(51)->toForegroundCode(),
            Role::DiagHint->name => Color::from(250)->toForegroundCode(),
            // No dim tier in high contrast: the "dim" roles keep full strength.
            Role::DiagErrorDim->name => Color::from(203)->toForegroundCode(),
            Role::DiagWarnDim->name => Color::from(226)->toForegroundCode(),
            Role::DiagInfoDim->name => Color::from(51)->toForegroundCode(),
            Role::DiagHintDim->name => Color::from(250)->toForegroundCode(),
            Role::DiagErrorUnderline->name => "\x1b[58;5;203m",
            Role::DiagWarnUnderline->name => "\x1b[58;5;226m",
            Role::DiagInfoUnderline->name => "\x1b[58;5;51m",
            Role::DiagHintUnderline->name => "\x1b[58;5;250m",
            Role::CurrentLineNumber->name => Color::from(231)->toForegroundCode(),
            Role::Placeholder->name => Color::from(244)->toForegroundCode(),
            Role::Inlay->name => Color::from(250)->toForegroundCode(),
            Role::IndentGuide->name => Color::from(242)->toForegroundCode(),
            Role::GitAdded->name => Color::from(120)->toForegroundCode(),
            Role::GitModified->name => Color::from(51)->toForegroundCode(),
            Role::GitDeleted->name => Color::from(203)->toForegroundCode(),
            Role::InlayPopupBg->name => Color::from(236)->toBackgroundCode(),
            Role::InlayPopupFg->name => Color::from(231)->toForegroundCode(),
            Role::CompletionHint->name => Color::from(250)->toForegroundCode(),
            Role::CompletionKind->name => Color::from(222)->toForegroundCode(),
            Role::SelectionBg->name => Color::from(18)->toBackgroundCode(),
            Role::SearchBg->name => Color::from(22)->toBackgroundCode(),
            Role::SearchCurrentBg->name => Color::from(88)->toBackgroundCode(),
            Role::OccurrenceBg->name => Color::from(23)->toBackgroundCode(),
            Role::Breakpoint->name => Color::from(203)->toForegroundCode(),
            Role::DebugLineBg->name => Color::from(17)->toBackgroundCode(),
            Role::TabAccent->name => Color::from(51)->toForegroundCode(),
            Role::MenuActiveBg->name => Color::from(238)->toBackgroundCode(),
            Role::Faint->name => Color::from(249)->toForegroundCode(),
            Role::Subtle->name => Color::from(251)->toForegroundCode(),
            Role::Emphasis->name => Color::from(231)->toForegroundCode(),
            Role::StatusBranch->name => Color::from(120)->toForegroundCode(),
            Role::StatusDebug->name => Color::from(203)->toForegroundCode(),
            Role::StatusError->name => Color::from(203)->toForegroundCode(),
            Role::StatusWarning->name => Color::from(226)->toForegroundCode(),
        ]);
    }

    public function of(Role $role): string
    {
        return $this->codes[$role->name] ?? '';
    }

    public function fgReset(): string
    {
        return "\x1b[39m";
    }

    public function reset(): string
    {
        return "\x1b[0m";
    }

    public function hasColor(): bool
    {
        return true;
    }
}
