# Getting started

## Requirements

- PHP ≥ 8.5
- An interactive terminal. Mouse support and heavily modified chords work
  best in terminals with SGR mouse reporting and the Kitty keyboard
  protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

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

To use Porta as git's difftool and mergetool, run `porta --git-setup` — see
[the git guide](git.md#as-gits-difftool-and-mergetool).

## Language server

For completion, diagnostics, navigation and refactoring you need an LSP
server. The IDE looks for one in this order:

1. `.porta.json` in the project root — a single command or a per-language
   map (see [configuration.md](configuration.md#lsp)):
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

Without a server the IDE still works — you keep highlighting and editing;
the status bar shows `LSP:none`.

## Usage

```bash
porta             # open the current directory
porta src/Foo.php # open a file directly
porta ~/other/app # open another project
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
