# Diff

The diff the other plugins show: the pane the [Git view](../../git/docs/README.md#diff-pane)
draws a file's changes in, and the model behind it — a unified diff read into
hunks, with the changed words inside a changed line picked out. Enabled by
default, because git needs it: a project whose `enabledPlugins` names `git`
must name `diff` too, or git is skipped with a warning.

It adds no command or view of its own; disabling it disables the plugins that
need it.

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
