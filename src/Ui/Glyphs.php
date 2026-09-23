<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The IDE-wide glyph vocabulary — every semantic marker drawn as a bare
 * character lives here so surfaces stay consistent and glyph overloads are
 * deliberate, not accidental. Rules of the vocabulary: glyphs sharing one
 * surface (e.g. the editor gutter's marker column) must be distinct; the same
 * value on disjoint surfaces is fine and is called out where it happens. All
 * single-character glyphs are one display column wide, which the whole render
 * path's column arithmetic depends on; {@see
 * \Porta\Tests\Unit\Ui\GlyphsWidthTest} is where that is enforced.
 *
 * They are not, however, East Asian Width *Narrow*, as this comment claimed for
 * a long time. Most of the vocabulary is *Ambiguous* — the box-drawing set, the
 * arrows, the geometric shapes, and the pane separator itself — and Ambiguous
 * is one column only because terminals conventionally render it so. A terminal
 * configured the other way (a CJK locale will do it) makes every row carrying
 * one of these over-wide, and an over-wide row does not merely look wrong: the
 * terminal wraps it, and since differential repaints are positioned with
 * relative cursor moves, every later frame paints a row off. Measured: with
 * Ambiguous rendered as two columns, 66 of 67 rows of the settings screen are
 * corrupted. Prefer a Narrow or Neutral glyph when there is a choice.
 */
final class Glyphs
{
    // Disclosure triangles (tree nodes, collapsible list/panel sections, and
    // the editor's fold column — the same "open/closed" meaning each time).
    // COLLAPSED is NOT the gutter's STOPPED_LINE ▶: they sit in adjacent
    // columns, so the outline/filled distinction is the one that has to read.
    public const string EXPANDED = '▾';
    public const string COLLAPSED = '▸';

    // Editor gutter marker column. Debug decorations and diagnostic severity
    // glyphs share this column, so the two sets must stay disjoint.
    public const string BREAKPOINT = '●'; // same value as DIRTY — disjoint surfaces (gutter vs tab label)
    public const string BREAKPOINT_CONDITIONAL = '◆';
    public const string LOGPOINT = '◇'; // a logpoint logs to the console instead of pausing
    public const string STOPPED_LINE = '▶';

    // A bookmarked line. Lowest priority in the marker column — a breakpoint or
    // a diagnostic on the same line is the more urgent thing to say — and
    // shape-distinct from every glyph that can share that column.
    public const string BOOKMARK = '◈';

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
    // The stripe's non-diagnostic marks. Each is shape-distinct from the ramp
    // above and from the others, because precedence gives a shared row to one
    // of them and the loser leaves no trace. STRIPE_SEARCH's horizontal dashes
    // are deliberately NOT VCS_MODIFIED's vertical ones, which the change marks
    // bring onto this same column. The breakpoint mark reuses BREAKPOINT itself:
    // the same meaning on a disjoint surface (the stripe, not the marker column).
    public const string STRIPE_SEARCH = '┈';

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
    // What a live drag would drop here, trailing the target row. Same value as
    // RENAME_FROM — disjoint surfaces (a tree row mid-drag vs a rename prompt).
    public const string DROP_INTO = '←';

    // The menu dropdown's separator rule (a full-width row between item groups).
    // Same ─ value as FRAME_HORIZONTAL on a disjoint surface.
    public const string MENU_SEPARATOR = '─';

    // The editor's indent guide, one per indent level on a blank leading column.
    // Same │ value as SEPARATOR on a disjoint surface (a guide, not a border).
    public const string INDENT_GUIDE = '│';

    // Show-whitespace markers, drawn INSIDE the code text. Both values are
    // already in this vocabulary on other surfaces and the reuse is deliberate:
    // WHITESPACE_SPACE is SEVERITY_HINT's dot, which lives in the gutter's
    // marker column, and WHITESPACE_TAB is NAV_CURRENT's arrow, which lives in
    // a results row. Neither shares a surface with the editor's content.
    public const string WHITESPACE_SPACE = '·';
    public const string WHITESPACE_TAB = '→';

    // The split (side-by-side) diff's centre column and gutter border.
    // Same │ value as SEPARATOR on a disjoint surface.
    public const string DIFF_COLUMN = '│';

    // Tab strips.
    public const string DIRTY = '●';
    // Same value as DIRTY and BREAKPOINT — disjoint surfaces again (a settings
    // row's marker column vs a tab label vs the editor gutter).
    public const string SETTING_MODIFIED = '●';
    public const string SESSION_STOPPED = '○';
    public const string SEPARATOR = '│'; // between two tabs of one family
    public const string FAMILY_SEPARATOR = '┃'; // between two families — heavier, because a drag cannot cross it
    // A pinned tab, prefixed to its label. Distinct from DIRTY ● and CLOSE ×,
    // which share the tab surface with it; one display column, like every
    // glyph here.
    public const string PINNED = '⌾';

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
    public const string VCS_ADDED = '▎';
    public const string VCS_MODIFIED = '┆';
    public const string VCS_DELETED = '▁';

    // Toggles — bracketed so on/off reads without color, the brackets being
    // the box a browser draws. ✓ and – are neutral, not Latin letters: inside
    // a mirrored row the control's own direction marks hold them together
    // (SettingsFormWidget::gap()). ✓ is the same value as BRANCH_CURRENT —
    // disjoint surfaces (a checkbox is always bracketed).
    public const string CHECKBOX_ON = '[✓]';
    public const string CHECKBOX_PARTIAL = '[–]';
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

    // A text field's border (FieldBox): the SHAPE is the field's state, so
    // focus reads with no colour — rounded idle (the MODAL_* corners and the
    // FRAME_* rules), heavy while it takes keys, double for a destructive
    // prompt. Same thin/heavy convention as PANE_DIVIDER → PANE_DIVIDER_ACTIVE.
    public const string FIELD_FOCUS_TOP_LEFT = '┏';
    public const string FIELD_FOCUS_TOP_RIGHT = '┓';
    public const string FIELD_FOCUS_BOTTOM_LEFT = '┗';
    public const string FIELD_FOCUS_BOTTOM_RIGHT = '┛'; // same value as MODAL_RESIZE_GRAB — disjoint surfaces (field vs modal corner)
    public const string FIELD_FOCUS_HORIZONTAL = '━'; // same value as PANEL_RESIZE_GRAB — disjoint surfaces
    public const string FIELD_FOCUS_VERTICAL = '┃'; // same value as PANE_DIVIDER_ACTIVE — disjoint surfaces
    public const string FIELD_DANGER_TOP_LEFT = '╔';
    public const string FIELD_DANGER_TOP_RIGHT = '╗';
    public const string FIELD_DANGER_BOTTOM_LEFT = '╚';
    public const string FIELD_DANGER_BOTTOM_RIGHT = '╝';
    public const string FIELD_DANGER_HORIZONTAL = '═';
    public const string FIELD_DANGER_VERTICAL = '║';
    // The heavy frame's tees: a menu that holds the keys is heavy-framed
    // (FieldState::Focused), and its separators must join that frame rather
    // than the light one.
    public const string FIELD_FOCUS_TEE_LEFT = '┣';
    public const string FIELD_FOCUS_TEE_RIGHT = '┫';
    // Disabled: the rounded corners with a dashed rule, the way a browser
    // greys an input — a shape of its own, so "not available" never passes for
    // "at rest" without colour. ┆ is the same value as VCS_MODIFIED — disjoint
    // surfaces (a field rail vs the editor gutter).
    public const string FIELD_DISABLED_HORIZONTAL = '┄';
    public const string FIELD_DISABLED_VERTICAL = '┆';

    // A select's closed-state marker (the browser's ▾), and an int stepper's
    // two arrows. ◂ and ▸ are the same values as the tree's disclosure
    // triangles — disjoint surfaces (a boxed control vs a tree row).
    public const string SELECT_ARROW = '▾';
    public const string STEPPER_DOWN = '◂';
    public const string STEPPER_UP = '▸';
    // The option a select's open list is currently set to, in the list's
    // shortcut column — the browser's check beside the chosen <option>.
    // Same value as BRANCH_CURRENT — disjoint surfaces.
    public const string OPTION_CURRENT = '✓';

    // The resizable modals' corner grab: the bottom-right corner heavies under
    // the pointer (and while dragging), the PANE_DIVIDER → PANE_DIVIDER_ACTIVE
    // thin/heavy convention — the affordance is the GLYPH, surviving --no-ansi.
    public const string MODAL_RESIZE_GRAB = '┛';

    // The breadcrumb bar's scope separator (` › `-spaced when rendered):
    // guillemet-family like NAV_NEXT, deliberately lighter than the tree's
    // disclosure triangles — a crumb is a path, not a fold.
    public const string BREADCRUMB_SEPARATOR = '›';

    // The status bar's git-branch marker: the Powerline private-use branch
    // glyph (U+E0A0), the terminal ecosystem's lingua franca for "branch" —
    // patched fonts render it; unpatched ones show a box, which is why it
    // stays confined to the status bar's optional branch segment.
    public const string STATUS_BRANCH = "\u{e0a0}";

    // Toast severity markers, one surface (the corner cards): shape-distinct
    // so the rank reads without color (monochrome, --no-ansi). All 1 column
    // wide; ✗/✓ deliberately echo the diff/check vocabulary — same rank, same
    // shape — while ◦ (info) is visibly not the tree bullet's context.
    public const string TOAST_ERROR = '✗';
    public const string TOAST_WARN = '▲';
    public const string TOAST_INFO = '◦';
    public const string TOAST_SUCCESS = '✓';

    // The Welcome screen's link-step marker: bracketed like the checkboxes so
    // it reads as the same control family, but visibly not a checkbox — a link
    // step has no done state to tick. Same → value as NAV_CURRENT on a
    // disjoint surface (a step marker never appears beside a target list).
    public const string LINK_MARKER = '[→]';

    // The 1-column left-half-block marking every row of the selected Welcome
    // card. The glyph, not the tint behind it, is the selection — it is what
    // survives --no-ansi and the monochrome theme. Distinct from VCS_ADDED
    // (`▎`, editor gutter) and on a disjoint surface anyway.
    public const string CARD_ACCENT = '▌';

    // The truncation mark. Sites that deliberately truncate WITHOUT a mark
    // (PTY output rows, fixed-width chrome fillers) pass '' and say why.
    public const string ELLIPSIS = '…';
}
