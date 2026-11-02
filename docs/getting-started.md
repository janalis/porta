# Getting started

![Porta open on a PHP project](/screenshots/overview.svg)

## Requirements

- PHP ≥ 8.5
- An interactive terminal. Mouse support and heavily modified chords work
  best in terminals with SGR mouse reporting and the Kitty keyboard
  protocol (kitty, WezTerm, iTerm2 3.5+, foot, Ghostty).

## Installation

Porta is a command, `porta`. It needs PHP ≥ 8.5 and an interactive terminal.
Four ways to put it on your `PATH`:

**Homebrew** (macOS, Linux) — the phar, with PHP as a dependency:

```bash
brew install janalis/tap/porta
```

**The phar** — one file, no Composer at run time. Download it from the
latest [release](https://github.com/janalis/porta/releases), then:

```bash
curl -fsSLO https://github.com/janalis/porta/releases/latest/download/porta.phar
install -m 755 porta.phar ~/.local/bin/porta
porta --self-update   # later: replace it with the latest release
```

`--self-update` checks the release's SHA-256, runs the new phar once, and only
then swaps it in; `--self-update --dry-run` only says whether there is one.
A `GH_TOKEN` or `GITHUB_TOKEN` in the environment is sent along, which only
lifts GitHub's anonymous rate limit. To build it yourself instead:
`composer install && composer build:phar`, which fetches
[Box](https://github.com/box-project/box) into `build/` the first time.

**Composer, globally.** Porta follows `symfony/tui`'s development branch,
hence the stability settings:

```bash
composer global config minimum-stability dev
composer global config prefer-stable true
composer global require janalis/porta
```

and put `$(composer global config bin-dir --absolute)` on your `PATH`.

**A checkout**, to work on Porta itself — a symlink is enough:

```bash
ln -s "$PWD/bin/porta" ~/.local/bin/porta
```

Once Porta runs, **Settings → Command line** does the same from inside it —
installs, updates or removes the `porta` link, and Porta as git's difftool and
mergetool. From a shell, `porta --git-setup` makes it git's tools, and
`porta --diff a.php b.php` compares two files by hand — see
[the git guide](../plugins/git/docs/README.md#as-gits-difftool-and-mergetool).

## Language server

For completion, diagnostics, navigation and refactoring you need an LSP
server. The IDE looks for one in this order:

1. `.porta.json` in the project root — a single command or a per-language
   map (see [configuration.md](configuration.md#lsp)):
   ```json
   {"lsp": "php phpactor.phar language-server"}
   ```
2. The `PORTA_LSP` environment variable (same format)
3. An enabled **language-server plugin**. [**phpantom**](../plugins/phpantom/docs/README.md)
   (`phpantom_lsp`) serves PHP out of the box; enable the bundled
   [**phpactor**](../plugins/phpactor/docs/README.md) plugin under
   Settings → Plugins to use `phpactor language-server` instead, and
   [**symfony-lsp**](../plugins/symfony-lsp/docs/README.md) for Twig, YAML,
   XML and the rest of a Symfony project. Each resolves
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
- [Git integration](../plugins/git/docs/README.md)
- [Running & the terminal](run-and-terminal.md)
- [Debugger](debugger.md)
- [Configuration](configuration.md)
- [Plugins](plugins.md)
- [Architecture (contributors)](architecture.md)
