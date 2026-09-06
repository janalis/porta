<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Style\Style;

/**
 * The dark-on-light counterpart of {@see DarkTheme}, selected when the
 * `theme` setting is `light`.
 */
final class LightTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::Gutter->selector() => new Style(color: 245),
                Role::Added->selector() => new Style(color: 28),
                Role::Deleted->selector() => new Style(color: 160),
                Role::Hunk->selector() => new Style(color: 25),
                Role::AddEmphasisBg->selector() => new Style(background: 194),
                Role::DelEmphasisBg->selector() => new Style(background: 224),
                Role::SelectedRow->selector() => new Style(background: 111, color: 16, bold: true),
                Role::SelectedRowUnfocused->selector() => new Style(background: 253),
                Role::RowHighlightBg->selector() => new Style(background: 253),
                Role::FieldFocusBg->selector() => new Style(background: 253),
                Role::PopupRow->selector() => new Style(background: 253, color: 240),
                Role::HeaderBar->selector() => new Style(background: 253),
                Role::Toolbar->selector() => new Style(background: 254),
                Role::TagOffBg->selector() => new Style(background: 252),
                Role::Modified->selector() => new Style(color: 130),
                Role::DangerBg->selector() => new Style(background: 224),
                Role::Dim->selector() => new Style(color: 244),
                Role::Muted->selector() => new Style(color: 240),
                Role::Bright->selector() => new Style(color: 16),
                Role::DiagError->selector() => new Style(color: 160),
                Role::DiagWarn->selector() => new Style(color: 130),
                Role::DiagInfo->selector() => new Style(color: 25),
                Role::DiagHint->selector() => new Style(color: 245),
                Role::DiagErrorDim->selector() => new Style(color: 124),
                Role::DiagWarnDim->selector() => new Style(color: 136),
                Role::DiagInfoDim->selector() => new Style(color: 67),
                Role::DiagHintDim->selector() => new Style(color: 240),
                Role::CurrentLineNumber->selector() => new Style(color: 240),
                Role::Placeholder->selector() => new Style(color: 250),
                Role::DebugValue->selector() => new Style(color: 28),
                Role::Inlay->selector() => new Style(color: 245),
                Role::IndentGuide->selector() => new Style(color: 250),
                Role::VcsAdded->selector() => new Style(color: 28),
                Role::VcsModified->selector() => new Style(color: 25),
                Role::VcsDeleted->selector() => new Style(color: 160),
                Role::InlayPopupBg->selector() => new Style(background: 254),
                Role::InlayPopupFg->selector() => new Style(color: 240),
                Role::CompletionHint->selector() => new Style(color: 244),
                Role::CompletionKind->selector() => new Style(color: 97),
                Role::SelectionBg->selector() => new Style(background: 153),
                Role::SearchBg->selector() => new Style(background: 229),
                Role::SearchCurrentBg->selector() => new Style(background: 215),
                Role::OccurrenceBg->selector() => new Style(background: 194),
                Role::OccurrenceWriteBg->selector() => new Style(background: 224),
                Role::Breakpoint->selector() => new Style(color: 160),
                Role::DebugLineBg->selector() => new Style(background: 224),
                Role::TabAccent->selector() => new Style(color: 25),
                Role::TabActiveBg->selector() => new Style(background: 252),
                Role::BarHoverBg->selector() => new Style(background: 252),
                Role::Faint->selector() => new Style(color: 245),
                Role::Subtle->selector() => new Style(color: 246),
                Role::Emphasis->selector() => new Style(color: 16),
                Role::StatusAccent->selector() => new Style(color: 28),
                Role::StatusActive->selector() => new Style(color: 160),
                Role::StatusError->selector() => new Style(color: 160),
                Role::StatusWarning->selector() => new Style(color: 130),
                // List selection as a `.list` pseudo-element: the base rule is
                // the unfocused shade, the :focus variant merges over it.
                '.list::selected' => new Style(background: 253),
                '.list::selected:focus' => new Style(background: 111, color: 16, bold: true),
                // Card selection: subtle tint only — see DarkTheme for why the
                // rules exist in every theme and stay off the SelectedRow roles.
                '.card::selected' => new Style(background: 254),
                '.card::selected:focus' => new Style(background: 252),
                // Modal drop shadow — see DarkTheme for the totality note.
                '.modal::shadow' => new Style(background: 250),
            ],
            underlines: [
                Role::DiagErrorUnderline->name => Sgr::underlineColor(160),
                Role::DiagWarnUnderline->name => Sgr::underlineColor(130),
                Role::DiagInfoUnderline->name => Sgr::underlineColor(25),
                Role::DiagHintUnderline->name => Sgr::underlineColor(245),
            ],
            hasColor: true,
        );
    }
}
