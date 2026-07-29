# Editor

## Syntax highlighting

PHP, JS/TS, JSON, YAML, Twig, Markdown, CSS, SQL and more (via
[tempest/highlight](https://github.com/tempestphp/highlight)), including
correct coloring of multi-line docblocks and heredocs. Highlighting is
incremental on files ≥ 2000 lines: local edits re-parse only the touched
lines, with a periodic full repair parse to restore multi-line token colors.

Two color themes ship (`dark`, `light`) and switch live from Settings.

## Buffers and tabs

Multiple buffers with an editor tab bar: `Ctrl+←`/`Ctrl+→` switch,
`Ctrl+Shift+←`/`Ctrl+Shift+→` reorder, `Ctrl+F4`/`Alt+W` closes (with a
Save / Discard / Cancel prompt on unsaved changes), `Ctrl+E` opens the
recent-files popup. Every buffer keeps its cursor, undo history and
diagnostics. The open set is restored on the next launch.

Files larger than 2 MB and non-UTF-8 files are refused. Clean buffers that
change on disk (e.g. rewritten by a formatter) reload automatically within
a second.

## Editing

- **Undo/redo** (`Ctrl+Z` / `Ctrl+Shift+Z`) with coalesced typing runs.
- **Format on save** — enable the `formatOnSave` setting to run the language
  server's formatter before every `Ctrl+S`. The save never hangs: if the
  server takes more than 2 seconds the file is saved unformatted with a
  status-bar note. `Close Other Tabs` / `Close All Tabs` live in the
  command palette alongside the tab context menu.
- **Auto-closing brackets & quotes**: typing an opener inserts its pair,
  selections get wrapped, closers type over, and backspacing an empty pair
  removes both — toggleable (`autoPairs`).
- **Smart Enter** between a `{}`/`()`/`[]` pair opens a blank indented line
  with the closer dropped below; **smart Home** goes to the first non-blank
  column first.
- **Structural editing**: expand/shrink the selection through the syntactic
  hierarchy — caret → word → brackets/quotes → line → document (`Ctrl+W` /
  `Ctrl+Shift+W`); move a statement over blank-line gaps
  (`Ctrl+Shift+↑/↓`); surround the selection with a bracket pair, quotes or
  an `if`/`foreach`/`try` snippet (`Ctrl+Alt+T`).
- **Code folding** (`Ctrl+.`): collapse/expand the `{ … }` block at the
  caret; a `⋯ N lines` marker shows on the folded line, and clicks and
  scrolling map through hidden lines.
- **Line operations**: duplicate (`Ctrl+D`), delete (`Ctrl+Y`), move line
  (`Alt+Shift+↑/↓`), toggle comment (`Ctrl+/`).
- **Clipboard** (`Ctrl+C/X/V`) also copies to the system clipboard via
  OSC 52.

## Multiple carets

Add carets above/below (`Ctrl+Alt+↑/↓`), at the next occurrence of the
word (`Alt+J`) or at all occurrences (`Ctrl+Alt+Shift+J`); `Alt+Click`
adds/removes one, `Alt+Shift+drag` makes a column selection. Typing,
deleting and pasting apply at every caret as a single atomic undo step.
`Esc` collapses back to one caret.

## Indentation

A file's original indentation (tabs or spaces) round-trips unchanged. Tabs
render at 4-cell stops, and `Tab`/auto-indent insert the file's own indent
unit. Per-language defaults are configurable (Settings → the language, or
`languages.<id>` in `.porta.json`), and `.editorconfig` files take
precedence when enabled — see [configuration.md](configuration.md).

## Gutter and decorations

Line numbers (absolute or relative), git change markers (added / modified /
deleted vs `HEAD`, refreshed on open and save), diagnostic markers,
breakpoint dots, indent guides, identifier-occurrence and matching-bracket
highlighting, and dimmed inline parameter-name/type **inlay hints** from
the language server.

## Merge conflicts

Jump between `<<<<<<<`/`=======`/`>>>>>>>` regions (`F7` / `Shift+F7`,
when no debug session is running) and resolve the one under the caret
(`Alt+M`) by keeping ours, theirs or both — a single undoable edit.

## Local history

Every save snapshots the file under `.porta/history/` (25 snapshots per
file, oldest pruned) independently of git. `Ctrl+Alt+H` lists the active
file's snapshots; `Enter` restores one as a single undoable edit.

## Image viewer

Opening a `png`, `jpg`, `jpeg`, `gif`, `webp` or `bmp` file shows it as an
inline image tab (truecolor half-block rendering) instead of text. Zoom
with `=`/`-`, `0`/`f` fit, `1` actual size, or `Ctrl+wheel` anchored at the
pointer (12.5%–800%); pan with the arrows or the wheel. Images over 20 MB
or 16 megapixels are refused (the size cap is the bundled `image-viewer`
plugin's setting). The viewer is a bundled plugin, enabled by default —
disabling it in Settings ▸ Plugins routes images to the ordinary text-path
refusal. Tab-switching and go-to-file keys keep working while an image is
active.

## Graph viewer

Opening a `.dot` or `.gv` file renders the Graphviz graph as an interactive
tab: clusters, edge labels, arrowheads and DOT colors, laid out by the real
`dot` binary (install graphviz, or point the `dotPath` setting at it).
Zoom with `=`/`-`, `0`/`f` fit, `1` actual size, or `Ctrl+wheel` at the
pointer; pan with the arrows, the wheel, or by dragging; select nodes with
`n`/`p` or a click (the status bar shows the node under selection). Editing
the file re-renders it in place — an invalid document keeps the previous
graph under an error banner. The viewer is a bundled plugin, enabled by
default — disabling it opens `.dot` files as editable text instead.
**Plugins ▸ Project Architecture** generates a namespace-dependency graph of
the current project into `.porta/architecture.dot` and opens it in this viewer
(the plugin's *Architecture command* setting supplies the DOT instead — its
stdout wins); `Graph: Export SVG/PNG` in the palette renders the active graph
beside its file.

## Mouse

Click to place the cursor, drag to select (double-click a word,
triple-click a line, `Shift+Click` extends), wheel to scroll, click the
gutter to toggle a breakpoint, drag pane separators to resize, and
right-click for a context menu of common actions.

## Structure navigation

`Ctrl+Shift+M` jumps between a bracket and its match (`()`, `[]`, `{}` —
works with the caret on or just after either end). `Ctrl+.` collapses or
expands the fold at the caret; **Fold All** / **Unfold All** in the palette
collapse and restore the whole file — with a language server attached the
fold regions come from `foldingRange` (real syntax), otherwise from a brace
scan.
