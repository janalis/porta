<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Style\Style;

/**
 * The default theme: the historical hardcoded colors, verbatim, as tui
 * {@see Style} rules (256-color indexes). The SGR-58 underline colors ride
 * separately — tui's Style has no underline-color property.
 */
final class DarkTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::Gutter->selector() => new Style(color: 240),
                Role::Added->selector() => new Style(color: 114),
                Role::Deleted->selector() => new Style(color: 203),
                Role::Hunk->selector() => new Style(color: 75),
                Role::AddEmphasisBg->selector() => new Style(background: 22),
                Role::DelEmphasisBg->selector() => new Style(background: 88),
                Role::SelectedRow->selector() => new Style(background: 31, color: 231, bold: true),
                Role::SelectedRowUnfocused->selector() => new Style(background: 237),
                Role::RowHighlightBg->selector() => new Style(background: 236),
                Role::FieldFocusBg->selector() => new Style(background: 236),
                Role::PopupRow->selector() => new Style(background: 236, color: 250),
                Role::HeaderBar->selector() => new Style(background: 237),
                Role::Toolbar->selector() => new Style(background: 235),
                Role::TagOffBg->selector() => new Style(background: 238),
                Role::Modified->selector() => new Style(color: 179),
                Role::DangerBg->selector() => new Style(background: 88),
                Role::Dim->selector() => new Style(color: 245),
                Role::Muted->selector() => new Style(color: 250),
                Role::Bright->selector() => new Style(color: 231),
                Role::DiagError->selector() => new Style(color: 203),
                Role::DiagWarn->selector() => new Style(color: 179),
                Role::DiagInfo->selector() => new Style(color: 75),
                Role::DiagHint->selector() => new Style(color: 244),
                Role::DiagErrorDim->selector() => new Style(color: 131),
                Role::DiagWarnDim->selector() => new Style(color: 101),
                Role::DiagInfoDim->selector() => new Style(color: 67),
                Role::DiagHintDim->selector() => new Style(color: 240),
                Role::CurrentLineNumber->selector() => new Style(color: 252),
                Role::Placeholder->selector() => new Style(color: 238),
                Role::Inlay->selector() => new Style(color: 243),
                Role::IndentGuide->selector() => new Style(color: 238),
                Role::GitAdded->selector() => new Style(color: 114),
                Role::GitModified->selector() => new Style(color: 75),
                Role::GitDeleted->selector() => new Style(color: 203),
                Role::InlayPopupBg->selector() => new Style(background: 238),
                Role::InlayPopupFg->selector() => new Style(color: 223),
                Role::CompletionHint->selector() => new Style(color: 244),
                Role::CompletionKind->selector() => new Style(color: 180),
                Role::SelectionBg->selector() => new Style(background: 24),
                Role::SearchBg->selector() => new Style(background: 58),
                Role::SearchCurrentBg->selector() => new Style(background: 130),
                Role::OccurrenceBg->selector() => new Style(background: 23),
                Role::Breakpoint->selector() => new Style(color: 203),
                Role::DebugLineBg->selector() => new Style(background: 52),
                Role::TabAccent->selector() => new Style(color: 110),
                Role::TabActiveBg->selector() => new Style(background: 238),
                Role::BarHoverBg->selector() => new Style(background: 240),
                Role::Faint->selector() => new Style(color: 242),
                Role::Subtle->selector() => new Style(color: 246),
                Role::Emphasis->selector() => new Style(color: 255),
                Role::StatusBranch->selector() => new Style(color: 114),
                Role::StatusDebug->selector() => new Style(color: 203),
                Role::StatusError->selector() => new Style(color: 203),
                Role::StatusWarning->selector() => new Style(color: 179),
                // List selection as a `.list` pseudo-element: the base rule is
                // the unfocused shade, the :focus variant merges over it.
                '.list::selected' => new Style(background: 237),
                '.list::selected:focus' => new Style(background: 31, color: 231, bold: true),
                // Card selection (`.card`, the Welcome screen) is a subtle tint
                // only — the selection itself is the accent bar the widget
                // draws. Deliberately NOT bound to the SelectedRow roles, and
                // present in every theme: StyleSheetThemer can only overwrite
                // rules, so a selector missing from one theme would leave a
                // stale rule live after a switch.
                '.card::selected' => new Style(background: 237),
                '.card::selected:focus' => new Style(background: 238),
            ],
            underlines: [
                Role::DiagErrorUnderline->name => Sgr::underlineColor(203),
                Role::DiagWarnUnderline->name => Sgr::underlineColor(179),
                Role::DiagInfoUnderline->name => Sgr::underlineColor(75),
                Role::DiagHintUnderline->name => Sgr::underlineColor(244),
            ],
            hasColor: true,
        );
    }
}
