# Keybindings

The keymap follows PhpStorm wherever the terminal allows. Every action is
rebindable — see [Custom keymap](#custom-keymap) below, or use
**Settings → Keybindings** (`Ctrl+,`) to capture a new key interactively.

Heavily modified chords (e.g. `Alt+Shift+↑`) work best in terminals with the
Kitty keyboard protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

**On macOS the function keys are media keys** unless *System Settings →
Keyboard → Use F1, F2, etc. as standard function keys* is on — otherwise `F11`
dims the display rather than dropping a bookmark, and the app never sees it.
Either turn that setting on, hold `Fn`, or rebind: this map uses `F2`, `F5`–`F12`
for problems, run/debug, bookmarks, the menu, panes and the context menu, so a
Mac with the default keyboard setting will feel like several of them do
nothing.

## Application

| Key | Action |
| --- | --- |
| `Ctrl+Q` | Quit (twice within 3 s to discard unsaved changes) |
| `Ctrl+T` | Switch focus between file tree and editor |
| `F6` / `Ctrl+F6` | Cycle focus forward / backward across panes (in the git and settings views, cycles their panes) |
| `Ctrl+F10` | Open the context menu for the focused pane (editor: at the caret; tree: at the selected node; image/help tab: the tab menu) |
| `Shift+F11` / `Shift+F12` | Shrink / grow the focused pane (file tree, git and settings sidebars, and the bottom panels) |
| `Ctrl+Shift+'` | Maximize the open bottom panel; press again to put it back at the height it had |
| `Ctrl+Shift+L` | Cycle the line-number mode (absolute → relative → off) |
| `Alt+PageDown` / `Alt+PageUp` | Next / previous panel on the bottom dock |
| `Alt+Z` | Toggle soft wrap (off by default; horizontal scroll is disabled while it is on) |
| `Alt+V` | Toggle code vision — the server's "3 usages" lines above declarations (off by default) |
| `F10` | Arm the application menu: File is lit, `←`/`→` move along the titles, `↓`/`Enter`/`Space` or a title's underlined letter opens one; `F10` or `Esc` again lets go |
| a menu's underlined letter | Run that item (in a select's list, a letter jumps to the next option starting with it) |
| `F1` | Open the documentation browser (these guides, fully rendered; see the Help viewer table below) |
| `Ctrl+Shift+A` | Find Action (command palette) |
| `Ctrl+Shift+E` | Search Everywhere |
| `Ctrl+,` | Settings (inside: `Ctrl+F` finds a setting — or filters the Keybindings list in place, `R` resets the selected row, `2` jumps to Keybindings, `[`/`]` change a value, `Tab` switches pane) |
| `Ctrl+Alt+D` | Git view (changes, staging, history) |
| `Ctrl+\`` / `Alt+F12` | Toggle the integrated terminal |
| `Ctrl+Shift+\`` / `Alt+F11` | Open a new terminal session |
| `Ctrl+PageDown` / `Ctrl+PageUp` | Next / previous terminal session (`Ctrl+Shift+W` closes one) |
| `Alt+1` | Focus the project tree |
| `Ctrl+X` / `Ctrl+C` / `Ctrl+V` | **In the file tree**: cut / copy / paste the selected files. The same chords move text in the editor — which you get depends on what has focus |
| `Alt+6` | Problems panel |
| `Alt+9` | Notification log — every flash the status bar showed and you missed |
| `Alt+D` | Dismiss the newest notification (the top card) |
| `Ctrl+Alt+H` | Local history |
| `Ctrl+Alt+Z` | Undo the last multi-file refactoring, across every file it touched (`Ctrl+Z` is per file); refuses and names the file if anything changed since |

## Files and buffers

| Key | Action |
| --- | --- |
| `Ctrl+S` | Save |
| `Ctrl+Alt+S` | Save all |
| `Ctrl+Shift+C` | Copy the active file's path, relative to the project |
| `Ctrl+Alt+Shift+C` | Copy a reference to the caret — the enclosing symbol (`App\Svc::run`), or `path:line` when there is none |
| `Ctrl+Alt+Shift+S` | New scratch file — pick a language, get an empty buffer in `<home>/.porta/scratches/` |
| `Ctrl+Shift+O` | Open project — pick one you have opened before, or type a path. The current session ends the way a quit does (buffers saved, session written) and the IDE reopens on the new root |
| `Ctrl+Shift+N` / `Ctrl+P` | Go to file (fuzzy finder) |
| `Ctrl+E` | Recent files popup (`Ctrl+E` again cycles, `Enter` opens, and typing filters) |
| `Ctrl+←` / `Ctrl+→` | Previous / next editor tab |
| `Ctrl+Shift+←` / `Ctrl+Shift+→` | Move the active tab left / right |
| `Ctrl+F4` / `Alt+W` | Close the active buffer (unsaved changes prompt) |
| `Ctrl+Alt+X` | Reopen the active tab as text (for a file a viewer plugin claimed) |

## Editing

| Key | Action |
| --- | --- |
| `Ctrl+Z` / `Ctrl+Shift+Z` | Undo / redo (typing runs coalesce) |
| `Shift+Enter` | Start a new line below this one, whatever column the caret is in (plain `Enter` still splits the line) |
| `Ctrl+Alt+Enter` | Start a new line above this one |
| `Ctrl+Shift+Enter` | Complete the current statement — close its brackets, add the terminator (or open a block for `if`/`for`/…), and move to the next line |
| `Ctrl+C` / `Ctrl+X` / `Ctrl+V` | Copy / cut / paste (also the system clipboard via OSC 52) |
| `Ctrl+Shift+V` | Paste from clipboard history — the last 20 things you copied, newest first, filterable; newlines show as `⏎` |
| `Ctrl+D` | Duplicate line or selection |
| `Ctrl+Y` / `Ctrl+Shift+K` | Delete line or selected lines |
| `Ctrl+K` | Delete to end of line |
| `Alt+Backspace` | Delete word backwards |
| `Ctrl+/` / `Ctrl+-` | Toggle line comment (in a language whose only comment form is a block — Twig, CSS, HTML, Markdown — each line is wrapped in that block instead) |
| `Ctrl+Shift+/` | Toggle block comment around the selection, or the current line's content; falls back to the line comment where the language has no block form |
| `Alt+Shift+↑/↓` | Move line or selection up / down |
| `Ctrl+Shift+↑/↓` | Move statement up / down (hops blank-line gaps) |
| `Ctrl+Shift+J` | Join the selected lines (or this line and the next) into one |
| `Ctrl+Shift+S` | Sort the selected lines |
| `Ctrl+Shift+U` | Toggle the case of the selected lines |
| `Ctrl+Shift+M` | Jump to the bracket matching the one at the caret |
| `Ctrl+W` / `Ctrl+Shift+W` | Extend / shrink selection (word → brackets → line → file) |
| `Ctrl+Alt+T` | Surround selection (bracket pair, quotes, `if`/`foreach`/`try`) |
| `Ctrl+.` | Collapse / expand the code fold at the caret |
| `Ctrl+Shift+-` | Fold all — collapse every region in the file |
| `Ctrl+Shift+=` | Unfold all — PhpStorm's Ctrl+Shift+Plus, as the terminal reports it (unshifted) |
| `Ctrl+A` | Select all |
| `Home` | Smart home (first non-blank, then column 0) |
| `Tab` / `Shift+Tab` | Indent; next / previous tab stop while a snippet is active |

## Multiple carets

| Key | Action |
| --- | --- |
| `Ctrl+Alt+↑` / `Ctrl+Alt+↓` | Add a caret above / below |
| `Alt+J` | Add a caret at the next occurrence of the word |
| `Ctrl+Alt+Shift+J` | Add carets at all occurrences |
| `Esc` | Collapse back to a single caret |
| `Alt+Click` | Add / remove a caret (mouse) |
| `Alt+C` | Column selection mode — then `Shift`+arrows build the rectangle (`COLUMN` shows in the status bar) |
| `Alt+Shift+drag` | Column (rectangular) selection (mouse) |

## Code intelligence (LSP)

| Key | Action |
| --- | --- |
| `Ctrl+Space` | Trigger completion (auto after `->` and `::`) |
| `PgUp` / `PgDn` | A page of the completion list |
| `Ctrl+↓` / `Ctrl+↑` | Scroll the completion's docblock |
| `Alt+↓` / `Alt+↑` | Next / previous overload, while the signature popup shows more than one |
| `Ctrl+B` / `F12` | Go to declaration |
| `Ctrl+Alt+B` | Go to implementation (chooser when several) |
| `Ctrl+Shift+B` | Go to type definition |
| `Ctrl+Alt+Left` | Navigate back after a definition jump |
| `Ctrl+Shift+I` | Peek definition (inline; `Enter` jumps) |
| `Alt+F7` | Find usages |
| `Ctrl+Shift+H` / `Ctrl+Alt+Shift+H` | Callers of / calls from the symbol at the caret |
| `Shift+F6` | Rename symbol (cross-file `WorkspaceEdit`) |
| `Alt+Enter` | Quick fixes / code actions (headed by the line's problems when diagnostic messages are set to `menu`) |
| `Ctrl+Alt+Shift+T` | Refactor This |
| `Ctrl+Alt+N` | Inline |
| `Ctrl+Alt+O` | Optimize imports (`source.organizeImports`; applied straight away when the server offers one) |
| `Ctrl+Shift+P` | Parameter info — re-summon the signature popup (PhpStorm's `Ctrl+P`, which Go to File owns here) |
| `Shift+F1` | Quick documentation, in a popup under the caret (`↑`/`↓`, `PgUp`/`PgDn` or the wheel scroll it; `Esc` or any other key closes it) |
| `Ctrl+F12` | File structure popup (type to filter — the symbol's KIND counts, so `method` narrows a class to its methods) |
| `Alt+7` | Structure panel (persistent, on the dock) |
| `F2` / `Shift+F2` | Next / previous problem in this file (wraps, and says so) |
| `Alt+↓` / `Alt+↑` | Next / previous declaration — class or method (wraps, and says so) |
| `F11` | Toggle a bookmark on the caret's line |
| `Alt+2` | Bookmarks — every mark across the project |
| `Alt+8` | TODOs — the bundled scan of TODO/FIXME comments |
| `Alt+5` | Recent Locations — the places you jumped away from |
| `Ctrl+Shift+Backspace` | Last edit location — back to what you were writing; press again to walk further back |
| `Alt+F1` | Select the opened file in the tree |
| `Ctrl+Alt+Shift+N` | Go to symbol in project |
| `Ctrl+Alt+L` | Reformat file (or the selection when one exists) |

## Search

| Key | Action |
| --- | --- |
| `Ctrl+F` / `Ctrl+R` | Find / find-and-replace in file (`F3`/`Shift+F3` cycle, `Tab` switches field, `Alt+X` regex, `Alt+W` whole word, `Alt+C` match case, `Alt+R` replace, `Alt+A` replace all, `Alt+Enter` select all occurrences, `Alt+↑`/`Alt+↓` recall an earlier query) |
| `Ctrl+Shift+F` | Find in Files — see the table below |
| `Ctrl+G` | Go to line |
| `Ctrl+Shift+T` | Go to test / go to tested class |
| `Ctrl+U` | Go to the super method — the parent class, interface or trait that declares the one under the caret (resolved from the project's own files; no language server answers this) |

Both find surfaces share one query history — what you looked for in this
project, whichever surface you looked from — kept in `.porta/search-history.json`
and recorded when you press `Enter`, not as you type.

### Inside Find in Files

The option toggles above the results are clickable, and these are their keys.
Note that regex is `Alt+X` in the in-file find bar and `Alt+R` here: `Alt+R`
replaces in the bar, and neither surface can have both.

| Key | Action |
| --- | --- |
| `Alt+R` / `Alt+C` / `Alt+W` | Regex (`.*`) / match case (`Aa`) / whole word (`\b`) |
| `Alt+V` | Preserve case when replacing (`Pc`) — replacing `foo` in `Foo` writes `Bar`, not `bar` |
| `Alt+T` | Cycle where matches count: anywhere (`·"`), only inside comments and strings (`i"`), or everywhere except them (`x"`) — the classification is per file and language-aware |
| `Alt+S` | Cycle the search scope (whole project, or one you saved) |
| `Alt+↑` / `Alt+↓` | Recall an earlier query into the field |
| `Shift+Enter` | A literal newline in the query — how you search for text spanning two lines |
| `Enter` / `Alt+Enter` | Open the selected result / send every result to the Find panel |
| `Tab` | Move between the find, replace and file-mask fields |
| `Delete` | Exclude the selected match from `Alt+A`'s bulk replace |
| `Alt+A` | Replace across the project (every match still included) |

## Run, debug, git

| Key | Action |
| --- | --- |
| `Ctrl+Shift+R` | Run… (current file, composer script, Makefile target) |
| `Shift+F10` | Repeat the last run |
| `Shift+F9` | Debug the current file |
| `Ctrl+F8` | Toggle a breakpoint (also: click the gutter) |
| `F8` / `F7` / `Shift+F8` | Step over / into / out *(while paused)* |
| `F9` | Resume *(while paused)* |
| `Ctrl+F2` | Stop the debugger *(while a session runs)* |
| `Ctrl+Shift+F5` | Restart the debugger *(while a session runs)* |
| `Ctrl+Shift+F8` | Edit the breakpoint's condition, hit count or log message |
| `Ctrl+Shift+F9` | Add a watch expression |
| `Alt+F9` | Run to the caret's line *(while paused)* |
| `F7` / `Shift+F7` | Next / previous merge conflict *(when not debugging)* |
| `Alt+M` | Resolve the conflict under the caret (ours / theirs / both) |
| `Ctrl+Alt+Shift+↓` / `Ctrl+Alt+Shift+↑` | Next / previous change against `HEAD` (click a gutter change mark to see it and revert it) |

The debugger's `F7`/`F8`/`F9`/`Shift+F8`/`Ctrl+F2` keys are gated on a
running session — without one they fall through to their editor meanings.

## Results panels

The shared list at the bottom — Find in Files, Problems, Usages, Structure,
TODOs, Bookmarks and a plugin's own. Whichever tool filled it, these are its
keys.

| Key | Action |
| --- | --- |
| `↑` / `↓`, `PageUp` / `PageDown` | Move the selection (in the Debug panel's call stack, watches and variables too) |
| `Enter` | Open the row's file at its line |
| `Alt+G` | Cycle how rows are grouped: by file, by directory, or nested |
| `Alt+E` | Collapse every group, or expand them all when they are already collapsed |
| `Alt+M` | Show or hide the source lines around the selected match |
| `F5` | Run the search again and refresh the rows |
| `Alt+X` | Write a Find in Files result set to a file and open it (inert on the other tools, which have no export to offer) |
| type | Filter the rows, where the tool offers a filter (Find in Files, Problems, Usages, Structure) |
| `Esc` | Clear the filter, or close the panel when there is none |

## File tree

| Key | Action |
| --- | --- |
| `↑` / `↓`, `PageUp` / `PageDown` | Move the selection |
| `Shift+↑` / `Shift+↓` | Extend the selection to more than one file |
| `←` / `→` | Collapse / expand a directory |
| `Enter` | Open file / toggle directory |
| `r` | Reload the tree |

Several files can be selected at once — `Shift+↑`/`Shift+↓`, or `Ctrl+Click`
to add one and `Shift+Click` to take a range. A modified click only selects:
it does not open the file, because the point of a pair is to act on both.
Moving the cursor without a modifier drops back to one file.

With exactly two selected, **Compare Selected Files** — on the Git menu and on
a tree row's right-click — diffs them into a **Compare** panel (`git diff
--no-index`, so neither file has to be tracked, though the git plugin still
wants a repository around it). Right-clicking inside the selection keeps it;
right-clicking elsewhere collapses to the row under the pointer.

## Help viewer

When the F1 documentation browser is active:

| Key | Action |
| --- | --- |
| `Tab` / `Shift+Tab` | Cycle through the page's links (scrolling the focused one into view) |
| `Enter` (or a click) | Follow the focused link — doc pages render in place, `http(s)` links open in the browser |
| `Backspace` / `Ctrl+Alt+Left` | Back to the previous page (scroll position restored) |
| Arrows, `PageUp`/`PageDown`, `Home`/`End`, wheel | Scroll |

The header row above the page is clickable: `‹` / `›` navigate back and
forward through the history, and the breadcrumb mirrors the page's place in
the docs file tree (`Documentation › folder › page`) — the root and any
folder with a `README.md` index navigate on click, and clicking the current
page scrolls back to the top. Following a link clears
the forward trail, like a browser. All navigation happens inside the single
Documentation tab.

Tab-management keys (`Ctrl+←/→`, `Ctrl+F4`, `Ctrl+P`…) keep working on the
help tab.

## Image viewer

When an image tab is active:

| Key | Action |
| --- | --- |
| `=` / `-` | Zoom in / out (also `Ctrl+wheel`, anchored at the pointer) |
| `0` / `f` | Fit to window |
| `1` | Actual size |
| Arrows / `Shift+arrows` | Pan |

## Graph viewer

When a `.dot`, `.gv` or PlantUML tab is active. The zoom keys match the image
viewer's, but zooming in re-renders the graph at a higher resolution rather
than magnifying pixels — see [the editor guide](editor.md#graph-viewer).

| Key | Action |
| --- | --- |
| `=` / `-` | Zoom in / out (also `Ctrl+wheel`, anchored at the pointer) |
| `0` / `f` | Fit to window |
| `1` | Actual size |
| Arrows / `Shift+arrows` | Pan (also the wheel, or dragging) |
| `n` / `p` | Select the next / previous node, framing it and naming it in the status bar |
| `/` | Find a node by name — a filterable list of every node with its in/out degree; picking one selects it and pans to it |
| `v` | Toggle bitmap / cell rendering — the picture `dot` draws, or node labels as real terminal text, which stays readable when the bitmap's type is a few pixels tall (no effect on a PlantUML picture, which has no layout to draw from) |

## Git view

The full-screen view `Ctrl+Alt+D` opens; `Tab` cycles panes and `Esc` closes.
[The git guide](git.md#the-git-view-ctrlaltd) has the whole workflow.

| Key | Action |
| --- | --- |
| `1` / `2` | Changes tab / History tab |
| `Space` | Stage or unstage — the selected file in Changes, the hunk or selection in the diff |
| `d` / `Delete` | Discard the selected file's changes (inline confirm) |
| `v` | Start a line range in the diff, then `Space` stages just those lines |
| `c` | Cycle diff context (git default → ±10 lines → whole file) |
| `s` | Toggle unified / side-by-side (persisted) |
| `w` | Toggle hide-whitespace (persisted) |
| `Ctrl+C` | Copy the selected diff lines |
| `Ctrl+Enter` | Commit the staged changes |
| `Alt+M` | Toggle amend, prefilling the last commit's message |
| `Enter` | In History, switch between the commit list and its file list |
| `f` / `p` / `Shift+P` | Fetch / pull / push |

## Review view

The `review` plugin's full-screen view (**Review → Show Review**); `Tab` cycles
panes and `Esc` closes. [The review guide](review.md#the-review-view) has the
whole workflow.

| Key | Action |
| --- | --- |
| `Enter` | In the list, show the entry; in the file pane, open the file at the caret's line |
| `r` | Reply to the thread (the selected one, or the one the caret is in) |
| `x` | Resolve or reopen that thread |
| `c` | In the list, comment on the review itself; in the file pane, start a thread on the caret's line |
| `o` | Open the thread's line in the editor |
| `s` | Show or hide resolved conversations — the toolbar toggle on the file pane, kept like the git view's |
| `F5` / `Shift+R` | Re-read the review |
| `Ctrl+Enter` | Send what the reply box holds |

## Mouse

**Every list popup filters as you type** — file structure, recent files, quick
fixes, Run…, the scratch-file languages, a plugin's own list. The typed text
shows above the rows, `Backspace` widens it again, and `Esc` clears the filter
before it closes the popup, so a typo never costs you the list. The match is
case-insensitive and covers the row's label and its right-hand hint.

SGR mouse reporting (modes 1000/1003/1006) is enabled: click to place the
cursor, drag to select (double-click a word, triple-click a line,
`Shift+Click` extends), wheel to scroll, hover highlights, click tree
entries / tabs / menu items / panel rows, drag pane separators to resize,
and right-click for a context menu. In the file tree, `Ctrl+Click` adds an
entry to the selection and `Shift+Click` takes the range from the last one.
A results row, or a debugger frame, offers **Copy Path** and **Copy Relative
Path** for the file it names, with whatever plugins add for a file.

**Dragging in the file tree** moves files: onto a folder to move, onto the
editor to open, `Alt` held at the release to copy, `Esc` to abandon. The tree
scrolls while you hold the pointer at its top or bottom edge, and a collapsed
folder opens if you rest on it, so the destination need not be on screen when
you start. `docs/editor.md` has the rest.

The status bar's right-hand cells are clickable too: the error/warning counts
open **Problems**, the `line:col` position opens **Go to Line**, and the
`LSP:<status>` cell opens the **LSP server log**. On the left, the file's
path is a way back to the tree: double-click it to expand the tree down to
the file and select it (focus stays in the editor, as with a double-clicked
tab), or right-click it for **Select Opened File in Tree** (`Alt+F1`),
**Copy Path** and **Copy Relative Path**.

## Custom keymap

Override any action in `.porta.json` (project root):

```json
{
    "keymap": {
        "duplicate_line": ["ctrl+shift+d"],
        "save": ["f10", "ctrl+s"]
    }
}
```

Action ids are listed in `Porta\Config\ActionCatalog::ACTIONS` (they match
the tables above); unknown ids are reported in the status bar at startup.
Plugin commands are rebindable through their namespaced id
(`"php-cs-fixer:fix": ["ctrl+alt+f"]`). Keymap changes apply on the next
launch; **Settings → Keybindings** writes the same block for you after
capturing a keypress.

On that page, `Enter` captures a new key and **`Del` puts the action back to
its shipped default**, removing its line from the file — and dropping the whole
`keymap` block once its last line goes. Rows a config file overrides are drawn
in the modified colour, so the ones you have changed are the ones you can pick
out. This matters beyond tidiness: an override is a *pin*. Whatever the file
says survives every future change to the default, silently, including a binding
you set to the value it already had.
