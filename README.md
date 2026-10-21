# Porta

A terminal IDE for PHP projects, built on the experimental
[symfony/tui](https://github.com/symfony/tui) component.

**Full documentation lives in [`docs/`](docs/README.md)** — getting
started, editor features, keybindings, git, debugger, configuration,
plugins, and architecture. Press **F1** inside the IDE to browse it fully
rendered: `Tab` cycles the links, `Enter` or a click follows them, and
`Backspace` goes back.

- **Syntax highlighting** for PHP, JS/TS, JSON, YAML, Twig, Markdown, CSS,
  SQL and more (via [tempest/highlight](https://github.com/tempestphp/highlight)),
  including correct coloring of multi-line docblocks and heredocs
- **Language Server Protocol** client over stdio, running on the same
  Revolt event loop as the UI — completely non-blocking
- **Autocompletion** popup (`Ctrl+Space`, and automatically after `->` / `::`),
  fed by the language server and **fuzzy/camel-hump** filtered and ranked as you
  type (e.g. `gm` matches `getMessage`); accepting an item also
  applies its **auto-import** `use` statements (`additionalTextEdits`, including
  those the server defers to `completionItem/resolve`) as a single undo step.
  **Snippet completions** expand with tab stops: `Tab` / `Shift+Tab` cycle
  through the placeholders (each selected so you can type over it), `Esc` ends
  the session
- **Go to definition** (`Ctrl+B` / `F12`) with a back-navigation stack (`Ctrl+Alt+Left`)
- **Auto-closing brackets & quotes**: typing an opener inserts its pair,
  selections get wrapped, closers type over, and backspacing an empty pair
  removes both — toggleable via the command palette / `autoPairs` setting
- **Live templates**: type `fore` and press `Tab` for a `foreach` with tab stops,
  or write the expression first and use a **postfix** template — `$rows.fore`,
  `$user.inn`, `$x.var`, `$ok.not`. Bodies are LSP snippets, so `Tab`/`Shift+Tab`
  walk the placeholders exactly as they do for a server's own snippet completions
- **Multiple cursors**: add carets above/below (`Ctrl+Alt+↑/↓`), at the next
  occurrence (`Alt+J`) or all occurrences (`Ctrl+Alt+Shift+J`); typing, deleting
  and pasting apply at every caret as a single atomic undo step
- **Structural editing**: expand/shrink the selection through the syntactic
  hierarchy — caret → word → brackets/quotes → line → document (`Ctrl+W` /
  `Ctrl+Shift+W`); move a statement over blank-line gaps (`Ctrl+Shift+↑/↓`);
  surround the selection with a bracket pair, quotes or an `if`/`foreach`/`try`
  snippet (`Ctrl+Alt+T`); and smart-Enter between a `{}`/`()`/`[]` pair opens a
  blank indented line with the closer dropped below
- **Multiple buffers** with an editor tab bar, `Ctrl+←/→` switching and a
  `Ctrl+E` recent-files popup — every buffer keeps its cursor, undo history
  and diagnostics
- **Integrated terminal** (`Ctrl+\`` or `Alt+F12`): a real shell in a bottom
  panel via a pseudo-terminal — run tests, git or composer without leaving the
  IDE. Colours and progress bars render; it is a line-oriented scrollback
  panel, not a full-screen VT host (vim/htop inside it are out of scope).
- **Run configurations** (`Ctrl+Shift+R`): run the current PHP file, a composer
  script, or a Makefile target in the terminal panel; `Shift+F10` repeats the
  last run
- **Git integration**: change markers in the gutter (added / modified /
  deleted vs `HEAD`, refreshed on open and save), marks in the **file tree** for
  every changed or untracked file in the working tree, and the current branch in
  the status bar — silently disabled outside a git working tree
- **Identifier highlight**: the caret's symbol lights up its other occurrences —
  **semantically** when the language server supports `documentHighlight`, with
  **write** accesses shaded apart from reads, falling back to a whole-word scan
  without one
- **Marker stripe**: the rightmost column maps the whole file onto the pane's
  height — diagnostics (`█▓▒░` by severity), breakpoints (`●`), git changes
  (`▎┆▁`) and search hits (`┈`) over a faint viewport thumb, so a glance down
  the margin says what is off screen. Click a cell to jump there; every mark
  is a distinct shape, so the stripe still reads under `--no-ansi`
- **Git view** (`Ctrl+Alt+D`): a full-screen changes/staging/history view —
  stage files or individual lines, commit and amend, browse the last 200
  commits, with a unified or side-by-side diff pane
- **Merge-conflict navigator**: jump between `<<<<<<<`/`=======`/`>>>>>>>`
  conflict regions (`F7` / `Shift+F7`) and resolve the one under the caret
  (`Alt+M`) by keeping ours, theirs or both — applied as a single undoable edit
- **Application menu** (`F10` or click): a menu bar atop the editor pane — File /
  Edit / Navigate / Code / Run / View / Help — with a dropdown of commands under
  each, every item showing its keyboard shortcut; the dropdown floats over the
  editor without shifting content down; `↑`/`↓` move, `←`/`→` switch menus,
  `Enter` runs, `Esc` closes, and titles/items are clickable
- **Mouse support** (SGR 1006): click to place the cursor, drag to select text
  (double-click a word, triple-click a line, Shift+click to extend), Alt+Click to
  add/remove a caret and Alt+Shift+drag for a column (rectangular) selection
  (PhpStorm-style), click the tree to open files / toggle folders, click a tab to
  switch buffers, and wheel-scroll the editor or tree
- **Fuzzy go-to-file** (`Ctrl+Shift+N`) and **incremental find/replace**
  (`Ctrl+F` / `Ctrl+R`) with live match highlighting
- **Find in project** (`Ctrl+Shift+F`): async `grep` streamed through the
  event loop into a results panel, `Enter` jumps to the match; toggle
  **regex** (`Alt+R`), **case-sensitive** (`Alt+C`) and **whole-word**
  (`Alt+W`), and **replace across the project** — `Tab` to the replace field,
  `Alt+A` applies to every matched file (open buffers as one undoable edit,
  others on disk)
- **Git write operations** (command palette): stage the current file
  (`git add`), commit with a message prompt (`git commit`), and blame the
  caret's line (`git blame`), all async and non-blocking
- **Symbol navigation**: file structure popup (`Ctrl+F12`) via
  `documentSymbol`, and go-to-symbol-in-project (`Ctrl+Alt+Shift+N`) via
  `workspace/symbol`, both fuzzy/live-filtered
- **Refactoring & navigation via LSP**: quick fixes / code actions
  (`Alt+Enter`, applying edits, resolving deferred ones or running server
  commands), **Refactor This** (`Ctrl+Alt+Shift+T`, the `refactor` kinds alone)
  and **Inline** (`Ctrl+Alt+N`, applied straight away when the server offers
  exactly one) — a selection is what the request asks about, so extract
  refactorings are offered; rename symbol
  (`Shift+F6`, validated first via `prepareRename` — refuses positions the
  server won't rename), find usages (`Alt+F7`), quick documentation (`Shift+F1`),
  signature help while typing arguments, go to line (`Ctrl+G`),
  **go to implementation** (`Ctrl+Alt+B`) and **type definition**
  (`Ctrl+Shift+B`) — when the server returns several targets a chooser opens in
  the results panel; **reformat** (`Ctrl+Alt+L`) formats the selection when one
  exists (`rangeFormatting`), otherwise the whole file
- **Live diagnostics** — errors and warnings in the gutter, status bar, and
  inline at end-of-line (dimmed), plus a project-wide problems panel (`Alt+6`)
  and reformat-file (`Ctrl+Alt+L`) where the language server supports it
- **Peek definition** (`Ctrl+Shift+I`): view a symbol's definition with
  surrounding context inline, without leaving the current file
- **Identifier highlight**: placing the caret on a name softly highlights its
  other occurrences in view (PhpStorm-style), alongside matching-bracket
  highlighting
- **EditorConfig**: a file's nearest `.editorconfig` (resolved by walking up to
  the first `root = true`) drives its indentation live — `indent_style`,
  `indent_size`, `tab_width` — and its `end_of_line`, `trim_trailing_whitespace`
  and `insert_final_newline` on save; disable with `"editorConfig": false`
- **Indent guides**: dim vertical guides mark each indentation level
- **Code folding** (`Ctrl+.`): collapse/expand the `{ … }` block at the caret;
  a `⋯ N lines` marker shows on the folded line, moving the caret into a
  collapsed region re-expands it, and clicks/scrolling map through hidden lines
- **Inlay hints**: dim inline parameter-name / inferred-type hints from the
  language server (`textDocument/inlayHint`), refreshed as you edit
- **Local history & session restore**: each save snapshots the file under
  `.porta/history/` (restore any snapshot with `Ctrl+Alt+H`); the open
  buffers and cursors are saved to `.porta/session.json` on quit and
  reopened next time you launch the IDE with no file argument.
  Local history ships as the bundled `local-history` plugin and is on by
  default — **but if your `.porta.json` already pins an explicit
  `enabledPlugins` list** (any project where you have toggled a bundled plugin
  in Settings ▸ Plugins), add `"local-history"` to it or snapshots stop being
  taken. Existing snapshots under `.porta/history/` are untouched either way
- File tree with lazy expansion, editor with undo/redo, auto-indent,
  smart Home, horizontal scrolling and a line-number gutter

```
 ide/                          │  1  <?php
 ▾ src/                        │  2
     Ide.php                   │  3  class Greeter
   ▸ Widget/                   │  4  {
   composer.json               │  5      public function greet(): string
                               │  6      {
                               │  7          return $this->▉
                               │  8      }        ┌ makeGreeting  method ┐
                               │  9  }            │ subject        prop  │
                               │                  └──────────────────────┘
 src/Greeter.php ●    1:24  LSP:phpantom  ^S save ^T panel ^Space complete ^Q quit
```

## Installation

Porta is a command, `porta`. It needs PHP ≥ 8.5 and an interactive terminal.
Three ways to put it on your `PATH`:

**The phar** — one file, no Composer at run time. Download it from the
latest [release](https://github.com/janalis/porta/releases) (the repository is
private, so with the `gh` CLI: `gh release download --repo janalis/porta
--pattern porta.phar`), then:

```bash
install -m 755 porta.phar ~/.local/bin/porta
porta --self-update   # later: replace it with the latest release
```

`--self-update` downloads through your `gh` login (or `GH_TOKEN` /
`GITHUB_TOKEN`), checks the release's SHA-256, runs the new phar once, and only
then swaps it in; `--self-update --dry-run` only says whether there is one. To
build it yourself instead: `composer install && composer build:phar`, which
fetches [Box](https://github.com/box-project/box) into `build/` the first time.

**Composer, globally.** The repository is not on Packagist, so name it first;
Porta follows `symfony/tui`'s development branch, hence the stability settings:

```bash
composer global config repositories.porta vcs git@github.com:janalis/porta.git
composer global config minimum-stability dev
composer global config prefer-stable true
composer global require janalis/porta:dev-main
```

and put `$(composer global config bin-dir --absolute)` on your `PATH`. If
Composer asks for a GitHub token (the repository is private), add
`--prefer-source` to clone over SSH instead.

**A checkout**, to work on Porta itself — a symlink is enough:

```bash
ln -s "$PWD/bin/porta" ~/.local/bin/porta
```

Once Porta runs, **Settings → Command line** does the same from inside it —
installs, updates or removes the `porta` link, and Porta as git's difftool and
mergetool. From a shell, `porta --git-setup` makes it git's tools, and
`porta --diff a.php b.php` compares two files by hand — see
[the git guide](plugins/git/docs/README.md#as-gits-difftool-and-mergetool).

## Language server

For completion and diagnostics you need an LSP server. The IDE looks for one
in this order:

1. `.porta.json` in the project root:
   ```json
   {"lsp": "php phpactor.phar language-server"}
   ```
2. The `PORTA_LSP` environment variable (same format)
3. An enabled **language-server plugin**. **phpantom** (`phpantom_lsp`) serves
   PHP out of the box; enable the bundled **phpactor** plugin under
   Settings → Plugins to use `phpactor language-server` instead. Each resolves
   its binary from the plugin's `binary` setting, then your `PATH`.
4. `typescript-language-server` / `pyright` on your `PATH` for JS/TS and Python.

The quickest PHP setup is to install phpantom
([PHPantom-dev/phpantom_lsp](https://github.com/PHPantom-dev/phpantom_lsp)) so
`phpantom_lsp` is on your `PATH`.

Without a server the IDE still works — you keep highlighting and editing,
the status bar shows `LSP:none (install phpantom)`.

## Usage

```bash
porta             # open the current directory
porta src/Foo.php # open a file directly
porta ~/other/app # open another project
```

### Keys

The keymap follows PhpStorm wherever the terminal allows.

| Key | Action |
| --- | --- |
| `Alt+1` | Focus the project tree |
| `Alt+6` | Problems panel (diagnostics across all open buffers) |
| `Alt+Backspace` | Delete word backwards |
| `Alt+Enter` | Quick fixes / code actions (via LSP) |
| `Ctrl+Alt+Shift+T` | Refactor This — the refactorings the server offers here |
| `Ctrl+Alt+N` | Inline (applied directly when there is only one) |
| `Alt+F7` | Find usages (results panel) |
| `Alt+J` | Add a caret at the next occurrence of the word |
| `Alt+M` | Resolve the conflict under the caret (keep ours / theirs / both) |
| `Alt+Shift+↑/↓` | Move line or selection up / down |
| `Alt+W` / `Ctrl+F4` | Close the active buffer (unsaved changes prompt Save / Discard / Cancel) |
| `Ctrl+←` / `Ctrl+→` | Previous / next editor tab |
| `Ctrl+.` | Collapse / expand the code fold at the caret (or click the gutter's `▾`/`▸`) |
| `Ctrl+/` | Toggle line comment |
| `Ctrl+A` | Select all |
| `Ctrl+Alt+B` | Go to implementation (chooser when several) |
| `Ctrl+Alt+D` | Git view: changes, staging, commit, history (`Esc` closes it) |
| `Alt+F1` | Select the opened file in the tree (expands to it and focuses it) |
| `Ctrl+Alt+H` | Show local history (restore an earlier snapshot) |
| `Ctrl+Alt+L` | Reformat file (via LSP formatting) |
| `Ctrl+Alt+Left` | Navigate back after a definition jump |
| `Ctrl+Alt+O` | Go to symbol in project (workspace symbols) |
| `Ctrl+Alt+S` | Save all modified buffers |
| `Ctrl+Alt+Shift+J` | Add carets at all occurrences |
| `Ctrl+Alt+T` | Surround selection (bracket pair, quotes, if/foreach/try) |
| `Ctrl+Alt+↑` / `Ctrl+Alt+↓` | Add a caret above / below |
| `Ctrl+B` / `F12` | Go to declaration (via LSP) |
| `Ctrl+C` / `Ctrl+X` / `Ctrl+V` | Copy / cut / paste (also copies to the system clipboard via OSC 52) |
| `Ctrl+D` | Duplicate line or selection |
| `Ctrl+E` | Recent files popup (`Ctrl+E` again cycles, `Enter` opens) |
| `Ctrl+F12` | File structure popup (symbols in current file) |
| `Alt+7` | Structure panel — the same symbols as a dock tab that stays open |
| `Ctrl+F` / `Ctrl+R` | Find / find-and-replace in file (`F3`/`Shift+F3` cycle, `Tab` switch field, `Alt+R` replace, `Alt+A` replace all) |
| `Ctrl+G` | Go to line |
| `Ctrl+Home` / `Ctrl+End` | Start / end of file |
| `Ctrl+K` | Delete to end of line *(deviation: PhpStorm commits)* |
| `Ctrl+P` | Go to file (fuzzy finder) |
| `Ctrl+Q` | Quit, twice to discard unsaved changes *(deviation: PhpStorm quick-doc)* |
| `Ctrl+S` | Save |
| `Ctrl+Shift+A` | Find action (command palette) |
| `Ctrl+Shift+B` | Go to type definition |
| `Ctrl+Shift+F` | Find in project (`Alt+R/C/W` toggle regex / case / whole-word; `Tab`+`Alt+A` replace all) |
| `Ctrl+Shift+I` | Peek definition (inline, no buffer switch; Enter jumps) |
| `Ctrl+Shift+R` / `Shift+F10` | Run menu / repeat last run |
| `Ctrl+Shift+↑/↓` | Move statement up / down (hops blank-line gaps) |
| `Ctrl+Space` | Trigger autocompletion (auto after `->` and `::`) |
| `Ctrl+T` / `F2` | Switch between file tree and editor |
| `Ctrl+W` / `Ctrl+Shift+W` | Extend / shrink selection (word → brackets → line → file) |
| `Ctrl+Y` | Delete line or selected lines |
| `Ctrl+Z` / `Ctrl+Shift+Z` | Undo / redo (typing runs coalesce) |
| `Ctrl+\`` / `Alt+F12` | Toggle the integrated terminal (`Esc` or the same key hides it) |
| `Enter` (tree) | Open file / toggle directory |
| `Esc` (multi-caret) | Collapse back to a single caret |
| `F1` | Documentation browser (the guides in `docs/`, rendered; `Tab` cycles links, `Enter`/click follows, `Backspace` back) |
| `Shift+F1` | Quick documentation popup |
| `F6` / `Ctrl+F6` | Cycle focus forward / backward |
| `F7` / `Shift+F7` | Jump to next / previous merge conflict |
| `Home` | Smart home (first non-blank, then column 0) |
| `Shift+F6` | Rename symbol (via LSP WorkspaceEdit, cross-file) |
| `Shift+arrows` / `Ctrl+Shift+←/→` | Select (character / word) |
| `Tab` / `Shift+Tab` | Next / previous snippet tab stop (while a snippet is active) |
| `r` (tree) | Reload the tree |
| `←` / `→` (tree) | Collapse / expand directory |
| `↑` `↓` `Enter` / `Tab` | Navigate / accept completion, `Esc` closes |
| Mouse | Click to position cursor / open tree files / switch tabs; wheel to scroll |

### Custom keymap & settings

Both live in `.porta.json`:

```json
{
    "keymap": {
        "duplicate_line": ["ctrl+shift+d"],
        "save": ["f10", "ctrl+s"]
    },
    "settings": {
        "tabWidth": 4,
        "diagnosticMessages": "inline",
        "lineNumbers": "absolute",
        "autoPairs": true
    }
}
```

The **command palette** (`Ctrl+Shift+A`, "Find Action") lists every action by
name and can run common ones, including cycling diagnostic messages, inlay
hints and line numbers through their modes, which persist back into
`.porta.json`.
Unknown action/setting names are reported in the status bar at startup. The full
action list is in `Porta\Config\IdeConfig::KNOWN_ACTIONS`. Heavily
modified chords (e.g. `Alt+Shift+↑`) work best in terminals with the Kitty
keyboard protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

## Project-local state

The IDE writes a `.porta/` directory in the project you open (local history
snapshots and the saved session) and reads/writes `.porta.json` for config.
Add these to your project's `.gitignore`:

```gitignore
/.porta/
```

## Notes

- Tabs are preserved verbatim: a file's original indentation (tabs or spaces)
  round-trips through load and save unchanged. Tabs render at 4-cell stops, and
  `Tab` / auto-indent insert the file's own indent unit (a tab in a tab-indented
  file, four spaces otherwise).
- Files larger than 2 MB open **read-only**: a scrollable preview of the first
  2 MB, with a banner naming the file's real size. Nothing about that tab can be
  edited or saved. Non-UTF-8 files are still refused.
- Git change marks are recomputed from `HEAD` on open, save, reload and buffer
  switch, and follow your edits in between: they move with the lines below an
  insertion or deletion, and the line under the caret lights as you type. The
  in-between state is an approximation around the active caret; each recompute
  replaces it with git's own answer.
- Highlighting is incremental on files ≥ 2000 lines: local edits re-parse
  only the touched lines (~0.2 ms per keystroke at 10k lines), with a full
  repair parse every 25 edits to restore multi-line token colors.
- Documents are synced to the language server with `didChange` notifications
  debounced at 300 ms — **incremental** (a minimal prefix/suffix delta) when the
  server advertises incremental sync, falling back to full-text otherwise.

## Development

```bash
composer install
composer verify            # every gate, cheapest first, stopping at the first failure
composer verify:quick      # the seven static gates only — the mid-work check
composer test              # PHPUnit: Unit + Integration + E2e + Plugins suites
composer test:unit         # one suite — also test:integration, test:e2e, test:plugins
composer coverage          # coverage report (needs the pcov extension)
composer coverage:check    # enforces 100% line coverage of src/ and every plugin's
composer duplication       # copy/paste detector — zero clones
```

The suite covers **100% of the lines in `src/`**: unit tests per class,
integration tests for the LSP client against mock/fault-injection servers
(`tests/Support/*-lsp-server.php`), and end-to-end tests that boot the whole
IDE on a `VirtualTerminal` and drive it with scripted keystrokes.

Coverage needs `pcov` built against your PHP
(`pecl install pcov`; on Homebrew PHP you may need
`CPATH="$(brew --prefix pcre2)/include" pecl install pcov`).

## License

MIT
