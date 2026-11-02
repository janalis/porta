# Changelog

All notable changes to Porta. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
