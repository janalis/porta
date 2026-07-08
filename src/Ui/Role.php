<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A semantic color role in the IDE chrome. Widgets ask the {@see Palette} for a
 * role's escape sequence rather than hardcoding a raw SGR code, so the whole
 * theme (dark/light) and the monochrome (`--no-ansi`) mode can be swapped in one
 * place. Roles carry no code themselves — each {@see Palette} maps them.
 */
enum Role
{
    // Diff view.
    case Gutter;
    case Added;
    case Deleted;
    case Hunk;
    case AddEmphasisBg;
    case DelEmphasisBg;

    // Shared row highlights (menus, popups, lists, the current editor line).
    case SelectedRow;
    case RowHighlightBg;
    case PopupRow;

    // Git chrome: header/toolbar bars, file-status markers, tag buttons.
    case HeaderBar;
    case Toolbar;
    case TagOffBg;
    case Modified;
    case DangerBg;

    // Text shades.
    case Dim;
    case Muted;
    case Bright;

    // Diagnostics by severity: foreground, a dimmer end-of-line shade, and the
    // curly-underline color (SGR 58 channel).
    case DiagError;
    case DiagWarn;
    case DiagInfo;
    case DiagHint;
    case DiagErrorDim;
    case DiagWarnDim;
    case DiagInfoDim;
    case DiagHintDim;
    case DiagErrorUnderline;
    case DiagWarnUnderline;
    case DiagInfoUnderline;
    case DiagHintUnderline;

    // Editor rendering: gutter shades, git marks, inlay hints, indent guides, popups.
    case CurrentLineNumber;
    case Placeholder;
    case Inlay;
    case IndentGuide;
    case GitAdded;
    case GitModified;
    case GitDeleted;
    case InlayPopupBg;
    case InlayPopupFg;
    case CompletionHint;
    case CompletionKind;
    case SelectionBg;
    case SearchBg;
    case SearchCurrentBg;
    case OccurrenceBg;

    // Chrome shades: tab/breadcrumb accent, menu highlight, extra greys.
    case TabAccent;
    case MenuActiveBg;
    case Faint;
    case Subtle;
    case Emphasis;
}
