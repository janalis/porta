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
    // titles are HeaderBar+bold; in-list section headers are Rule legends;
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

    // Git chrome: header/toolbar bars, file-status markers.
    case HeaderBar;
    case Toolbar;
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

    // Agents at work (Workbench\AgentPresence): one colour per agent, the
    // same on its caret cell, its name beside it and its tab mark. Eight,
    // handed out in order of arrival; a ninth agent shares the first's.
    case Agent1;
    case Agent2;
    case Agent3;
    case Agent4;
    case Agent5;
    case Agent6;
    case Agent7;
    case Agent8;

    // Debugger: the gutter breakpoint dot and the stopped-line band.
    case Breakpoint;
    case DebugLineBg;

    // Chrome shades: the accent, bar-chrome hover, extra greys.
    // TabAccent is the IDE's one accent despite its name: the frame of every
    // floating surface (ModalFrame, PopupFrame, the tooltip card), the
    // selection bar, a checked mark, a tab's lid. The name stays because it is
    // plugin API — `<tab-accent>` in markup (docs/plugins.md).
    case TabAccent;
    case TabActiveBg;
    case BarHoverBg;
    case Faint;
    case Subtle;
    case Emphasis;
    // A rule: every plain line that separates — the pane divider, the tab and
    // dock baselines, the status bar's cells, a menu's separator, a modal's
    // section tee, a group's legend — drawn by {@see \Porta\Widget\Render\Rule}.
    // One tint, so a line reads as a line wherever it runs; hover and focus
    // keep their own (the heavy ┃ ━ in the accent).
    case Rule;

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
        return '.role-'.$this->tag();
    }

    /**
     * The kebab-case name (`diag-warn` for `DiagWarn`) — the markup tag that
     * names this role ({@see \Porta\Ui\Markup\StyleSpec}) and the tail of
     * its {@see selector()}.
     */
    public function tag(): string
    {
        return \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', replacement: '-$0', subject: $this->name));
    }

    /** The role {@see tag()} names, or null when no role has that tag. */
    public static function fromTag(string $tag): ?self
    {
        $case = self::class.'::'.\str_replace(search: ' ', replace: '', subject: \ucwords(\str_replace(search: '-', replace: ' ', subject: $tag)));
        if (!\defined($case)) {
            return null;
        }
        /** @var self $role a constant named Role::<Case> is that case */
        $role = \constant($case);

        return $role->tag() === $tag ? $role : null;
    }

    /** The colour of agent slot $slot (0-based), wrapping past the last. */
    public static function agent(int $slot): self
    {
        $agents = [self::Agent1, self::Agent2, self::Agent3, self::Agent4, self::Agent5, self::Agent6, self::Agent7, self::Agent8];

        return $agents[\abs($slot) % \count($agents)];
    }
}
