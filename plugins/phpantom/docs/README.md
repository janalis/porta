# PHPantom

[PHPantom](https://github.com/PHPantom-dev/phpantom_lsp) is the PHP language
server Porta suggests: completion, diagnostics, navigation and refactoring for
PHP, from one native binary. The Welcome screen sets it up in one keystroke;
otherwise enable it under **Settings → Plugins → phpantom**.

![The phpantom page in Settings → Plugins](/screenshots/plugin-phpantom.svg)

The plugin runs `phpantom_lsp` from its **phpantom_lsp binary** setting when
you set one, otherwise from your `PATH`. While neither resolves, PHP is simply
left unserved and the plugin's settings page offers to install it — through
Homebrew (`brew install phpantom-lsp`) or cargo
(`cargo install phpantom_lsp --locked`). How Porta picks between language
servers is in [Getting started](../../../docs/getting-started.md#language-server).

The same binary is a fixer, too:

- **PHPantom: Fix Current File** (`Ctrl+Alt+M`) fixes the active file and
  applies the result to the buffer, one `Ctrl+Z` away.
- **PHPantom: Fix Project** fixes every file, leaving them to reload from disk.
- **PHPantom: Generate Config** writes a starter configuration
  (`phpantom_lsp init`).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| phpantom_lsp binary | empty | The executable to run; empty finds it on the `PATH` |
| Fix on save | off | Fix each PHP file when you save it (**PHPantom: Toggle Fix on Save**) |

## Keys

| Key | Action |
| --- | --- |
| `Ctrl+Alt+M` | Fix the current file |
