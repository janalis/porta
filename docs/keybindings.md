# Keybindings

The keymap follows PhpStorm wherever the terminal allows. Every action is
rebindable — see [Custom keymap](#custom-keymap) below, or use
**Settings → Keybindings** (`Ctrl+,`) to capture a new key interactively.

Heavily modified chords (e.g. `Alt+Shift+↑`) work best in terminals with the
Kitty keyboard protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

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
| `F10` | Open the application menu |
| `F1` | Open the documentation browser (these guides, fully rendered; see the Help viewer table below) |
| `Ctrl+Shift+A` | Find Action (command palette) |
| `Ctrl+Shift+E` | Search Everywhere |
| `Ctrl+,` | Settings |
| `Ctrl+Alt+D` | Git view (changes, staging, history) |
| `Ctrl+\`` / `Alt+F12` | Toggle the integrated terminal |
| `Ctrl+Shift+\`` / `Alt+F11` | Open a new terminal session |
| `Alt+1` | Focus the project tree |
| `Alt+6` | Problems panel |
| `Alt+9` | Notification log — every flash the status bar showed and you missed |
| `Ctrl+Alt+H` | Local history |

## Files and buffers

| Key | Action |
| --- | --- |
| `Ctrl+S` | Save |
| `Ctrl+Alt+S` | Save all |
| `Ctrl+Shift+C` | Copy the active file's path, relative to the project |
| `Ctrl+Alt+Shift+C` | Copy a reference to the caret — the enclosing symbol (`App\Svc::run`), or `path:line` when there is none |
| `Ctrl+Alt+Shift+S` | New scratch file — pick a language, get an empty buffer in `<home>/.porta/scratches/` |
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
| `Ctrl+B` / `F12` | Go to declaration |
| `Ctrl+Alt+B` | Go to implementation (chooser when several) |
| `Ctrl+Shift+B` | Go to type definition |
| `Ctrl+Alt+Left` | Navigate back after a definition jump |
| `Ctrl+Shift+I` | Peek definition (inline; `Enter` jumps) |
| `Alt+F7` | Find usages |
| `Ctrl+Shift+H` / `Ctrl+Alt+Shift+H` | Callers of / calls from the symbol at the caret |
| `Shift+F6` | Rename symbol (cross-file `WorkspaceEdit`) |
| `Alt+Enter` | Quick fixes / code actions |
| `Ctrl+Alt+Shift+T` | Refactor This |
| `Ctrl+Alt+N` | Inline |
| `Ctrl+Alt+O` | Optimize imports (`source.organizeImports`; applied straight away when the server offers one) |
| `Ctrl+Shift+P` | Parameter info — re-summon the signature popup (PhpStorm's `Ctrl+P`, which Go to File owns here) |
| `Shift+F1` | Quick documentation |
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
| `Ctrl+F` / `Ctrl+R` | Find / find-and-replace in file (`F3`/`Shift+F3` cycle, `Tab` switches field, `Alt+R` replace, `Alt+A` replace all) |
| `Ctrl+Shift+F` | Find in Files (regex `Alt+R`, case `Alt+C`, whole word `Alt+W`; `Tab` to the replace field, `Alt+A` replaces across the project) |
| `Ctrl+G` | Go to line |
| `Ctrl+Shift+T` | Go to test / go to tested class |

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

The debugger's `F7`/`F8`/`F9`/`Shift+F8`/`Ctrl+F2` keys are gated on a
running session — without one they fall through to their editor meanings.

## File tree

| Key | Action |
| --- | --- |
| `↑` / `↓`, `PageUp` / `PageDown` | Move the selection |
| `←` / `→` | Collapse / expand a directory |
| `Enter` | Open file / toggle directory |
| `r` | Reload the tree |

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
and right-click for a context menu.

The status bar's right-hand cells are clickable too: the error/warning counts
open **Problems**, the `line:col` position opens **Go to Line**, and the
`LSP:<status>` cell opens the **LSP server log**.

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
