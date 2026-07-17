<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A semantic color role in the IDE chrome. Widgets ask {@see Theme\Styles} for
 * a role's tui Style rather than hardcoding a raw SGR code, so the whole theme
 * (dark/light) and the monochrome (`--no-ansi`) mode can be swapped in one
 * place. Roles carry no style themselves — each theme definition maps them.
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
    // SelectedRow marks the selection in the focused pane; SelectedRowUnfocused
    // is the dimmer variant every pane uses while unfocused.
    //
    // Selection convention: focus-tracking panes join the theme's
    // `.list::selected(:focus)` element rules (addStyleClass('list') +
    // resolveElement('selected')); always-focused modal surfaces (menus,
    // editor overlays, go-to-file, the find modal) apply SelectedRow
    // directly. Themes MUST keep `.list::selected:focus` ≡ SelectedRow and
    // `.list::selected` ≡ SelectedRowUnfocused so the two families render
    // identically. Pane/popup titles are HeaderBar+bold; in-list section
    // headers are Toolbar+Dim+bold; hover over default rows is
    // RowHighlightBg, hover over bar chrome (menu titles, tab strips) is
    // BarHoverBg.
    case SelectedRow;
    case SelectedRowUnfocused;
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

    // Debugger: the gutter breakpoint dot and the stopped-line band.
    case Breakpoint;
    case DebugLineBg;

    // Chrome shades: tab/breadcrumb accent, bar-chrome hover, extra greys.
    case TabAccent;
    case TabActiveBg;
    case BarHoverBg;
    case Faint;
    case Subtle;
    case Emphasis;

    // Status bar segments: git branch, debug indicator, problem counts.
    case StatusBranch;
    case StatusDebug;
    case StatusError;
    case StatusWarning;

    /**
     * The stylesheet class selector for this role (`.role-added` for `Added`),
     * so theme rule maps and widget style classes share one derived name.
     */
    public function selector(): string
    {
        return '.role-'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', replacement: '-$0', subject: $this->name));
    }
}
