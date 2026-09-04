# Editor

## Syntax highlighting

PHP, JS/TS, JSON, YAML, Twig, Markdown, CSS, SQL and more (via
[tempest/highlight](https://github.com/tempestphp/highlight)), including
correct coloring of multi-line docblocks and heredocs. Highlighting is
incremental on files ≥ 2000 lines: local edits re-parse only the touched
lines, with a periodic full repair parse to restore multi-line token colors.

Two color themes ship (`dark`, `light`) and switch live from Settings.

### Semantic highlighting

Where the language server supports it, colouring is corrected by MEANING rather
than by shape: a property is coloured differently from a local variable and from
a parameter, a `static` member leans, and a `deprecated` symbol is struck
through. The lexer can only guess these from an identifier's shape; the server
knows.

This layers ON TOP of the syntax colours above rather than replacing them — the
server's own vocabulary has no entry for strings or numbers — so it works
whichever highlight backend you have selected, and it needs no theme of its own.

It is requested once per file and refreshed on save. Servers that do not offer
`textDocument/semanticTokens` (phpactor, at the time of writing; phpantom does
offer it) simply keep the lexer's colours, with nothing to configure either way.

### Parameter info

Typing `(` or `,` shows the signature of the call you are in, with **the
argument you are typing in bold** — the server says which one it is, so a
five-parameter signature no longer reads the same at every comma. `Ctrl+Shift+P`
brings the popup back after `Escape`, or summons it anywhere inside an argument
list. (PhpStorm binds this to `Ctrl+P`, which Go to File owns here.)

### Breadcrumbs

The bar under the tabs names the scopes containing the caret —
`Namespace › Class › method` — and each crumb is clickable.

PHP is scanned offline, so it is instant and correct with no language server
running at all. Other languages use the server's own `textDocument/documentSymbol`
answer, refreshed on save and on opening a buffer; without a server that provides
it, the bar simply stays empty as before.

The sticky-scroll band above the code reads the same scopes, so it names your
enclosing class and method in every language the trail does.

## Buffers and tabs

Multiple buffers with an editor tab bar: `Ctrl+←`/`Ctrl+→` switch,
`Ctrl+Shift+←`/`Ctrl+Shift+→` reorder, `Ctrl+F4`/`Alt+W` closes (with a
Save / Discard / Cancel prompt on unsaved changes), `Ctrl+E` opens the
recent-files popup. Every buffer keeps its cursor, undo history and
diagnostics. The open set is restored on the next launch.

The recent-files popup outlives the tab: below the open buffers it lists the
files this project has had open and closed, newest first, which is usually
where the one you want is. The same list seeds Search Everywhere's empty
state, so the two cannot disagree about what "recent" means. It is kept in
`.porta/recent-files.json`, capped at 32, and a file that has since been
deleted is dropped rather than offered. Help pages and images are never
recorded — reopening a help page as editable source is not a switch.

Files larger than 2 MB and non-UTF-8 files are refused. Clean buffers that
change on disk (e.g. rewritten by a formatter) reload automatically within
a second.

## Editing

- **Undo/redo** (`Ctrl+Z` / `Ctrl+Shift+Z`) with coalesced typing runs.
- **Format on save** — enable the `formatOnSave` setting to run the language
  server's formatter before every `Ctrl+S`. The save never hangs: if the
  server takes more than 2 seconds the file is saved unformatted with a
  status-bar note. `Close Other Tabs` / `Close All Tabs` live in the
  command palette alongside the tab context menu, which also offers
  **Close to the Left** and **Close to the Right** — the run of tabs beside
  this one in the strip. Every bulk close leaves unsaved tabs open and says
  how many it kept.
- **Pinned tabs** (`Pin Tab` on the tab menu) carry a `⌾` and survive every
  bulk close — Close Others, Close All, and both close-to-the-side verbs. The
  pin is saved with the session, so a tab you deliberately kept is still there
  next launch. Pinning does not reorder the strip.
- **Column selection** by mouse (`Alt+Shift+drag`) or keyboard: `Alt+C` toggles
  column mode — the status bar shows `COLUMN` — and `Shift`+arrows then build a
  rectangle instead of a linear selection. Passing over a short line does not
  narrow it; the column is remembered. PhpStorm's `Alt+Shift+Insert` is not
  available in a terminal, hence `Alt+C`.
- **Block copy and paste round-trip**: a column selection copies as one line
  per row, and pasting a block whose line count matches the caret count gives
  each caret its own line, top to bottom. A clipboard that does not match is
  repeated whole at every caret, as before.
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

## Copy path and reference

`Ctrl+Shift+C` copies the active file's path relative to the project.
`Ctrl+Alt+Shift+C` copies a **reference** to where the caret is: the enclosing
symbol when there is one (`App\Http\Svc::run`, or `App\helper` for a namespaced
function), and `path/to/file.php:12` when there is not. Both are on the editor's
right-click menu; the file tree has had the two path forms on its own menu all
along.

A symbol is the more useful half in a message or a test filter — it survives the
file being edited underneath it, where a line number does not — which is why the
line number is the fallback rather than the default. The scopes come from the
same source the breadcrumb trail reads, so this works in every language that
does.

## Scratch files

`Ctrl+Alt+Shift+S` asks which language and opens an empty buffer for it —
somewhere to paste a snippet, draft a query or try a syntax without adding a
file to the project or a line to `git status`.

They live in `<home>/.porta/scratches/`, beside the global config and the global
plugins rather than in the project, because that is what makes them scratch
files: the snippet you are reformatting outlives whichever project happened to
be open. Names are minted per language — `scratch_1.php`, `scratch_2.php`, and
a separate `scratch_1.md` — so a tab strip full of them still reads.

Picking a language rather than typing a name is the whole interaction: the
extension is the only thing the editor needs in order to highlight, indent and
hand the file to a language server. The list is the same thirteen languages the
settings screen configures, plus plain text.

PhpStorm's binding for this is `Ctrl+Alt+Shift+Insert`, which a terminal cannot
deliver — every modified `Insert` sequence parses to nothing — so the `S` chord
stands in. Rebind it like any other action; see
[keybindings.md](keybindings.md).

## Method separators

Off by default (Settings → Interface, or `methodSeparators` in `.porta.json`).
A thin rule closing off each method and named function — PhpStorm's
separator, in the place PhpStorm puts it: along the bottom edge of the member's
last line.

It is an underline on that row rather than a row of its own, so turning it on
costs no vertical space and shifts nothing. Under the closing brace is also the
only placement that survives docblocks: the line ABOVE the next method is
usually its own `/** … */` or `#[\Override]`, and a rule through one of those
reads as a mistake.

The lines come from the same scopes the breadcrumb trail and the sticky band
read — the offline PHP scan, or the language server's document symbols — so the
rules appear in every language those work in. Abstract and interface methods
have no body to close, and get none.

## Soft wrap

`Alt+Z`, off by default. A line longer than the pane continues on the next row
or rows instead of running off the right edge. Continuation rows carry no line
number and no gutter marks, so the numbers still count lines rather than rows.

**Horizontal scroll is disabled while it is on**, because the two are the same
mechanism read two ways: a wrapped segment is the same slice of a decorated
line that `Ctrl+←`-style scrolling produces, taken at a fixed offset instead of
a moving one.

Everything follows the visual rows, not the document lines. `↑`/`↓` step
through a wrapped line's segments before leaving it, arriving in the first
segment from above and the last from below; clicking lands on the row you
clicked; and the viewport scrolls INTO a line taller than the pane, which is
the only way to reach a caret near the end of one.

Wrapping is measured on the line's own text, not on its decorations. Inlay
hints and inline diagnostics ride in the same row and would otherwise make the
text reflow as they arrived — they clip at the row edge as they already do.

## Code vision

`Alt+V`, off by default. The dim "3 usages" line a language server offers above
a declaration, from `textDocument/codeLens`. Each lens takes a row of its own
above the line it describes — the only decoration that ADDS a row rather than
painting into one — and clicking it runs the command the server attached, or
says so when the lens is informational and carries none.

Requested on a buffer switch and on save, which is the same settle point the
git change marks use: a lens count is a real round trip, not something to spend
per keystroke. Turning it off stops the requests, not just the painting.

phpantom advertises `codeLensProvider`; phpactor does not, and is never asked.

## Bookmarks

`F11` toggles one on the caret's line, `Alt+2` lists every mark in the project
with the line's own text beside it. Marks show as `◈` on the gutter's marker
column, below breakpoints and diagnostics in priority, and survive closing the
file — they live in `.porta/bookmarks.json`, not on the buffer.

A rename carries them with the file, whether it came from the tree or from a
language server's refactoring. A delete does not; the mark is dropped the next
time the file fails to open.

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

## Refactoring

Porta does not implement refactorings; it asks the language server for the ones
it has and applies what comes back. What that needs, and now does:

- **A selection is what the request asks about.** `refactor.extract` needs a real
  range — a caret point can never be one — so select the expression or block
  first, then `Alt+Enter` or `Ctrl+Alt+Shift+T`.
- **Refactor This** (`Ctrl+Alt+Shift+T`) narrows the list to `refactor` kinds;
  `Alt+Enter` keeps everything, quick fixes included. **Inline** (`Ctrl+Alt+N`)
  applies straight away when the server offers exactly one, and asks when it
  offers several. Extract stays a list, because servers publish variable,
  constant and method behind one `refactor.extract` kind.
- **Deferred edits are resolved.** A server may answer with a title and hold the
  edit back until `codeAction/resolve` — phpantom does this for extract and
  inline. Those actions used to appear and do nothing.
- **File operations are all or nothing.** A refactoring that creates, renames or
  deletes files (extract to a new class, move a class) is applied whole or
  refused whole, and the refusal says why. Half of one — references rewritten to
  a file that was never created — is worse than none. A rename takes the open
  buffer with it.

The two bundled PHP servers differ in what they offer: phpantom publishes
`refactor.extract`, `refactor.inline` and `source.organizeImports`; phpactor
publishes a longer list of narrower kinds. Both are reached the same way.

**Optimize imports** (`Ctrl+Alt+O`) asks the server for `source.organizeImports`
alone. One answer is applied straight away — organising imports is a whole-file
operation rather than a choice between alternatives — and several offer a
picker. Nothing to organise says so rather than reporting no quick fixes.

## Merge conflicts

Jump between `<<<<<<<`/`=======`/`>>>>>>>` regions (`F7` / `Shift+F7`,
when no debug session is running) and resolve the one under the caret
(`Alt+M`) by keeping ours, theirs or both — a single undoable edit.

## Local history

Every save snapshots the file under `.porta/history/` (25 snapshots per
file, oldest pruned) independently of git. `Ctrl+Alt+H` lists the active
file's snapshots; `Enter` restores one as a single undoable edit.

**Compare with Local History** (the command palette, or a right-click on the
editor or on a file) shows what a snapshot would change before you restore it:
pick one, and the diff opens in its own panel — oldest on the left, so a `-`
line is what restoring would bring back and a `+` line is what it would take
away. The newest snapshot is always the file as it stands, because saving is
what takes one, and picking it says so rather than opening an empty panel.

That comparison is the one part that needs `git` on your `PATH` — it runs
`git diff --no-index`, which takes two arbitrary paths and so works in a
project that is not a repository. Without git the snapshots still record and
still restore; only the comparison is unavailable, and it says so.

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
