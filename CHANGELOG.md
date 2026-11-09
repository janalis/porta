# Changelog

All notable changes to Porta. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.6] - 2026-11-09

### Added

- **Plugin store**: **Settings → Plugins → Store** (or **Plugins: Open Store**,
  also in the File menu) lists every Porta plugin published on Packagist. Browse
  the Discover and Updates tabs by category or name. Each plugin has a page
  with its screenshots, what changed in its newest release, its README and its
  details, and one button: Get, Update, Open, Built-in or Enable. A plugin Porta
  has not verified asks twice before it installs, since it runs as PHP with
  your permissions. See [The plugin store](docs/plugin-store.md).
- **Plugin updates**: Porta checks for newer releases of the plugins you
  installed from the store, weekly by default (`pluginUpdateCheck`), and can
  install them as soon as it finds them (`pluginAutoUpdate`). An updated plugin
  that is running reloads at once, without a restart. If it had already loaded
  some of its own code, the update says a restart finishes it. An outdated
  plugin's Settings page has its own Update button too.
- **Publishing a plugin**: register yours in the store with a pull request
  adding it to `store/plugins.json`; CI checks that you own the package. The
  owner of a tool a bundled plugin drives (mago, phpstan, symfony-lsp, …) can
  publish their own plugin in its place. The store then offers theirs, and the
  bundled plugin's Settings page points to it.

- `porta --self-update` (and **Settings → Command line → Porta itself**) now
  updates every install: through `brew upgrade` for Homebrew and `composer
  global update` for a global Composer install. A checkout or a project's
  dependency is told the command to run. Before, only a downloaded phar could
  update itself.

### Fixed

- Installing a plugin under the name of one that was running — a copy of a
  bundled plugin, say — failed with "duplicate plugin name". It now replaces
  the running one at once.
- `porta --self-update` from Homebrew replaced the phar in brew's Cellar behind
  brew's back. Through a `porta` that is a link to the phar, it replaced the
  link with a plain file. It now leaves Homebrew's phar to brew and updates the
  file a link points to.

## [0.1.5] - 2026-11-08

### Security

- **Assist**: a guest could open any file under the shared project, including
  ones the share leaves out (a gitignored `.env`), and a guest who may edit
  could rewrite it. A guest now opens and saves only what the host shares.
- **Assist**: a guest's language-server session could point the host's server
  at any folder on the host's disk and read it back through hover and symbol
  search. The host's server now always works on the host's project.
- **Assist**: a guest could freeze the host by asking for the file list many
  times at once; each ask ran a full project scan.

### Fixed

- **Data loss**: renaming or moving a file whose unsaved edits could not be
  saved first — the file had changed on disk — went ahead and lost the edits.
  It now stops and says the file changed. Trying again at once no longer
  overwrites the other program's changes.
- **Data loss**: discarding a renamed file in the git view restored the old
  name over a file you had written there since. Discarding a copy no longer
  touches the file it was copied from.
- **Data loss**: keeping a Claude Code proposal after its tab was closed
  emptied the file.
- **Data loss**: the stash picker could drop or apply a different stash than
  the one picked, when the list changed in a terminal while it was open.
- **Format on save**: saving a second file while the first was still being
  formatted silently cancelled the first file's save.
- **Symbolic links**: deleting or renaming a link in the tree, or by a
  language server, acted on the file it points to — closing its tabs and
  forgetting its bookmarks and history.
- **Line endings**: a language-server rename, and a Claude Code proposal,
  rewrote a CRLF file as LF and dropped its byte-order mark; a proposal's
  side-by-side comparison showed every line of such a file as changed.
- **Language servers**: a rename onto an existing file marked "ignore if it
  exists" overwrote it; a create marked "overwrite" kept the old text; an
  operation the disk refused stopped the IDE mid-edit instead of saying which
  files were not written.
- **Search and replace**: a replace limited to comments and strings wrote `$1`
  literally and ignored Preserve Case, landed three characters late in files
  with a byte-order mark, and rewrote files with unsaved edits; a multi-line
  replace now leaves those alone too, and names them.
- **Files changed elsewhere**: a file rewritten by another program into
  something Porta cannot show (not UTF-8, or over 2 MB) is now flagged as
  changed, so the next save warns before overwriting it.
- A `.porta.json` saved with a byte-order mark was ignored as invalid, and
  set aside at the next settings change.
- A project folder moved or deleted while open was recreated by the next
  layout save.
- Stopping a run in a terminal tab could freeze the IDE when its process was
  slow to exit.
- Claude Code: one session quitting turned down another session's waiting
  proposals; a session that closed without reading its replies could freeze
  the IDE.
- Git: comparing two binary files that differ said they were identical; a
  failed discard of an untracked file said "Deleted".
- Local History: restoring from the tree failed in a project opened through a
  symbolic link; a comparison git could not make said the snapshot matched
  the file.
- TODO: a scan started while another ran could leave the older results in
  the tab.
- Review: the IDE could crash when a review disappeared while its view was
  closed.

## [0.1.4] - 2026-11-06

### Added

- **The phpunit plugin**: run the test at the caret (`Ctrl+Shift+F10`), a
  file's tests, the whole suite, or the tests that failed last time. Failures
  are diagnostics on their assertion, each test method is marked passed or
  failed, and the status bar counts the failures. Pest projects run with Pest.
  **Run All Tests with Coverage** marks what ran and what no test reached in
  the change strip. Off by default.
- **The xdebug plugin**: PHP debugging with Xdebug in one step. It uses the
  vscode-php-debug adapter from VS Code, Cursor or VSCodium, or installs it
  (checked against a pinned SHA-256): `Shift+F9` debugs the PHP file in front,
  and **Xdebug: Start Listening for Connections** debugs web requests and
  anything else started with Xdebug. Off by default.
- **The composer plugin**: direct dependencies with newer releases — within
  your constraint, past it, or abandoned — listed in the dock and marked in
  `composer.json`. Install, update, require and remove run in a terminal tab
  of their own. Off by default.
- **Moving a PHP file moves its namespace**: with a language server that
  offers it (phpactor), a file moved or renamed in the tree has its namespace
  and every `use` of its class updated, as one step Undo Last Refactoring
  takes back.

### Changed

- **Git: Push** is `Alt+K` on every platform. Its `Ctrl+Shift+K` is the
  editor's Delete Line, and in a terminal that reports the chord (kitty,
  Ghostty, WezTerm) deleting a line pushed.
- Plugin authors: a plugin is named by its folder, whatever `name()` returns;
  a core key a plugin adopts must be one core skips; an IDE action called in
  `activate()` fails the plugin when it is turned on live, as it does at
  startup; `projectTrusted()` answers as the project stands now.

### Fixed

- **Editor**: a caret placed by a reload, a selection or a click lands on a
  character, never inside one; typing at several carets keeps them in step
  and stays fast; typing somewhere else, or through an auto-pair, is one undo
  step; words are letters of any script, Latin-1 lines included, and `Home`
  stops after tabs; uncomment takes the space commenting added, not the
  indent; occurrence carets match whole identifiers; a CRLF or BOM file keeps
  its line endings through whole-text edits and no longer looks changed on
  disk after a formatter; a double or triple click keeps its word or line;
  blame and other notes at the end of a line survive a jump within the file.
- **Find and replace**: a regex replace expands its references in place,
  however far into a long line; a multi-line query matches a CRLF file and
  replaces with the file's own line ending. In Find in Files, `Alt+A` waits
  for the search to land and replaces only the results shown; a buffer with
  unsaved edits is left alone and named; local history in `.porta/` is no
  longer searched, nor offered by Go to File.
- **Files and local history**: rename takes a name, never a path, and renames
  across case or Unicode spellings; New Folder over a taken name says so; a
  copy pasted into its own folder takes a free name; deleting or renaming a
  folder keeps its files' history; deleting a link to a folder leaves the
  target alone; Compare with Snapshot works under a coloured git config and
  with binary files. A `.markdown` file is Markdown everywhere.
- **Git**: a conflict is never discarded, and discard never deletes a nested
  repository or worktree; discarding a staged change goes back to HEAD; a
  refused commit says why in one line; a file name is never read as a
  pattern; commands that write the index take turns; a first push goes to the
  repository's only remote and its offer names that remote; a project below
  the repository's root is told which folder to open; reverting a deletion or
  a CRLF change works; a changed binary file says the files differ; the last
  line of a file without a final newline diffs correctly.
- **Run and terminal**: a run saves the edited buffers first, and runs
  nothing if a save is refused; a run is its own process group — `Ctrl+C`
  interrupts it and closing its tab stops what it started; progress bars
  redraw in place; a colour lasts until a reset; a 9 MB paste no longer stalls.
- **Language servers**: phpantom's errors and warnings show — Porta asks for
  them, as phpantom only reports when asked — and its call hierarchy
  (`Ctrl+Shift+H`) works. Rename, reformat and code actions never land on
  text typed since, edits apply in the order sent and to the right file, and
  a completion replaces the whole range its server gives.
- **Debugger**: a breakpoint moves with its line as you edit; a stack or a
  *Paused* answered after you resumed is not painted; an inline value after
  an emoji shows; a plugin's adapter keeps the language's launch config.
- **Plugins**: enabling or disabling one respects the other config file and
  the plugins it requires; a plugin whose requirement is not running is not
  loaded, and removing one stops those that need it; a plugin switched off
  takes back what it showed and stops its timers; installing a
  language-server plugin starts its server; php-cs-fixer's extra arguments
  keep their quoting; a restricted project can still turn graph-viewer's
  PlantUML preview off.
- **Remote projects (SSH, FTP)**: a plugin's create, move and delete, and an
  undone refactoring, reach the server; a listing older than your own change
  is dropped; one folder the server refuses no longer empties the tree; a new
  file gets the server's usual mode, and a link is written through; a typed
  FTP password is used by its own server only.
- **Agents (MCP)**: Claude Code's save obeys *Let agents save files*; undo
  after an agent's edit returns to your version.
- **Settings and session**: writing a setting keeps the file's formatting;
  two windows on one project keep each other's bookmarks, breakpoints and
  session; a copied project reopens its own files.

### Security

- graph-viewer asks whether the project is trusted at each Mermaid render,
  not once when it starts.

## [0.1.3] - 2026-11-03

### Added

- **Screenshots in the docs**: the guide and every bundled plugin's page open
  on a screenshot of the IDE, drawn from Porta itself each time the site is
  built, so they always show the current version.

### Fixed

- The cursor no longer jumps past a popup's shadow: typing with the
  completion popup open, its shadow cast along the caret's row put the
  terminal cursor at the shadow's far end instead of after what you typed.
- The numeric keypad types its digits and operators under the Kitty keyboard
  protocol, where they used to be read as keys with no name and typed nothing.

## [0.1.2] - 2026-11-02

### Added

- **Diagrams without their tools**: with no `dot` or `plantuml` installed,
  Graphviz and PlantUML diagrams — in their tab, in Markdown fences and in
  exports — are drawn natively by janalis/viz 0.2. A DOT tab drawn this way is
  the picture alone: node selection, the counts and the cell rendering come
  from `dot`'s layout. What viz leaves to PlantUML, such as an `!include` of a
  file or a URL, is refused with the reason in the banner.

### Changed

- A PlantUML document opens beside its preview whenever *Preview PlantUML
  beside its source* is on, no longer only when `plantuml` is found.
- A Mermaid ER relationship is labelled with its role alone, as Mermaid draws
  it (janalis/viz 0.2.2).

## [0.1.1] - 2026-11-02

### Added

- **Agents (MCP)**: an MCP server inside the IDE (`porta mcp`). Agents read
  buffers, diagnostics and the selection, ask the language server
  (definition, references, hover, symbols, outline, rename, format), search
  the project, make undoable edits behind the user's tab, create, move and
  delete files (local history takes any of it back), and run tasks and tests.
  An agent at work shows its caret, its name and its files; any plugin can
  offer tools of its own.
- **Claude Code** connects as the IDE: it sees the open file and the selected
  lines, and its proposed edits are shown in Porta — inline, as a diff in the
  dock, or side by side in the git view — to accept or reject.
- **Assist**: work on one project together, live. Guests join with a code,
  the session is encrypted, and a relay carries it when guests cannot reach
  the host. Follow each other, share terminals, language servers, run
  configurations, a dev server and the debugger.
- **Code review**: pull and merge request threads, and local reviews from an
  agent, in a view, in the diff and in the editor; `porta review` writes and
  reads local reviews from a shell.
- **Remote projects**: open a project on a server over SSH or FTP — browse,
  edit, save, with unsent changes listed and the link restored on its own.
  Presence shows who else is at work on the same project.
- **Live preview**: Markdown, SVG and diagrams open as text beside a preview
  that follows the caret; a click in the preview puts the caret on its
  source. A CommonMark 0.31.2 + GFM parser lays out the preview and the F1
  help as GitHub draws them. A native Mermaid renderer, and Mermaid, Graphviz
  and PlantUML fences previewed in Markdown.
- **Git**: a view after GitHub Desktop — toolbar, three-column History,
  worktrees, merges and rebases in progress (continue, skip, abort), and a
  three-way merge view (ours | result | theirs). `porta diff A B` and
  `porta merge L R B M` as git's difftool and mergetool.
- **Editor**: PhpStorm-style problem cards on hover, inlay hints as pills, a
  severity-tinted gutter and marker stripe; soft wrap as a setting, followed
  by the diffs; drop files and folders in from outside the IDE.
- **Tools**: plugins declare the tools they run; Settings installs, updates
  and removes them (and Porta's own), with an update check.
- **custos** plugin: PHP inspections and quick-fixes beside the language
  server, and Fix File.
- **Debugger**: pause a running program.
- Views open in movable windows; file-tree verbs (create, rename, delete)
  from the keyboard; each platform's own keys (Cmd on a Mac).
- Search folds every letter when ignoring case (`été` finds `ÉTÉ`), in the
  find bar, Find in Files, Go to File and the pickers.
- The UI language follows the system by default; plugins carry their own
  translations and their own guide.

### Changed

- Bundled plugins are published as `janalis/porta-<name>`, each from a
  read-only split repository. A plugin package's leading `porta-` is dropped
  from the directory it installs into, so `janalis/porta-todo` installs as
  `todo` and shadows the bundled copy.
- A plugin's entry file is `porta.php`, not `plugin.php`: in a plugin's own
  repository it says whose plugin it is. Rename it in a plugin of your own.
- Opening a project no longer runs it: an untrusted project opens restricted.

### Fixed

- Language servers, debug adapters, git remotes and assist links that die,
  hang or wedge never leave the IDE waiting, and no child process outlives it.
- Many rendering fixes for right-to-left (Hebrew) layouts, wide graphemes,
  tabs and malformed escapes in terminal output.
- Regex search reads PCRE where grep can; the PHP search walk no longer
  freezes the UI.

### Security

- A restricted project starts no language server, runs no git program, mago
  or mmdc it names, installs or removes no plugin, cannot pick assist's relay,
  and reopens only its own files from `.porta/session.json`.
- An agent runs no task or test in a project not trusted, and publishes
  nothing to a forge until the user turns `allowPublish` on.
- What Porta keeps in `.porta/` is never written through a link; the MCP
  socket pointer is believed only for the user's own socket.
- A forge token goes only to a host the user named; downloads refuse a
  downgrade to plain http; the image viewer refuses decompression bombs;
  shown text from a peer can never reach the terminal as escapes.

## [0.1.0] - 2026-09-29

First release: the editor, LSP client, syntax highlighting, autocompletion,
integrated terminal, run configurations, git integration, DAP debugger,
plugin system, and `porta --self-update`.
