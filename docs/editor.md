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
argument you are typing in bold and underlined**. The server says which one it
is, so a five-parameter signature no longer reads the same at every comma. A
signature too long for the popup is cut around that argument, not at its end.
A function with overloads shows which one this is (`1/3`), and
`Alt+↓`/`Alt+↑` walk them. `Ctrl+Shift+P`
brings the popup back after `Escape`, or summons it anywhere inside an argument
list. (PhpStorm binds this to `Ctrl+P`, which Go to File owns here.)

### Quick documentation

`Shift+F1` shows the documentation for the symbol at the caret in a popup just
under it (or above it, when under has no room). Markdown's marks are gone and
code blocks are indented. The popup scrolls with `↑`/`↓`, `PgUp`/`PgDn` or the
wheel, and a `▲`/`▼` in its border says there is more. `Esc`, or any other key,
closes it; the other key then does what it always does. A click on the popup
stays there, and a click anywhere else closes it.

Quick fixes (`Alt+Enter`) put the server's preferred fix first, marked `★`. An
action the server knows but cannot run where the caret is appears greyed and
struck through, with the server's reason beside it.

### Breadcrumbs

The bar under the tabs names the scopes containing the caret —
`Namespace › Class › method` — and each crumb is clickable. When the bar is too
narrow for the whole trail it drops scopes from the outside in, behind a
leading `… ›`, so the scope the caret is in is always shown.

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

The View menu's toggles (Soft-Wrap, Code Vision, inline diagnostics,
auto-pairs, format on save) show a `✓` while they are on.

On a terminal 30 rows or taller the tabs are folder tabs, two rows each. The
shown tab has a heavy lid (`┍━┑`); while its pane has the keys the whole tab is
heavy (`┏━┓` over `┃ name ┃`). The dock and the git view's tabs work the same
way. Shorter terminals keep the one-row strip, so the code keeps the row.

Two open files with the same name say which is which — `plugin.php (git)` and
`plugin.php (symfony-lsp)` — and the qualifier grows a directory at a time
until it distinguishes them, so two `GitSidebar.php` under `<plugin>/src/View/`
become `GitSidebar.php (git/src/View)` and `(other/src/View)`. It goes after
the name rather than in front so the part that says what the file *is* survives
a narrow strip. Names that are shared deliberately — every help page is one
`Documentation` tab — are left alone.

The tab bar sits on top by default; Settings → Interface moves it to the bottom,
left or right. A side strip is a fixed 24 columns, so on a terminal too narrow
to seat it and still leave a file worth reading it returns to the top on its own
and goes back to the side when the room does. Changing the setting while it is
displaced replaces what comes back, so you never get an abandoned preference
restored under you.

The recent-files popup outlives the tab: below the open buffers it lists the
files this project has had open and closed, newest first, which is usually
where the one you want is. The same list seeds Search Everywhere's empty
state, so the two cannot disagree about what "recent" means. It is kept in
`.porta/recent-files.json`, capped at 32. A file that has since been deleted is
dropped rather than offered, and one that has been renamed follows the rename
the way bookmarks and breakpoints do. Help pages and images are never
recorded — reopening a help page as editable source is not a switch.

A file larger than 2 MB opens **read-only** instead of being refused: a
scrollable preview of its first 2 MB, cut at a line break, with a banner naming
the file's real size. `↑`/`↓`, `PageUp`/`PageDown` and `Home`/`End` move through
it and the wheel scrolls it; nothing about that tab can be edited or saved,
because it is a viewer pane rather than a buffer. Non-UTF-8 files are still
refused. Clean buffers that change on disk (e.g. rewritten by a formatter)
reload automatically within a second.

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
  next launch. Pinning does not reorder the strip. A pinned tab shows no `×`;
  `Ctrl+W` or the tab menu still close it.
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
  from a brace scan, which starts it at the `{`. `Ctrl+Shift+-` collapses every
  region in the file and `Ctrl+Shift+=` opens them all again — the fastest way
  to see a long class as a list of its members. (`=` rather than `+`: a
  terminal reports the unshifted key.)
- **Line operations**: duplicate (`Ctrl+D`), delete (`Ctrl+Y`), move line
  (`Alt+Shift+↑/↓`).
- **Complete the current statement** (`Ctrl+Shift+Enter`): closes the brackets
  the line opened, then either ends the statement or opens a block, and leaves
  the caret on a fresh line. `$x = foo(1, bar(2` becomes `$x = foo(1, bar(2));`
  and `if ($x > 1` becomes `if ($x > 1) {` with an indented line and a closing
  brace. What counts as a terminator, a block and a block keyword is per
  language — Go writes no semicolons, Python's block is a `:` and its indent,
  and JSON, YAML and Markdown have no statement to complete, so the key does
  nothing there. A line ending in an operator is a statement that continues and
  is left alone. One undo step. The bracket count does not read strings, so a
  parenthesis inside one is counted.
- **Start a new line** below (`Shift+Enter`) or above (`Ctrl+Alt+Enter`)
  without splitting this one — whatever column the caret is in. Plain `Enter`
  still splits at the caret; these two do not look at it. The new line copies
  its neighbour's indent, and going DOWN from a line that opens a block takes
  one more level.
- **Comments**, in every language Porta can colour. `Ctrl+/` comments the line
  or the selected lines one at a time; `Ctrl+Shift+/` puts one block comment
  around exactly what is selected — `foo(bar, baz)` with `bar` selected becomes
  `foo(/* bar */, baz)` — or around the current line's content when nothing is.
  Either key comes off again the same way it went on.

  Each prefers a different form and falls back to the other, which is what
  makes both work everywhere: `Ctrl+/` in a Twig, CSS, HTML or Markdown file
  wraps each line in that language's block delimiters, because none of them has
  a line comment at all, and `Ctrl+Shift+/` in Python or YAML line-comments,
  because neither has a block. `Ctrl+Shift+/` on a blank line opens an empty
  comment with the caret inside it.

  Un-commenting a block needs the target to BE the comment: with no selection
  the target is the whole line, so a one-line block comment comes off from
  anywhere on it, while one spanning several lines has to be selected first.
- **Clipboard** (`Ctrl+C/X/V`) also copies to the system clipboard via
  OSC 52. `Ctrl+Shift+V` opens the **clipboard history** — the last 20 things
  you copied, newest first, with the picker's type-to-filter over all of them.
  Newlines show as `⏎`, so a three-line copy is distinguishable from a long
  one at a glance instead of both reading as their first line. Re-copying
  something already in the list moves it back to the top rather than adding a
  duplicate, the history is one list across every buffer in the session, and
  picking an entry also makes it what plain `Ctrl+V` pastes next.

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

The caret line's number is bold. With colour, a shaded band marks the whole
caret line. In the monochrome theme (and under `--no-ansi`) there is no band;
instead a `›` stands in the marker column beside the number, since underlining
the whole line would mark every character you type. When a breakpoint or a
bookmark takes the column a diagnostic's glyph would use, the line number stays
underlined, so the finding still shows without colour. The last pinned
sticky-scroll row is underlined there too, to separate the pinned headers from
the code below them.

Under the pointer, a gutter mark underlines and the status bar says what it
is and what a click does: "Breakpoint · click to remove", "Bookmark", a
diagnostic's message, or "Modified · click to see the change" on a git change
mark.

## The completion popup

Each row is the name, the server's **type** and the item's kind: a method's
signature or a property's type, in the muted pen so the name still reads first.

Where the type comes from depends on the server, and both are handled: some
send it with the completion, some only answer when asked. Porta asks about the
rows on screen — once per item, and never at all when the type is already there
or the server does not offer to resolve. A row that scrolls in under a newly
typed prefix may show no type until the selection reaches it.

The name is served first and the type takes what is left. A completion whose
label you cannot read is not a completion — the type is what you check once the
name is already the one you meant — so at a narrow width the type is truncated,
and when the name alone fills the row the type is not drawn at all.

The popup holds the keys, so it wears the heavy frame. Its selected row starts
with `▌`, and an item the server marks **deprecated** is struck through. A `▲`
or `▼` in the border says the list goes on past what is shown, and
`PgUp`/`PgDn` move a page. When the list fits on neither side of the caret, it
takes the roomier side, cut to fit. It never covers the line being typed.

The docblock beside the list scrolls with `Ctrl+↓`/`Ctrl+↑`, with its own `▲`/`▼`.
A click on the popup or the docblock stays on the popup; it does not land in the
code underneath.

`Ctrl+Space` always gets an answer where it was pressed. While the servers are
asked it shows `◌ Loading…`. After that you get the list, or "No suggestions", or
"No language server" when nothing serves the file. Any key takes the note away.
The automatic trigger after `->` and `::` stays silent, because a note on
ordinary typing would flicker.

## Inline debug values

Stopped at a breakpoint, each line that assigns a variable shows what it holds,
after the code:

```php
$count = 41;                    $count = 41
$name = 'thing';                $name = 'thing'
```

It is a join of two protocols that do not know about each other. The language
server says WHERE the variables are and what they are called
(`textDocument/inlineValue`); the debug adapter says what they hold. A variable
the server names but the adapter has not reported is simply not shown — it has
not been assigned yet at this point in the frame.

Both bundled servers can supply the WHERE, by different routes. phpactor answers
`textDocument/inlineValue` directly. phpantom_lsp does not offer that request at
all — so its **semantic tokens** stand in: the same pass that colours the file
types each local as a `variable` or a `parameter`, and the range it gives is the
name with its `$`. Nothing extra is fetched and nothing is guessed; a server
that offers neither simply shows no values.

The values share the trailing slot with the inline diagnostic and with a
plugin's line annotation (git blame). While the debugger is stopped the value
wins, because the other two keep their other surfaces — a diagnostic still has
its squiggle, its gutter mark, its stripe cell and the Problems panel. They
disappear the moment the debuggee continues or the session ends: they were
facts about a frame that is running again.

## The Problems panel

`Alt+6` collects every diagnostic in the open buffers, sorted by file then line.
A row is the severity glyph, the message, and — when the source named one — the
**rule that fired**, at the end:

```
✗ Name "Other" is imported but not used  worse.unused_import
```

At the end rather than in front, because the rule is what you search for to
suppress it or to tell two identically-worded findings apart; the sentence is
what you read the row for. It comes from the language server's `code`, or from
an analyser plugin that passed one.

Being at the end does not make it the first thing dropped: a panel too narrow
for both elides the **sentence** and keeps the rule whole, which is the point —
long messages are exactly the ones where the rule is all that tells two
findings apart. Below a two-dozen-column floor for the message the row gives up
and cuts normally, since a rule name with no sentence beside it names nothing
you could act on.

## Unused and deprecated code

A diagnostic's **severity** says how bad something is; its **tags** say what
kind of thing it is, and the two are independent. An unused import arrives from
phpactor at severity 2 — the same severity as a warning about code that runs —
so without the tag it looks exactly like a real problem, and the only way to
tell them apart is to read the message.

So tagged ranges are drawn differently from the text around them:

| Tag | Drawn |
| --- | --- |
| unnecessary | faint — code that does nothing, the terminal's version of PhpStorm's grey |
| deprecated | struck through |

Both keep the severity-coloured underline they always had, so the tag adds to
what the diagnostic says rather than replacing it. A tag Porta does not
recognise draws normally.

Nothing needs turning on: the client advertises `tagSupport` in the handshake,
and servers that send tags have theirs drawn. phpactor sends them for unused
imports; a server that sends none is unaffected.

## Last edit location

`Ctrl+Shift+Backspace` takes you back to what you were writing. Press it again
to walk further back through the last ten places you edited; making an edit
starts the walk over, because the next press then means "the place before here".

It is a different list from navigate-back's (`Ctrl+Alt+Left`), which returns you
from a **jump** and so knows only the places a navigation verb pushed. Scrolling
away from your work, or opening another file from the tree, pushes nothing
there — and that is exactly when you want this one.

Consecutive edits in one place are one place, so typing a word does not fill the
list, and a renamed file is followed rather than lost. The caret lands at the
start of the line rather than the column the edit was at: a stale column on a
line since re-typed is a worse guess than its start.

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
does: PHP from the offline scanner, everything else from the language server's
document symbols.

The reference is spelled the way the language does — `App\Http\Svc::run` in
PHP, `Service.handle` elsewhere. A scope carries a label and a kind and no
language semantics, so PHP is named and the rest share `.`, which is what
JavaScript, TypeScript, Python, Java and Go all write. A language that spells it
`::` gets a reference that reads a little wrong; that is a smaller cost than a
table of guesses about languages nothing here tests.

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

## Show whitespace

Off by default (Settings → Interface, or `showWhitespace` in `.porta.json`).
Leading and trailing whitespace is drawn: a space as `·`, a tab as `→` followed
by blank to its stop.

**Leading and trailing only** — not the spaces between words. PhpStorm offers
the three separately, and turning the inner ones on writes a dot between every
word; that is legible on a wide screen with a pale grey and unreadable in a
terminal, which has one intensity and eighty columns. It is also where the
questions are: is this file indented with tabs or spaces, and is there junk
after the semicolon.

A tab is drawn blank after its arrow rather than dotted, which is what a
graphical editor does and what keeps it legible here: the indent guides take the
first cell of every indent level, arrow included, so it is the *padding* that
tells a tab from four spaces — blank against four dots.

The substitution is one display column for one, so nothing downstream sees a
different line than it would have.

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
column, below breakpoints but ABOVE diagnostics in priority — a diagnostic also
colours the line number, paints the error stripe and writes itself at the end of
the line, while a mark has that one cell — and they survive closing the
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
| `▶` | the debugger's stopped line |
| `█` `▓` `▒` `░` | a diagnostic — the block gets heavier with the severity |
| `●` | a breakpoint |
| `━` | the search hit `F3` is on |
| `▎` `┆` `▁` | a git change — added / modified / deleted, the same marks the gutter draws |
| `◈` | a bookmark |
| `┈` | any other search hit from the find bar |

A faint band behind the marks is the **viewport thumb**: the part of the file
you are looking at, shown only when the file is taller than the pane. On rows
with no mark the thumb draws its own `▐`, so it is visible without the band.
Under the pointer a mark underlines, and the status bar says what it is and
the line a click goes to: "Bookmark · line 41".
Clicking a cell jumps there.

Every mark is a distinct shape rather than a colour, so the stripe still reads
under `--no-ansi` and in the monochrome theme. Change marks follow the git
plugin's own *gutter marks* setting: turn that off and they leave the stripe
too.

## The file tree

The row under the cursor wears a bar in its first column: `▌` while the tree
has the keys, `▎` once focus moves elsewhere, so you can see where they will
land when you come back. A second file picked with `Shift+↑/↓` or a modifier
click shows a thin `│`. The same bar marks the selection in every list: the
settings sections, the keybindings, the results and debug panels, and git's
changes and history.

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

Right-click a row for the rest: **New File…**, **New Folder…**, **Copy Path**,
**Copy Relative Path**, **Rename…** and **Delete…**. A rename takes the open
buffer with it, and everything else keyed by that path — bookmarks,
breakpoints, recent files, saved search scopes, edit and jump history, local
history — follows too, whether the file moved from here or from a language
server's refactoring.

### Dragging files

Drag a row onto a folder to **move** it there. Dropping onto a *file* means its
folder, so you can aim at any row rather than hunting for the directory line.
The folder under the pointer is highlighted while you drag — in its own colour,
not the ordinary hover tint — and carries a count of what would land on it, so a
multi-file drag says what it is moving even when the rows it picked up have
scrolled away. The status bar names the copy modifier as you start. `Esc`
abandons the drag, and releasing without having moved is just a click.

- **Several files at once.** Dragging a row that is part of a selection carries
  the whole selection; dragging one outside it carries only that row. A folder
  always travels alone.
- **Drop on the editor** instead of a folder and the file simply opens — nothing
  moves.
- **Hold `Alt` as you release** to copy instead of moving. Not `Ctrl`, which
  this tree already uses to add a row to the selection (and `Shift` to extend
  to one), so that chord would mean two things at once.
- **A name already taken** asks rather than refuses: *Overwrite*, *Rename…* or
  *Cancel* for one file, *Overwrite all* or *Cancel* for several. Moves are not
  undoable — the same rule a refactoring that renames files follows — so the
  question is the safety net.
- **A folder cannot be dropped inside itself** or its own descendant, and a
  drop back into the folder a file is already in does nothing.
- **The tree scrolls under the drag.** Hold the pointer on the top or bottom row
  and it keeps moving, so the destination does not have to be on screen when you
  start. It stops at the ends of the tree.
- **A collapsed folder opens if you rest on it** for about half a second, which
  is how you reach a row inside one without letting go. Moving on before that
  leaves it shut.
- **Released over nothing** — the tab strip, the dock, the status bar — the drag
  says so rather than failing silently.

### Moving files without the mouse

The same move, on the chords every file manager uses: **`Ctrl+X`** / **`Ctrl+C`**
on the selected rows, then **`Ctrl+V`** on the folder you want them in (a *file*
row means its folder, exactly as a drop does). They are on the right-click menu
too, and *Paste* only appears once there is something to paste.

These are the editor's cut and paste as well, and which one you get depends on
**what has focus** rather than on the chord: in the editor they move text, in the
tree they move files. `Alt+1` focuses the tree.

A cut is spent by its paste — those files are somewhere else now — while a copy
stays on the clipboard, so you can paste the same set into several places. Name
collisions, the refusal to paste a folder into itself, and everything that
follows a file (buffers, bookmarks, breakpoints, history, the language server)
behave exactly as they do for a drop, because it *is* one.

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
file's snapshots; `Enter` restores one as a single undoable edit. Each row
names its file, because the list is not always about the tab you are looking
at — a right-click in the tree opens the history of THAT file, and a file that
has been deleted has no tab at all.

**Compare with Local History** (the command palette, or a right-click on the
editor or on a file) shows what a snapshot would change before you restore it:
pick one, and the diff opens in its own panel — oldest on the left, so a `-`
line is what restoring would bring back and a `+` line is what it would take
away. The newest snapshot is always the file as it stands, because saving is
what takes one, and picking it says so rather than opening an empty panel.

**Recent Changes** (the command palette) answers the other question — not
"what did this file look like before", but "what have I been changing", which
is what you want when you do not yet know which file to ask about. It lists
every file with history, the file changed last on top, with when it changed and
how many versions it has; `Enter` opens one, and `Ctrl+Alt+H` on it then shows
that file's snapshots.

**A file you deleted is still in that list, and `Enter` on it goes straight to
its snapshots** — there is no file to open, and its history is now the only copy
left, which is the case local history exists for. Restoring a version then
recreates the file and opens it. That is the one restore that writes to disk:
everywhere else `Enter` replaces the buffer and saving stays yours, because a
file that exists is yours to keep; here there is nothing to keep and nothing to
overwrite.

A history directory is named after a HASH of the path, so each one records its
own path in a small sidecar file. Directories written before that existed are
recovered the first time you open this view: a hash cannot be inverted, but it
can be *recognised*, and the paths worth trying are the files in your project —
so the view hashes them and matches. That walk happens **once** (it skips
`.git`, `vendor`, `node_modules`, `.idea` and `.phpunit.cache`, and stops at
20,000 files), and whatever it cannot name is marked so it is not walked for
again. A file it skipped or could not find gets its name on the next save.

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

`/` finds a node by name. Two hundred namespaces laid out by `dot` are not
something you can read your way through, so the key opens the ordinary
filterable list over every node — type to narrow it — and picking one selects
that node and pans to it, exactly as `n`/`p` would. Each row carries the node's
**in/out degree**, which is the one thing a list can say and the picture cannot:
the hub you are looking for is the row with fifteen edges, and it looks like
every other box on screen. `Graph: Find Node` does the same from the palette.

`v` toggles the two renderings. **Bitmap** is what the graph actually looks
like — dot's own splines, arrowheads and fonts — and **cells** draws the node
labels as real terminal text, which stays readable at a pane size where the
bitmap's type is a few pixels tall. Cells is also what paints when dot cannot
produce a bitmap at all (no libgd) or has not answered yet, and the standing
preference is the graph-viewer plugin's `renderMode` setting. A PlantUML
picture has no layout to draw from, so it ignores the toggle.

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
