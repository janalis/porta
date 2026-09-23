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
    // editor overlays, go-to-file, the find modal) resolve the row shade
    // through {@see \Porta\Widget\Render\ModalRowStyle} (Selected → SelectedRow,
    // Hovered → RowHighlightBg, else PopupRow). Themes MUST keep
    // `.list::selected:focus` ≡ SelectedRow and `.list::selected` ≡
    // SelectedRowUnfocused so the two families render identically. A third
    // family, the Welcome screen's cards, joins `.card::selected(:focus)`
    // instead: those rules carry only a subtle background tint (plain in
    // monochrome) because the selection there is the accent bar the widget
    // draws (Glyphs::SELECTION_BAR styled TabAccent, title Bright+bold) —
    // deliberately NOT bound to the SelectedRow roles. Pane/popup
    // titles are HeaderBar+bold; in-list section headers are Toolbar+Dim+bold;
    // hover over default rows is RowHighlightBg; hover over bar chrome (menu
    // titles, tab strips) is BarHoverBg, resolved through
    // {@see \Porta\Widget\Render\BarChromeStyle}.
    case SelectedRow;
    case SelectedRowUnfocused;
    case RowHighlightBg;
    case PopupRow;
    // Where a drag would land. Its own role rather than the hovered-directory
    // tint it used to borrow: those were byte-identical, so "this is where the
    // files are going" looked exactly like "the pointer is here". Green in the
    // colour themes, the ordinary affordance for a live drop target, and
    // reverse+bold in monochrome — distinct from the selection's plain reverse
    // and the hover's underline, because a drag can rest on a row that is
    // already both.
    case DropTargetRow;
    // A text field's idle border and legend (FieldBox). Only a tint: the
    // border SHAPE (rounded idle, heavy focused, double danger) is the state
    // cue, so monochrome leaves it plain and still reads.
    case FieldBorder;
    // An empty field's placeholder: grey AND italic, so it never passes for a
    // typed value — monochrome keeps the italic (plus dim) where Dim is plain.
    case FieldPlaceholder;

    // Git chrome: header/toolbar bars, file-status markers, tag buttons.
    case HeaderBar;
    case Toolbar;
    case TagOffBg;
    case Modified;

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

    /**
     * The debugger's value for a line, in the trailing slot. Its own role
     * rather than {@see Inlay}'s grey: an inlay hint is what the code MEANS and
     * is always there, a debug value is what it currently HOLDS and is there
     * only while stopped — reading the same would make a live value look like
     * part of the source.
     */
    case DebugValue;
    case IndentGuide;
    case VcsAdded;
    case VcsModified;
    case VcsDeleted;
    case InlayPopupBg;
    case InlayPopupFg;
    case CompletionHint;
    case CompletionKind;
    case SelectionBg;
    case SearchBg;
    case SearchCurrentBg;
    case OccurrenceBg;
    case OccurrenceWriteBg;

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
    case StatusAccent;
    case StatusActive;
    case StatusError;
    case StatusWarning;

    /**
     * The stylesheet class selector for this role (`.role-added` for `Added`),
     * so theme rule maps and widget style classes share one derived name.
     */
    public function selector(): string
    {
        return '.role-'.\strtolower((string) \preg_replace('/(?<!^)[A-Z]/', replacement: '-$0', subject: $this->name));
    }
}
