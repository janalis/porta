# Porta documentation

Porta is a terminal IDE for PHP projects built on
[symfony/tui](https://github.com/symfony/tui), with an LSP client, a DAP
debugger, git integration and a plugin system — all on a single
non-blocking event loop.

![Porta: the project tree, a PHP file in the editor, the status bar](/screenshots/overview.svg)

## Using Porta

| Guide | Contents |
| --- | --- |
| [Getting started](getting-started.md) | Installation, LSP setup, launching, first steps |
| [Editor](editor.md) | Buffers, editing, multi-caret, folding, indentation, live preview, mouse |
| [Keybindings](keybindings.md) | Every default binding, and how to rebind |
| [Search & navigation](search-navigation.md) | Go to file/symbol/test, find in file, Find in Files, problems panel |
| [Run & terminal](run-and-terminal.md) | Run configurations, the integrated terminal |
| [Debugger](debugger.md) | DAP adapters, breakpoints, stepping, the debug panel |
| [Configuration](configuration.md) | `.porta.json`, the Settings view, `.editorconfig`, the `.porta/` state directory |
| [Plugins](plugins.md) | Installing/removing plugins, writing your own, the `PluginApi` reference |

## Bundled plugins

Porta ships these plugins, each documented in its own directory — and so can
yours ([Documenting your plugin](plugins.md#documenting-your-plugin)). A
plugin's guide also opens from its page under **Settings → Plugins**.

| Plugin | Contents |
| --- | --- |
| [Git](../plugins/git/docs/README.md) | Gutter markers, palette operations, the Git view (staging, commits, history), difftool and mergetool |
| [Diff](../plugins/diff/docs/README.md) | The diff pane git and review show changes in, and the model behind it |
| [Code review](../plugins/review/docs/README.md) | Pull and merge request threads, and local reviews from an agent — in a view, in the diff and in the editor |
| [Assist](../plugins/assist/docs/README.md) | Work on one project together, live: share it, let others in, edit the same files at once, follow each other |
| [Agents (MCP)](../plugins/mcp/docs/README.md) | Let an agent such as Claude Code read your buffers and diagnostics, open files and make undoable edits in the running IDE |
| [SSH](../plugins/ssh/docs/README.md) | Open a project that lives on a server over ssh: browse, edit, save |
| [FTP](../plugins/ftp/docs/README.md) | Open a project on an FTP or FTPS server: browse, edit, save |
| [Local history](../plugins/local-history/docs/README.md) | A snapshot on every save, independent of git: compare, restore, recover deleted files |
| [Image viewer](../plugins/image-viewer/docs/README.md) | Images and SVG drawings as pictures — zoom, pan, pixel-exact where the terminal allows |
| [Graph viewer](../plugins/graph-viewer/docs/README.md) | Graphviz and PlantUML diagrams drawn beside their source, the project's architecture graph |
| [TODO](../plugins/todo/docs/README.md) | Every TODO and FIXME in the project, in a panel |
| [Xdebug](../plugins/xdebug/docs/README.md) | Debug PHP with Xdebug in one step: the vscode-php-debug adapter, found or installed for you |
| [PHPUnit](../plugins/phpunit/docs/README.md) | Run the test at the caret, the file, the suite or the failed ones; results in the dock, failures inline |
| [PHPantom](../plugins/phpantom/docs/README.md) | The suggested PHP language server, and its fixer |
| [Phpactor](../plugins/phpactor/docs/README.md) | Phpactor as the PHP language server instead |
| [Symfony language server](../plugins/symfony-lsp/docs/README.md) | Twig, YAML, XML, JSON and dotenv intelligence, installed and updated for you |
| [custos](../plugins/custos/docs/README.md) | PHP inspections and quick-fixes beside your language server, and Fix File |
| [PHPStan](../plugins/phpstan/docs/README.md) | Analyse the current file, or every file on save |
| [Psalm](../plugins/psalm/docs/README.md) | Analyse the current file, or every file on save |
| [Mago](../plugins/mago/docs/README.md) | Format and lint, on demand or on save |
| [PHP CS Fixer](../plugins/php-cs-fixer/docs/README.md) | Fix the current file, or every file on save |
| [Tempest](../plugins/tempest/docs/README.md) | The built-in syntax highlighting engine |
| [Chroma](../plugins/chroma/docs/README.md) | Highlighting for the languages the built-in engine has no grammar for |

## Language support

The UI is translated into English, French, German, Spanish, Italian,
Japanese and Hebrew. Hebrew mirrors the layout — the file tree moves to
the right — and reorders each pane's chrome right-to-left.

Code stays left-to-right, which is the point: the editor, the git diff,
the terminal, the search results and the debug panel are verbatim, as
are the Find-in-Files and go-to-file dialogs, which carry paths and
code. Those surfaces' own headings stay left-to-right with them.
Hebrew *inside* a document is not reordered either — the editor shows
it in logical order and leaves shaping to your terminal.

Switch under **Settings → Interface → Language**.

## Contributing

| Guide | Contents |
| --- | --- |
| [Architecture](architecture.md) | Event loop, DI composition root, rendering, subsystems, tests and quality gates |
| [Events](events.md) | The event bus: facts vs commands, listener priority, adding an event, the gates |
| [Troubleshooting](troubleshooting.md) | Common failures and what they mean |
| [Internals](internals/README.md) | Working on Porta itself: gates, container, subsystems, testing, settled questions |
