# Configuration

## `.porta.json`

Per-project configuration, read from the project root. All keys are
optional; unknown keys and invalid values are reported as status-bar
warnings at startup and skipped. The IDE persists its own edits (from the
Settings view and palette toggles) back into this file, preserving anything
else in it.

```json
{
    "keymap": {
        "duplicate_line": ["ctrl+shift+d"],
        "php-cs-fixer:fix": ["ctrl+alt+f"]
    },
    "settings": {
        "language": "en",
        "theme": "dark",
        "inlineDiagnostics": true,
        "relativeLineNumbers": false,
        "autoPairs": true
    },
    "languages": {
        "php": {
            "indentStyle": "spaces",
            "indentSize": 4,
            "debug": {
                "adapter": "node /path/to/php-debug-adapter.js",
                "launch": {"runtimeArgs": ["-dxdebug.mode=debug"]}
            }
        }
    },
    "lsp": {
        "php": "vendor/bin/phpactor language-server",
        "typescript": "typescript-language-server --stdio"
    }
}
```

### `keymap`

`action id → key or list of keys`; see [keybindings.md](keybindings.md).
Applied on the next launch. Plugin command ids (containing `:`) bypass the
catalog validation and bind if the plugin loads.

### `settings`

| Key | Default | Effect |
| --- | --- | --- |
| `language` | `"en"` | UI locale: `en`, `fr`, `de`, `es`, `it`, `ja`, `he` (Hebrew mirrors the layout right-to-left) |
| `theme` | `"dark"` | Color theme: `dark`, `light` or `high-contrast` (WCAG-AAA pairings) — swaps chrome and syntax colors live |
| `inlineDiagnostics` | `true` | Dimmed end-of-line diagnostic messages |
| `relativeLineNumbers` | `false` | Relative gutter numbering |
| `autoPairs` | `true` | Auto-closing brackets and quotes |
| `formatOnSave` | `false` | Format via the language server before each Ctrl+S save (single-file saves only; a 2s timeout saves unformatted with a flash) |
| `cursorShape` | `"bar"` | Hardware cursor shape: `bar`, `block` or `underline` — screen readers follow the hardware cursor |

All of these apply live when changed from the Settings view or the palette
toggles (`Toggle Inline Diagnostics`, `Toggle Relative Line Numbers`,
`Toggle Auto-Pairs`).

### `languages.<id>`

Per-language overrides for: `php`, `javascript`, `typescript`, `python`,
`json`, `css`, `scss`, `html`, `markdown`, `yaml`, `twig`.

- `indentStyle` — `"auto"` (detect from the file), `"spaces"`, or `"tabs"`.
- `indentSize` — `"auto"` or a positive integer.
- `debug` — the DAP adapter for this language; see [debugger.md](debugger.md):
  - `adapter` — the adapter command (string or argv list),
  - `launch` — a JSON object merged into the DAP launch request.

### `plugins` and `disabledPlugins`

Plugin-registered settings persist per plugin, and disabled plugins are
listed by name (bundled plugins can be disabled, never removed):

```json
{
    "plugins": {
        "phpstan": {"binary": "/opt/phpstan/phpstan"}
    },
    "disabledPlugins": ["psalm"]
}
```

Both are normally written by **Settings → Plugins → <plugin>**; see
[plugins.md](plugins.md).

### `lsp`

Either a single server command for everything:

```json
{"lsp": "php phpactor.phar language-server"}
```

or a per-language map (as in the large example above). Editing a language's
LSP server in the Settings view converts the single-command form to a map,
keeping the original command under the `*` key. A `--lsp` command-line
override wins over both.

## The Settings view (`Ctrl+,`)

A full-screen view with a resizable sidebar: **General**, **Keybindings**,
**Plugins**, and one section per language.

- **General** — the `settings` table above, edited live (theme and language
  switch immediately, including a full RTL re-layout for Hebrew).
- **Keybindings** — every action with its current binding; `Enter` arms a
  capture, the next keypress becomes the binding (persisted to `.porta.json`,
  applies on restart).
- **Plugins** — install (git URL or local path, project or global scope) and
  remove plugins; see [plugins.md](plugins.md).
- **Languages** — indent style/size, the LSP server command (live: the pool
  is reconfigured and buffers re-opened), and the debug adapter + launch
  config (next debug session).

## `.editorconfig`

A file's nearest `.editorconfig` (resolved by walking up to the first
`root = true`) is always honored:

- live: `indent_style`, `indent_size`, `tab_width`
- on save: `end_of_line`, `trim_trailing_whitespace`, `insert_final_newline`
- `charset` produces a warning only — the editor is UTF-8 only

The per-language indent settings (Settings → Languages) supersede it: a
language whose indent style/size is not `auto` wins over the file's
`.editorconfig` for that field.

## Project-local state: `.porta/`

The IDE writes a `.porta/` directory into the project you open:

| Path | Contents |
| --- | --- |
| `.porta/session.json` | Open buffers and cursor positions, restored when you launch without a file argument |
| `.porta/layout.json` | Pane proportions (sidebar widths, bottom-panel height, git view splits) — hand-editable |
| `.porta/history/` | Local-history snapshots (see below) |
| `.porta/plugins/` | Installed project-scope plugins |

Add it to your project's `.gitignore` (keep `plugins/` if you want to
version your project plugins):

```gitignore
/.porta/
```

Local history snapshots every save (capped at 25 per file) independently of
git; restore any snapshot with `Ctrl+Alt+H`.

## Environment variables

| Variable | Effect |
| --- | --- |
| `PORTA_LSP` | LSP server command (same format as the `lsp` config key) |
| `PORTA_HOME` | Overrides the home directory used for global plugins (`~/.porta/plugins`) |
