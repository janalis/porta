<p align="center">
  <img src="docs/public/logo.png" alt="Porta" width="160">
</p>

<h1 align="center">Porta</h1>

<p align="center">
  <strong>A terminal IDE for PHP.</strong><br>
  LSP · debugger · git · live collaboration · agents · plugins
</p>

<p align="center">
  <a href="https://github.com/janalis/porta/actions/workflows/ci.yml"><img src="https://github.com/janalis/porta/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://janalis.github.io/porta/"><img src="https://github.com/janalis/porta/actions/workflows/docs.yml/badge.svg" alt="Docs"></a>
  <a href="https://github.com/janalis/porta/releases"><img src="https://img.shields.io/github/v/release/janalis/porta?sort=semver" alt="Latest release"></a>
  <a href="https://packagist.org/packages/janalis/porta"><img src="https://img.shields.io/packagist/v/janalis/porta" alt="Packagist"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/janalis/porta" alt="MIT license"></a>
</p>

<p align="center">
  <a href="https://janalis.github.io/porta/"><strong>Documentation</strong></a> ·
  <a href="https://janalis.github.io/porta/getting-started">Getting started</a> ·
  <a href="https://janalis.github.io/porta/keybindings">Keybindings</a> ·
  <a href="https://janalis.github.io/porta/plugins/git/">Plugins</a> ·
  <a href="https://janalis.github.io/porta/plugins">Writing a plugin</a>
</p>

---

Porta is an IDE that runs in your terminal, written in PHP for PHP projects,
on the [symfony/tui](https://github.com/symfony/tui) component. The editor,
the language servers, the debugger and every subprocess share one
non-blocking event loop, so typing never waits on a server.

- **Code intelligence** from any LSP server — completion with auto-imports,
  diagnostics, go to definition, references, rename, code actions, inlay
  hints — with PHPantom, Phpactor and the Symfony language server set up for
  you, and PHPStan, Psalm, Mago, PHP CS Fixer and custos beside them.
- **An editor** with multiple carets, structural selection, live templates,
  folding, soft wrap, and a live preview for Markdown, SVG and diagrams.
- **Git** in a view after GitHub Desktop: staging by line, history,
  worktrees, a three-way merge view, and Porta as git's difftool and
  mergetool. Pull and merge request threads in the editor.
- **A debugger** (DAP: Xdebug and more), an integrated terminal and run
  configurations.
- **Work together**: share a project live with others (Assist), or open one
  on a server over SSH or FTP.
- **Agents**: an MCP server inside the IDE lets Claude Code and other agents
  read your buffers, propose edits you accept or reject, and run your tests.
- **Plugins** in PHP, with a public API; the features above are plugins too.

The UI speaks English, French, German, Spanish, Italian, Japanese and Hebrew
(mirrored right-to-left).

## Install

Porta needs PHP ≥ 8.5.

```sh
brew install janalis/tap/porta            # macOS / Linux
composer global require janalis/porta     # with minimum-stability dev, see below
```

Or download `porta.phar` from the
[releases page](https://github.com/janalis/porta/releases). However it was
installed, `porta --self-update` keeps it current. See
[Getting started](https://janalis.github.io/porta/getting-started) for
Composer's stability settings, a language server, and building from source.

## Quick start

```sh
porta                       # open the project in the current directory
porta src/Kernel.php:42     # open a file at a line
porta --diff a.php b.php    # compare two files
```

Press **F1** inside the IDE for the documentation, rendered in place.

## Plugins

Every bundled plugin is also its own package, `janalis/porta-<name>`, from a
read-only split of [`plugins/`](plugins). Install yours from **Settings →
Plugins** by Packagist name, git URL or local path; see
[Writing a plugin](https://janalis.github.io/porta/plugins).

## Contributing

Bug reports and pull requests are welcome — against this repository, never
a plugin's split. Read the
[contributor guide](docs/internals/README.md) first: `composer verify` (or
`make verify`) is the definition of done, and it holds every change to
100% line coverage.

```sh
make install        # composer install + the docs site's packages
make check          # every quick gate
make verify         # the quick gates + 100% coverage of src/ and plugins
make docs-dev       # the documentation site, live
```

## License

[MIT](LICENSE).
