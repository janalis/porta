# Diff

The diff the other plugins show — the pane the [Git view](../../git/docs/README.md#diff-pane)
and code review draw a file's changes in, and the model behind it, a unified
diff read into hunks with the changed words inside a changed line picked out —
and the merge view. Enabled by default, because git needs it: a project whose
`enabledPlugins` names `git` must name `diff` too, or git is skipped with a
warning. Disabling it disables the plugins that need it.

## The merge view

A file holding conflict markers opens as three columns: **ours** on the left,
the **result** in the editor between, **theirs** on the right. It does so
however the file got its markers — a `git merge` or `git rebase` that stopped
on a conflict, or `git mergetool` launching Porta on it
([setup](../../git/docs/README.md#as-gits-difftool-and-mergetool)).

Each side is the result with every conflict replaced by that side's lines,
drawn level with the result, and the three scroll as one. A conflict's first
row on a side is its label — the branch git named — behind an accept mark:
`⇥` on ours, `⇤` on theirs. Click it, or press `a` with the keys on that side,
and the result keeps that side's lines for the conflict; `b` keeps both, ours
first. Each is one undo step in the editor, and the result is still the
editor's text: edit it by hand as much as you like. A diff3 or zdiff3 base
section is neither side's and is never kept. The status bar says which
conflict of how many the keys act on — the one the caret is in, else the next
below it — and, once none is left, that the result only needs saving.

The two sides read the markers, not git: a change git merged by itself
outside the conflicts is in the result already, on both sides alike. Drag the
dividers to resize the columns; each keeps its width. A narrow window drops
ours first, then theirs, and keeps the result.

## The diff pane

Unified (dual gutters) or side-by-side, added rows on a green band and deleted
ones on a red one, the changed words a shade stronger. Its file header is one
bar row — the path, its folder dimmed — and the ⚙ ▾ at its end drops the
display settings. What a key does beyond moving and copying is up to the view
the pane is in: in the Git view `Space` stages, in a read-only comparison it
does nothing.

## The diff pane

Unified (dual gutters) or side-by-side, added rows on a green band and deleted
ones on a red one, the changed words a shade stronger. Its file header is one
bar row — the path, its folder dimmed — and the ⚙ ▾ at its end drops the
display settings. What a key does beyond moving and copying is up to the view
the pane is in: in the Git view `Space` stages, in a read-only comparison it
does nothing.

## Settings

None.

## Keys

In the merge view, with the keys on ours or theirs:

| Key | Action |
| --- | --- |
| `a` / `Enter` | Keep this side for the conflict |
| `b` | Keep both, ours first |
| `n` / `p` | The caret to the next / previous conflict |

In the diff pane:

| Key | Action |
| --- | --- |
| `↑` / `↓`, `PgUp` / `PgDn` | Move the cursor row |
| `←` / `→` | Scroll sideways |
| `v` | Start a line range |
| `Space` | Act on the hunk or the range — stage it, in the Git view |
| `c` | Cycle the context around each hunk |
| `s` | Toggle unified / side-by-side |
| `w` | Toggle hiding whitespace-only changes |
| `Ctrl+C` | Copy the selected lines |
| `Tab` | Next pane of the view |
| `Esc` | Leave the view |
