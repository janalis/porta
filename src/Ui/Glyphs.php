<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The IDE-wide glyph vocabulary — every semantic marker drawn as a bare
 * character lives here so surfaces stay consistent and glyph overloads are
 * deliberate, not accidental. Rules of the vocabulary: glyphs sharing one
 * surface (e.g. the editor gutter's marker column) must be distinct; the same
 * value on disjoint surfaces is fine and is called out where it happens. All
 * single-character glyphs are one display column wide (East Asian Width
 * Narrow) so they never break line-width math.
 */
final class Glyphs
{
    // Disclosure triangles (tree nodes, collapsible list/panel sections).
    public const string EXPANDED = '▾';
    public const string COLLAPSED = '▸';

    // Editor gutter marker column. Debug decorations and diagnostic severity
    // glyphs share this column, so the two sets must stay disjoint.
    public const string BREAKPOINT = '●'; // same value as DIRTY — disjoint surfaces (gutter vs tab label)
    public const string BREAKPOINT_CONDITIONAL = '◆';
    public const string LOGPOINT = '◇'; // a logpoint logs to the console instead of pausing
    public const string STOPPED_LINE = '▶';

    // Diagnostic severity (gutter, inline message prefix, status-bar counts).
    public const string SEVERITY_ERROR = '✗';
    public const string SEVERITY_WARNING = '▲'; // also SCROLL_UP — disjoint surfaces (message row vs strip edge)
    public const string SEVERITY_INFO = '◦'; // NOT ◆ — the conditional breakpoint owns ◆ in the same gutter column
    public const string SEVERITY_HINT = '·';

    // The diagnostic right-stripe's density ramp: a heavier block is a higher
    // severity, so the stripe reads without colour. Disjoint surface from the
    // gutter severity glyphs above (the stripe column, not the marker column).
    public const string STRIPE_ERROR = '█';
    public const string STRIPE_WARNING = '▓';
    public const string STRIPE_INFO = '▒';
    public const string STRIPE_HINT = '░';

    // Debug status-bar segment (the two states are temporally exclusive).
    public const string DEBUG_RUNNING = '▶';
    public const string DEBUG_PAUSED = '⏸';

    // The "you are here" row marker in navigation target lists (peek/choose
    // target). NOT ▶ — the editor gutter owns ▶ for the debugger's stopped line.
    public const string NAV_CURRENT = '→';

    // Directional markers for "one thing becomes another": the find-in-files
    // replace preview (`old → new`) and a git rename entry (`new ← old`). Same
    // → value as NAV_CURRENT on a disjoint surface.
    public const string REPLACE_ARROW = '→';
    public const string RENAME_FROM = '←';

    // The menu dropdown's separator rule (a full-width row between item groups).
    // Same ─ value as FRAME_HORIZONTAL on a disjoint surface.
    public const string MENU_SEPARATOR = '─';

    // The editor's indent guide, one per indent level on a blank leading column.
    // Same │ value as SEPARATOR on a disjoint surface (a guide, not a border).
    public const string INDENT_GUIDE = '│';

    // The split (side-by-side) diff's centre column and gutter border.
    // Same │ value as SEPARATOR on a disjoint surface.
    public const string DIFF_COLUMN = '│';

    // Tab strips.
    public const string DIRTY = '●';
    public const string SESSION_STOPPED = '○';
    public const string SEPARATOR = '│'; // between two tabs of one family
    public const string FAMILY_SEPARATOR = '┃'; // between two families — heavier, because a drag cannot cross it
    public const string CLOSE = '×'; // the tab's close affordance; {@see \Porta\Widget\Render\TabCloseGlyph} owns its shade
    public const string SCROLL_PREV = '‹';
    public const string SCROLL_NEXT = '›';
    public const string SCROLL_UP = '▲';
    public const string SCROLL_DOWN = '▼';

    // The help viewer's back/forward header arrows.
    public const string NAV_BACK = '‹'; // same value as SCROLL_PREV — disjoint surfaces (help header vs strip edge)
    public const string NAV_FORWARD = '›'; // same value as SCROLL_NEXT — disjoint surfaces

    // The help viewer's markdown presentation glyphs: a thematic-break/table rule,
    // the heavier h1 underline, the bullet, and the blockquote bar. Disjoint from
    // the chrome dividers above (this surface is rendered document text).
    public const string MD_RULE = '─'; // same value as FRAME_HORIZONTAL — disjoint surface
    public const string MD_HEADING_RULE = '═';
    public const string MD_BULLET = '•';
    public const string MD_QUOTE_BAR = '│'; // same value as SEPARATOR — disjoint surface

    // The draggable column between two panes. Thin at rest, heavy while the
    // pointer is on it or dragging it: the affordance is the GLYPH, so it
    // survives a terminal with no colour (where the hover shade resolves to
    // nothing) — a resize boundary nobody can find is the bug being fixed.
    public const string PANE_DIVIDER = '│'; // same value as SEPARATOR — disjoint surfaces (pane column vs tab strip)
    public const string PANE_DIVIDER_ACTIVE = '┃';

    // The bottom dock's height grab is the strip's own row: at rest it stays the
    // plain Toolbar strip (already a visible boundary), but the pointer over its
    // empty tail heavy-rules it, so the drag-to-resize reads before you find it
    // by accident. Horizontal counterpart of PANE_DIVIDER_ACTIVE; hover-only, so
    // there is no thin rest glyph. The GLYPH carries it, surviving --no-ansi.
    public const string PANEL_RESIZE_GRAB = '━';

    // The image pane's focus frame: box-drawing edges drawn only while the pane
    // is focused, so a caret-less, row-less image still shows it holds focus.
    // The GLYPHS carry the cue (accent-tinted in colour themes, a bare box under
    // --no-ansi), the same "affordance is the glyph" rationale as the dividers.
    public const string FRAME_TOP_LEFT = '┌';
    public const string FRAME_TOP_RIGHT = '┐';
    public const string FRAME_BOTTOM_LEFT = '└';
    public const string FRAME_BOTTOM_RIGHT = '┘';
    public const string FRAME_HORIZONTAL = '─';
    public const string FRAME_VERTICAL = '│'; // same value as PANE_DIVIDER — disjoint surfaces (image frame vs pane column)

    // Git.
    public const string BRANCH_CURRENT = '✓';
    public const string GIT_ADDED = '▎';
    public const string GIT_MODIFIED = '┆';
    public const string GIT_DELETED = '▁';

    // Toggles — bracketed so on/off reads without color.
    public const string CHECKBOX_ON = '[x]';
    public const string CHECKBOX_PARTIAL = '[~]';
    public const string CHECKBOX_OFF = '[ ]';

    // The floating modals' frame: the rounded family, deliberately distinct
    // from the image pane's square FRAME_* focus frame — a modal is a separate
    // surface with its own visual language, and the two never meet on screen.
    // All 1 column wide (EAW Narrow), like every frame glyph.
    public const string MODAL_TOP_LEFT = '╭';
    public const string MODAL_TOP_RIGHT = '╮';
    public const string MODAL_BOTTOM_LEFT = '╰';
    public const string MODAL_BOTTOM_RIGHT = '╯';

    // Section separators inside a modal frame (Find in Files' field block /
    // results / footer): tees joining the side rails, so the zones read as
    // zones of one box rather than stacked boxes.
    public const string MODAL_TEE_LEFT = '├';
    public const string MODAL_TEE_RIGHT = '┤';

    // The resizable modals' corner grab: the bottom-right corner heavies under
    // the pointer (and while dragging), the PANE_DIVIDER → PANE_DIVIDER_ACTIVE
    // thin/heavy convention — the affordance is the GLYPH, surviving --no-ansi.
    public const string MODAL_RESIZE_GRAB = '┛';

    // The Welcome screen's link-step marker: bracketed like the checkboxes so
    // it reads as the same control family, but visibly not a checkbox — a link
    // step has no done state to tick. Same → value as NAV_CURRENT on a
    // disjoint surface (a step marker never appears beside a target list).
    public const string LINK_MARKER = '[→]';

    // The 1-column left-half-block marking every row of the selected Welcome
    // card. The glyph, not the tint behind it, is the selection — it is what
    // survives --no-ansi and the monochrome theme. Distinct from GIT_ADDED
    // (`▎`, editor gutter) and on a disjoint surface anyway.
    public const string CARD_ACCENT = '▌';

    // The truncation mark. Sites that deliberately truncate WITHOUT a mark
    // (PTY output rows, fixed-width chrome fillers) pass '' and say why.
    public const string ELLIPSIS = '…';
}
