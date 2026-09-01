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
  scrolling map through hidden lines. The gutter's **fold column** marks every
  line a region starts at — `▾` open, `▸` collapsed — and clicking it folds or
  unfolds that region. With a language server the regions come from
  `foldingRange`, so a fold starts at the declaration; without one they come
  from a brace scan, which starts it at the `{`.
- **Line operations**: duplicate (`Ctrl+D`), delete (`Ctrl+Y`), move line
  (`Alt+Shift+↑/↓`), toggle comment (`Ctrl+/`).
- **Clipboard** (`Ctrl+C/X/V`) also copies to the system clipboard via
  OSC 52.

## Live templates

Type an abbreviation and press `Tab` to expand it into a snippet with tab
stops — `Tab` / `Shift+Tab` walk the placeholders, `Esc` ends the session. PHP
ships `fore`, `forek`, `fori`, `ifn`, `inn`, `thr`, `tryc`, `pubf`, `prif`,
`ctor`, `sout` and `vdd`; JavaScript and TypeScript share a smaller set.

**Postfix templates** start with a dot and wrap what is already to their left,
so you write the expression first and decide what to do with it after:

| Type | Get |
| --- | --- |
| `$rows.fore` + `Tab` | `foreach ($rows as $item) { … }` |
| `$user.inn` + `Tab` | `if (null !== $user) { … }` |
| `$this->count().var` + `Tab` | `$value = $this->count();` |
| `$ok.not` + `Tab` | `!$ok` |

The receiver can be a variable, a property or static chain, a call or an index
(`$this->rows`, `Foo::bar`, `count($x)`, `$rows[0]`).

Define your own under `languages.<id>.templates` in `.porta.json` — see
[configuration.md](configuration.md#languageslangid). Yours replace a built-in of
the same name, and an empty body switches one off.

`Tab` is still `Tab`: with no template at the caret it indents as it always did,
and a language with no templates of its own is unaffected. Template bodies are
**LSP snippets** — the same syntax the language server's own completions arrive
in — so `$1` is a tab stop, `${1:default}` a placeholder, `$0` the final caret,
and a plain `$name` stays literal.

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
breakpoint dots, fold triangles, indent guides, identifier-occurrence and matching-bracket
highlighting, and dimmed inline parameter-name/type **inlay hints** from
the language server.

## Identifier highlight

Park the caret on a symbol and its other occurrences light up. With a language
server that supports `documentHighlight` (phpantom and phpactor both do) the
occurrences are the **semantic** ones — a local `$x` no longer lights up a
same-named variable from another scope — and a **write** access is shaded
differently from a read, PhpStorm-style. In the monochrome theme the two
separate by weight instead of colour.

Without a server, on a file it has no answer for, or in the moment before the
caret settles, the editor's own whole-word scan shows instead; it reports every
match as a read, because matching text cannot tell an assignment from a use.

The lookup waits for the caret to hold still, is skipped entirely while the
answer already on hand covers the caret's symbol, and is never sent to a server
that did not advertise the capability.

## The marker stripe

The editor's rightmost column maps the **whole file** onto the pane's height,
so a glance down the margin says where the interesting lines are without
scrolling. Each cell shows the most important thing landing on its slice of
the file, and the kinds resolve by strict precedence:

| Mark | Means |
| --- | --- |
| `█` `▓` `▒` `░` | a diagnostic — the block gets heavier with the severity |
| `●` | a breakpoint |
| `▎` `┆` `▁` | a git change — added / modified / deleted, the same marks the gutter draws |
| `┈` | a search hit from the find bar |

A faint band behind the marks is the **viewport thumb** — the part of the file
you are currently looking at, shown only when the file is taller than the pane.
Clicking a cell jumps there.

Every mark is a distinct shape rather than a colour, so the stripe still reads
under `--no-ansi` and in the monochrome theme. Change marks follow the git
plugin's own *gutter marks* setting: turn that off and they leave the stripe
too.

## The file tree

File rows carry what is worth knowing about the file, right-aligned and in a
fixed order: its version-control status (`▎` added / `┆` modified, the same
marks the gutter draws), `✗` or `▲` for the worst diagnostic in it, then `●` for
unsaved changes.
The version-control mark comes from the git plugin and covers the whole working
tree, so a changed file you have never opened is still marked; the other two
come from open buffers, because nothing walks the project on a frame. Info and
hint diagnostics are left unmarked, so the marks mean "something here needs
you", and directories are never marked.

**Select Opened File in Tree** (`Alt+F1`, or View ▸ Select Opened File in Tree)
expands the tree down to the file you are editing, selects it and moves focus
there, so you can carry on navigating from where the caret already was.

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
pointer (12.5%–800%); pan with the arrows or the wheel. Shrinking averages
the pixels it covers, so a downscaled image keeps its thin lines instead of
shimmering; magnifying stays pixel-crisp.

`.svg` files open here too, rasterized by an external converter — the
image-viewer plugin's `svgConverter` setting if you set one, otherwise `rsvg-convert`, `magick` or
`convert`, whichever is installed. Zooming in re-renders the drawing rather
than magnifying pixels, so it stays sharp, and the status bar reports the
document's own size (100% means 100% of the SVG). With no converter
installed — or with *Render SVG as an image* turned off on the image-viewer
plugin's settings page — `.svg` keeps opening as editable XML, as it always
has. **Open as Text** (`Ctrl+Alt+X`, or the palette) is the per-file way back:
it reopens the active viewer tab in the editor, and works for a graph's DOT or
an image's bytes just as well.

**Ctrl+two-finger-scroll on a trackpad is the pinch**: after the first notch
the zoom glides continuously toward the pointer instead of jumping between
the preset levels, while a single deliberate wheel click still snaps to the
next one. A real pinch cannot be delivered to a program running in a
terminal — the terminal receives the gesture and keeps it, usually for its
own font size, and no terminal mouse protocol can express a magnification.
If your terminal can bind a gesture to an escape sequence (iTerm2 does this
for taps and swipes), point it at whatever key `Image: Zoom In` is bound to. Images over 20 MB
or 16 megapixels are refused (the size cap is the bundled `image-viewer`
plugin's setting). The viewer is a bundled plugin, enabled by default —
disabling it in Settings ▸ Plugins routes images to the ordinary text-path
refusal. Tab-switching and go-to-file keys keep working while an image is
active.

## Graph viewer

Opening a `.dot` or `.gv` file renders the Graphviz graph as an interactive
tab, showing **the picture `dot` itself draws** — real splines, arrowheads,
fills and fonts (install graphviz, or point the graph-viewer plugin's `dotPath` setting at it).
Zoom with `=`/`-`, `0`/`f` fit, `1` actual size, or `Ctrl+wheel` at the
pointer — including the trackpad's Ctrl+two-finger-scroll, which glides (see
the image viewer above for why that is as close to a pinch as a terminal
gets); pan with the arrows, the wheel, or by dragging; select nodes with
`n`/`p` or a click, which frames the node and names it in the status bar.
Zooming in re-renders the graph at a higher resolution rather than magnifying
pixels, so labels sharpen as you go.

The same tab opens **PlantUML** documents — `.puml`, `.plantuml`, `.pu`,
`.iuml` and `.wsd` — rendered by the `plantuml` tool: set the *PlantUML path*
setting to a launcher **or straight to a `plantuml.jar`** (it is run with
`java -jar`), or leave it empty to use `plantuml` from your PATH. Zoom, pan and
the re-render-as-you-zoom all work the same, and a syntax error is reported in
the banner instead of being drawn into the picture. What a PlantUML tab does
*not* have is a node model, so `n`/`p`, click-select, the node counts and the
`v` cell rendering do nothing there. PlantUML uses Graphviz for several diagram
types (class, activity, state), so install `dot` as well if those come out
empty. With no tool installed — or with *Render PlantUML diagrams* off on the
graph-viewer plugin's page — these files stay editable text.

`v` switches to a **cell rendering** that draws node labels as terminal text
— less faithful, but readable when the picture's own type is only a few
pixels tall. The *Graph rendering* setting (Settings ▸ General) picks which
one new tabs open with. It is also what you get automatically if your
graphviz was built without PNG support, with the reason in the banner.

Editing the file re-renders it in place — an invalid document keeps the
previous graph under an error banner. The viewer is a bundled plugin, enabled by
default — disabling it opens `.dot` files as editable text instead.
**Plugins ▸ Project Architecture** generates a namespace-dependency graph of
the current project into `architecture.dot` and opens it in this viewer. The
plugin's *Architecture output directory* setting picks where that file goes —
empty means the project root, a relative path resolves from it, an absolute one
is taken as given, and the directory is created if it does not exist. (Its
*Architecture command* setting supplies the DOT instead — that command's stdout
wins.) `Graph: Export SVG/PNG` in the palette renders the active graph
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
