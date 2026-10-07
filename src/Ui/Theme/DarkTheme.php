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
                Role::DropTargetRow->selector() => new Style(background: 22, color: 231, bold: true),
                Role::FieldBorder->selector() => new Style(color: 245),
                Role::FieldPlaceholder->selector() => new Style(color: 243, italic: true),
                Role::PopupRow->selector() => new Style(background: 236, color: 250),
                Role::HeaderBar->selector() => new Style(background: 237),
                Role::Toolbar->selector() => new Style(background: 235),
                Role::Modified->selector() => new Style(color: 179),
                Role::Dim->selector() => new Style(color: 245),
                Role::Muted->selector() => new Style(color: 250),
                Role::Bright->selector() => new Style(color: 231),
                Role::DiagError->selector() => new Style(color: 203),
                Role::DiagWarn->selector() => new Style(color: 208),
                Role::DiagInfo->selector() => new Style(color: 220),
                Role::DiagHint->selector() => new Style(color: 244),
                Role::DiagErrorDim->selector() => new Style(color: 131),
                Role::DiagWarnDim->selector() => new Style(color: 130),
                Role::DiagInfoDim->selector() => new Style(color: 136),
                Role::DiagHintDim->selector() => new Style(color: 240),
                Role::CurrentLineNumber->selector() => new Style(color: 252),
                Role::Placeholder->selector() => new Style(color: 238),
                Role::Rule->selector() => new Style(color: 238),
                Role::DebugValue->selector() => new Style(color: 149),
                Role::Inlay->selector() => new Style(color: 243),
                Role::InlayParameter->selector() => new Style(background: 238, color: 250),
                Role::InlayType->selector() => new Style(background: 238, color: 245),
                Role::IndentGuide->selector() => new Style(color: 238),
                Role::VcsAdded->selector() => new Style(color: 114),
                Role::VcsModified->selector() => new Style(color: 75),
                Role::VcsDeleted->selector() => new Style(color: 203),
                Role::InlayPopupBg->selector() => new Style(background: 238),
                Role::InlayPopupFg->selector() => new Style(color: 223),
                Role::CompletionHint->selector() => new Style(color: 244),
                Role::CompletionKind->selector() => new Style(color: 180),
                Role::SelectionBg->selector() => new Style(background: 24),
                Role::SearchBg->selector() => new Style(background: 58),
                Role::SearchCurrentBg->selector() => new Style(background: 130),
                Role::OccurrenceBg->selector() => new Style(background: 23),
                Role::OccurrenceWriteBg->selector() => new Style(background: 52),
                Role::Agent1->selector() => new Style(background: 25, color: 231),
                Role::Agent2->selector() => new Style(background: 130, color: 231),
                Role::Agent3->selector() => new Style(background: 91, color: 231),
                Role::Agent4->selector() => new Style(background: 29, color: 231),
                Role::Agent5->selector() => new Style(background: 124, color: 231),
                Role::Agent6->selector() => new Style(background: 162, color: 231),
                Role::Agent7->selector() => new Style(background: 37, color: 231),
                Role::Agent8->selector() => new Style(background: 100, color: 231),
                Role::Breakpoint->selector() => new Style(color: 203),
                Role::DebugLineBg->selector() => new Style(background: 52),
                Role::TabAccent->selector() => new Style(color: 110),
                Role::TabActiveBg->selector() => new Style(background: 238),
                Role::BarHoverBg->selector() => new Style(background: 240),
                Role::Faint->selector() => new Style(color: 242),
                Role::Subtle->selector() => new Style(color: 246),
                Role::Emphasis->selector() => new Style(color: 255),
                Role::StatusAccent->selector() => new Style(color: 114),
                Role::StatusActive->selector() => new Style(color: 203),
                Role::StatusError->selector() => new Style(color: 203),
                Role::StatusWarning->selector() => new Style(color: 208),
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
                // The floating modals' drop shadow: solid cells that hide the
                // content beneath. Present in every theme (StyleSheetThemer
                // can only overwrite) like the .card rules above.
                '.modal::shadow' => new Style(background: 233),
            ],
            underlines: [
                Role::DiagErrorUnderline->name => Sgr::underlineColor(203),
                Role::DiagWarnUnderline->name => Sgr::underlineColor(208),
                Role::DiagInfoUnderline->name => Sgr::underlineColor(220),
                Role::DiagHintUnderline->name => Sgr::underlineColor(244),
            ],
            hasColor: true,
        );
    }
}
