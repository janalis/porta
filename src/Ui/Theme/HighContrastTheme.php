<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Style\Style;

/**
 * The accessibility theme (`high-contrast`): near-white text and saturated
 * accents on the terminal's black, every pairing at WCAG-AAA contrast.
 */
final class HighContrastTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::Gutter->selector() => new Style(color: 251),
                Role::Added->selector() => new Style(color: 120),
                Role::Deleted->selector() => new Style(color: 203),
                Role::Hunk->selector() => new Style(color: 51),
                Role::AddEmphasisBg->selector() => new Style(background: 22),
                Role::DelEmphasisBg->selector() => new Style(background: 88),
                Role::SelectedRow->selector() => new Style(background: 19, color: 231, bold: true),
                Role::SelectedRowUnfocused->selector() => new Style(background: 236),
                Role::RowHighlightBg->selector() => new Style(background: 234),
                Role::FieldFocusBg->selector() => new Style(background: 234),
                Role::PopupRow->selector() => new Style(background: 236, color: 231),
                Role::HeaderBar->selector() => new Style(background: 236),
                Role::Toolbar->selector() => new Style(background: 233),
                Role::TagOffBg->selector() => new Style(background: 237),
                Role::Modified->selector() => new Style(color: 226),
                Role::DangerBg->selector() => new Style(background: 88),
                Role::Dim->selector() => new Style(color: 250),
                Role::Muted->selector() => new Style(color: 253),
                Role::Bright->selector() => new Style(color: 231),
                Role::DiagError->selector() => new Style(color: 203),
                Role::DiagWarn->selector() => new Style(color: 226),
                Role::DiagInfo->selector() => new Style(color: 51),
                Role::DiagHint->selector() => new Style(color: 250),
                // No dim tier in high contrast: the "dim" roles keep full strength.
                Role::DiagErrorDim->selector() => new Style(color: 203),
                Role::DiagWarnDim->selector() => new Style(color: 226),
                Role::DiagInfoDim->selector() => new Style(color: 51),
                Role::DiagHintDim->selector() => new Style(color: 250),
                Role::CurrentLineNumber->selector() => new Style(color: 231),
                Role::Placeholder->selector() => new Style(color: 244),
                Role::Inlay->selector() => new Style(color: 250),
                Role::IndentGuide->selector() => new Style(color: 242),
                Role::GitAdded->selector() => new Style(color: 120),
                Role::GitModified->selector() => new Style(color: 51),
                Role::GitDeleted->selector() => new Style(color: 203),
                Role::InlayPopupBg->selector() => new Style(background: 236),
                Role::InlayPopupFg->selector() => new Style(color: 231),
                Role::CompletionHint->selector() => new Style(color: 250),
                Role::CompletionKind->selector() => new Style(color: 222),
                Role::SelectionBg->selector() => new Style(background: 18),
                Role::SearchBg->selector() => new Style(background: 22),
                Role::SearchCurrentBg->selector() => new Style(background: 88),
                Role::OccurrenceBg->selector() => new Style(background: 23),
                Role::Breakpoint->selector() => new Style(color: 203),
                Role::DebugLineBg->selector() => new Style(background: 17),
                Role::TabAccent->selector() => new Style(color: 51),
                Role::TabActiveBg->selector() => new Style(background: 238),
                Role::BarHoverBg->selector() => new Style(background: 238),
                Role::Faint->selector() => new Style(color: 249),
                Role::Subtle->selector() => new Style(color: 251),
                Role::Emphasis->selector() => new Style(color: 231),
                Role::StatusBranch->selector() => new Style(color: 120),
                Role::StatusDebug->selector() => new Style(color: 203),
                Role::StatusError->selector() => new Style(color: 203),
                Role::StatusWarning->selector() => new Style(color: 226),
                // List selection as a `.list` pseudo-element: the base rule is
                // the unfocused shade, the :focus variant merges over it.
                '.list::selected' => new Style(background: 236),
                '.list::selected:focus' => new Style(background: 19, color: 231, bold: true),
                // Card selection: subtle tint only — see DarkTheme for why the
                // rules exist in every theme and stay off the SelectedRow roles.
                '.card::selected' => new Style(background: 236),
                '.card::selected:focus' => new Style(background: 238),
            ],
            underlines: [
                Role::DiagErrorUnderline->name => Sgr::underlineColor(203),
                Role::DiagWarnUnderline->name => Sgr::underlineColor(226),
                Role::DiagInfoUnderline->name => Sgr::underlineColor(51),
                Role::DiagHintUnderline->name => Sgr::underlineColor(250),
            ],
            hasColor: true,
        );
    }
}
