# Search & navigation

## Go to…

| Key | Target |
| --- | --- |
| `Ctrl+Shift+N` / `Ctrl+P` | File (fuzzy, camel-hump aware) |
| `Ctrl+Alt+Shift+N` / `Ctrl+Alt+O` | Symbol in project (`workspace/symbol`, live-filtered) |
| `Ctrl+F12` | Symbol in the current file (file structure popup) |
| `Ctrl+G` | Line |
| `Ctrl+E` | Recent files (press again to cycle) |
| `Ctrl+Shift+T` | The test for the current class — or back: from `FooTest.php` to `Foo.php`. Searches the project (skipping `vendor/`) and flashes when nothing matches |
| `Ctrl+Shift+E` | Search Everywhere |
| `Ctrl+Shift+A` | Find Action (command palette — every command by name) |

## Code navigation (LSP)

Go to declaration (`Ctrl+B`/`F12`), implementation (`Ctrl+Alt+B`), type
definition (`Ctrl+Shift+B`) — a chooser opens when the server returns
several targets — with a back-navigation stack (`Ctrl+Alt+Left`). Peek
definition (`Ctrl+Shift+I`) shows the target inline without leaving the
file. Find usages (`Alt+F7`) fills the results panel.

## Find in file

`Ctrl+F` finds incrementally with live match highlighting; `Ctrl+R` adds a
replace field. `F3`/`Shift+F3` cycle matches, `Tab` switches fields,
`Alt+R` replaces the current match, `Alt+A` replaces all. `Alt+X` toggles
regex mode and `Alt+W` whole-word (note: in this bar `Alt+R` means replace —
the Find-in-Files modal uses `Alt+R` for its regex toggle instead). In regex
mode the replacement is a preg replacement pattern: `$1` inserts the first
captured group, `\$` a literal dollar. An invalid half-typed pattern shows
`[bad regex]` in place of the match counter and matches nothing.

## Find in Files (`Ctrl+Shift+F`)

A centered modal with three fields (`Tab` cycles): **Find**, **Replace**
and a file **Mask** (glob). Results preview live as you type, grouped by
file; `Shift+Enter` makes the query multiline, `Alt+↑/↓` recall recent
queries. Full mouse support: click fields, toggles and result rows; wheel
scrolls the preview; drag the split to resize the results pane.

Toggles: regex (`Alt+R`), case-sensitive (`Alt+C`), whole-word (`Alt+W`),
preserve-case on replace (`Alt+V`), token scope (`Alt+T`), and search scope
(`Alt+S`, Whole Project or saved scopes — e.g. "Find in Files…" from a tree
folder's context menu scopes to that folder).

- `Enter` opens the selected match.
- `Alt+Enter` promotes the whole result set to its own tab on the
  bottom dock — every search keeps its tab (same query re-uses it,
  a new query opens another) until you close it with the tab's `×`;
  `F5` re-runs the shown tab's query.
- **Replace**: with Replace filled, each match previews as `old → new`
  with an exclude checkbox (`Delete` toggles). `Alt+A` applies to every
  kept match — open buffers as one undoable edit, other files on disk.

The search runs as async `grep` streamed through the event loop; the UI
never blocks.

## Structure panel (`Alt+7`)

The active file's symbols as a dock tab, indented by nesting with each one's
kind beside it; `Enter` or a click jumps to the symbol. Unlike the `Ctrl+F12`
popup it stays open — switching buffers or saving re-reads the file into it, and
`F5` re-runs it on demand. While the tab is closed it costs nothing: no
`documentSymbol` request is sent at all. Requires a language server.

PhpStorm's split, which Porta used to collapse onto the popup: `Alt+7` is the
persistent tool window, `Ctrl+F12` the transient popup.

## Problems panel (`Alt+6`)

Aggregated diagnostics across all open buffers — `✗` errors, `▲` warnings,
sorted by file and line; `Enter` jumps to the diagnostic. The status bar
carries live error/warning counts. Diagnostics come from the language
server and from plugins that publish them (phpstan, mago, psalm).

## Call hierarchy

`Ctrl+Shift+H` (or Navigate → Call Hierarchy) lists the callers of the
function or method at the caret in the results panel — pick one to jump to
the call site. `Ctrl+Alt+Shift+H` lists the other direction: what that symbol
calls. Requires a language server with call-hierarchy support.
