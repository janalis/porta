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
    public const string STOPPED_LINE = '▶';

    // Diagnostic severity (gutter, inline message prefix, status-bar counts).
    public const string SEVERITY_ERROR = '✗';
    public const string SEVERITY_WARNING = '▲'; // also SCROLL_UP — disjoint surfaces (message row vs strip edge)
    public const string SEVERITY_INFO = '◦'; // NOT ◆ — the conditional breakpoint owns ◆ in the same gutter column
    public const string SEVERITY_HINT = '·';

    // Debug status-bar segment (the two states are temporally exclusive).
    public const string DEBUG_RUNNING = '▶';
    public const string DEBUG_PAUSED = '⏸';

    // The "you are here" row marker in navigation target lists (peek/choose
    // target). NOT ▶ — the editor gutter owns ▶ for the debugger's stopped line.
    public const string NAV_CURRENT = '→';

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

    // The draggable column between two panes. Thin at rest, heavy while the
    // pointer is on it or dragging it: the affordance is the GLYPH, so it
    // survives a terminal with no colour (where the hover shade resolves to
    // nothing) — a resize boundary nobody can find is the bug being fixed.
    public const string PANE_DIVIDER = '│'; // same value as SEPARATOR — disjoint surfaces (pane column vs tab strip)
    public const string PANE_DIVIDER_ACTIVE = '┃';

    // Git.
    public const string BRANCH_CURRENT = '✓';
    public const string GIT_ADDED = '▎';
    public const string GIT_MODIFIED = '┆';
    public const string GIT_DELETED = '▁';

    // Toggles — bracketed so on/off reads without color.
    public const string CHECKBOX_ON = '[x]';
    public const string CHECKBOX_PARTIAL = '[~]';
    public const string CHECKBOX_OFF = '[ ]';

    // The truncation mark. Sites that deliberately truncate WITHOUT a mark
    // (PTY output rows, fixed-width chrome fillers) pass '' and say why.
    public const string ELLIPSIS = '…';
}
