# Getting started

## Requirements

- PHP ≥ 8.5
- An interactive terminal. Mouse support and heavily modified chords work
  best in terminals with SGR mouse reporting and the Kitty keyboard
  protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

## Installation

Install into any PHP project:

```bash
composer require --dev pierre/porta
vendor/bin/ide
```

## Language server

For completion, diagnostics, navigation and refactoring you need an LSP
server. The IDE looks for one in this order:

1. `.porta.json` in the project root — a single command or a per-language
   map (see [configuration.md](configuration.md#lsp)):
   ```json
   {"lsp": "php phpactor.phar language-server"}
   ```
2. The `PORTA_LSP` environment variable (same format)
3. `vendor/bin/phpactor` in the project
4. `phpactor` on your `PATH`
5. `intelephense` on your `PATH` (`npm i -g intelephense`)

The quickest setup:

```bash
composer require --dev phpactor/phpactor
```

Without a server the IDE still works — you keep highlighting and editing;
the status bar shows `LSP:none`.

## Usage

```bash
vendor/bin/ide             # open the current directory
vendor/bin/ide src/Foo.php # open a file directly
vendor/bin/ide ~/other/app # open another project
```

Launching without a file argument restores your previous session: open
buffers, cursor positions and the active tab.

## First steps

- `F10` arms the **application menu** — every command with its shortcut. `↓`
  opens File, or type a title's underlined letter; in a menu, an item's
  underlined letter runs it.
- `Ctrl+Shift+A` opens the **command palette** ("Find Action") — every
  action by name, fuzzy-filtered.
- `Ctrl+Shift+N` (or `Ctrl+P`) fuzzy-opens files; `Ctrl+E` lists recent ones.
- `Ctrl+,` opens **Settings** (theme, UI language, keybindings, plugins,
  per-language indent/LSP/debugger).
- `Ctrl+Q` quits (twice to discard unsaved changes).

The IDE writes a `.porta/` directory into the project (session, layout,
local history, project plugins) and reads/writes `.porta.json` for
configuration. Add `/.porta/` to your project's `.gitignore`.

## Where to go next

- [Editor features](editor.md)
- [Keybindings](keybindings.md)
- [Search & navigation](search-navigation.md)
- [Git integration](git.md)
- [Running & the terminal](run-and-terminal.md)
- [Debugger](debugger.md)
- [Configuration](configuration.md)
- [Plugins](plugins.md)
- [Architecture (contributors)](architecture.md)
