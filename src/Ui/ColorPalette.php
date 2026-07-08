<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Style\Color;

/**
 * A colored {@see Palette}. Each role maps to an SGR sequence built from
 * symfony/tui {@see Color} objects (256-color palette), so the codes are
 * declared once here instead of scattered as raw escapes across the widgets.
 * {@see dark()} reproduces the historical hardcoded colors exactly.
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
            Role::SelectedRow->name => Color::from(31)->toBackgroundCode().Color::from(231)->toForegroundCode(),
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
            Role::TabAccent->name => Color::from(110)->toForegroundCode(),
            Role::MenuActiveBg->name => Color::from(240)->toBackgroundCode(),
            Role::Faint->name => Color::from(242)->toForegroundCode(),
            Role::Subtle->name => Color::from(246)->toForegroundCode(),
            Role::Emphasis->name => Color::from(255)->toForegroundCode(),
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
