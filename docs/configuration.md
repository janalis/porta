# Configuration

Porta reads two files, and the second wins:

| Scope | File | For |
| --- | --- | --- |
| Global | `~/.porta/config.json` | your preferences, on every project |
| Project | `<root>/.porta.json` | the team's, committed with the repo |

Both use the same format, described below. The global file is optional and
does not exist until something writes it. Neither is required.

`PORTA_HOME` overrides `HOME` when resolving the global file, which is also
how the global plugin directory is found — the two are siblings.

## How the two are merged

Per key for `settings`, `plugins`, `lsp` and `highlight.languages`: setting your
theme globally and your indentation per project is the ordinary case.

Three blocks are deliberately coarser, because a partial merge of one would
produce a configuration nobody wrote:

- **`keymap`** merges per ACTION. A project naming `save` replaces that action's
  whole chord list rather than adding to it.
- **`languages.<lang>.debug.launch`** replaces whole. A launch configuration is
  something an adapter either understands or does not.
- **`enabledPlugins`** is taken from the nearest file that names it, and an
  empty list counts as naming it. This is what lets a project turn off a
  bundled plugin that your global file turned on.

**`disabledPlugins` is the one that unions.** Both files are saying "not this
one", and an override that silently re-enabled a plugin you turned off globally
would be the opposite of what either file asked for.

A warning names the file it came from, so a mistake in one is not reported
against the other.

## Which file the Settings screen writes

Every page that persists something leads with a **Save changes to** row. It is
a session choice, not a stored setting — there is no good file in which to
store "which file".

It starts on the global file, so a preference does not dirty a repository. The
exception: if the project's `.porta.json` already has a `settings` block, it
starts on the project instead. Your next edit landing somewhere new while the
old values sit visibly in that file, still overriding it, is the surprising
outcome — not the safe one.

The same choice decides where a plugin is installed, because it is the same
question: are you configuring this project, or yourself?

## Where a shown value came from

Rows carry a dim tag when the answer is not obvious:

- **`from global`** — the value comes from `~/.porta/config.json`, which is not
  the file you are editing if the scope row says otherwise.
- **`overrides global`** — the project file sets this, and a *different* value
  sits in the global file underneath it.

Nothing is tagged in the ordinary case: a value the project sets and nothing
else, or a value nothing sets at all. A tag on every row is a tag on no row.

## When a change will not take effect here

Writing into a key the other file also sets is allowed — it is a correct write
to the file you chose — but it may change nothing you can see, because the
merge rules above decide which layer wins. Rather than refuse the edit or
report a success that is not one, Porta says so:

| You did | And | It says |
| --- | --- | --- |
| Saved to the global file | the project sets that key | *Saved to ~/.porta/config.json — .porta.json overrides it in this project* |
| Pressed `r` on a global value | the project still sets that key | *Cleared from ~/.porta/config.json — .porta.json still overrides it here* |
| Enabled a plugin | the other file lists it in `disabledPlugins` | *&lt;plugin&gt; stays off — &lt;file&gt; disables it* |
| Rebound an action | the other file binds that action | *&lt;action&gt; keeps its binding — &lt;file&gt; sets it* |

In the last two the operation reports nothing else — no "enabled", no
"rebound" — because it did not happen.

Refusing these edits was considered and rejected: setting a personal default
for a key that some open project happens to pin is a reasonable thing to do,
and refusing would make the global value unreachable from inside the project
that shadows it.

## Turning bundled plugins on and off

`enabledPlugins` is an explicit list, so the first write to a file that has
none has to invent one. It starts from **the list already in effect**, not from
the shipped default — otherwise turning one plugin off in a project would
silently turn back on every plugin you had turned off globally.

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
        "diagnosticMessages": "inline",
        "inlayHints": "tooltip",
        "lineNumbers": "absolute",
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
| `diagnosticMessages` | `"inline"` | Where a diagnostic's message shows: `none`, `inline` (dimmed after the line's code) or `menu` (at the top of the Alt+Enter quick-fix list). The squiggle, gutter mark, stripe and Problems panel show in every mode. Replaces `inlineDiagnostics` (`true` → `inline`, `false` → `none`), which still loads and is retired the first time this one is written |
| `inlayHints` | `"inline"` | Where the language server's inlay hints (parameter names, types, reference counts) show: `none` (not requested at all), `inline` (dim text in the line) or `tooltip` (a card when the pointer rests on the word) |
| `lineNumbers` | `"absolute"` | Gutter numbering: `off`, `absolute` or `relative`. Replaces `relativeLineNumbers`, which still loads and is retired the first time this one is written |
| `autoPairs` | `true` | Auto-closing brackets and quotes |
| `breadcrumbs` | `true` | The scope path bar above the editor |
| `stickyScroll` | `true` | Pin the enclosing scope headers to the top while scrolling |
| `indentGuides` | `true` | Vertical rules at each indent level |
| `methodSeparators` | `false` | A rule between members of a class |
| `showWhitespace` | `false` | Render spaces and tabs as glyphs |
| `tabBarPosition` | `"top"` | Where the editor tab strip sits: `top`, `bottom`, `left` or `right` |
| `searchIncludeVendor` | `false` | Let Find in Files and Go to File descend into `vendor/` |
| `formatOnSave` | `false` | Format via the language server before each Ctrl+S save (single-file saves only; a 2s timeout saves unformatted with a flash) |
| `cursorShape` | `"bar"` | Hardware cursor shape: `bar`, `block` or `underline` — screen readers follow the hardware cursor |
| `tooltips` | `true` | A card saying what a glyph, a toggle or a cut name means when the pointer rests on it |
| `tooltipDelay` | `500` | How long the pointer rests before a tooltip shows, in milliseconds (0–2000; the next one shows at once while a card is up) |

All of these apply live when changed from the Settings view or the palette
commands (`Cycle Diagnostic Messages`, `Cycle Inlay Hints`,
`Cycle Line Numbers`, `Toggle Auto-Pairs`).

`relativeLineNumbers` and `inlineDiagnostics` are the two keys here that are
no longer current: a `.porta.json` still carrying one is read without
complaint, and writing its successor (`lineNumbers`, `diagnosticMessages`)
from the Settings view retires it.

### `languages.<id>`

Per-language overrides for: `php`, `javascript`, `typescript`, `python`,
`json`, `css`, `scss`, `html`, `markdown`, `yaml`, `twig`, `xml`, `dotenv`.

`dotenv` is the one id matched by NAME rather than extension: any file whose
name starts with `.env`, except `.env.local.php` and its siblings, which are
real PHP.

- `indentStyle` — `"auto"` (detect from the file), `"spaces"`, or `"tabs"`.
- `indentSize` — `"auto"` or a positive integer.
- `debug` — the DAP adapter for this language; see [debugger.md](debugger.md):
  - `adapter` — the adapter command (string or argv list),
  - `launch` — a JSON object merged into the DAP launch request.
- `templates` — your own **live templates**: abbreviation => LSP snippet body,
  layered over the built-in set. See [editor.md](editor.md#live-templates).

```json
{
    "languages": {
        "php": {
            "templates": {
                "dd": "dd($1);$0",
                "logi": "$this->logger->info(${1:'message'}, [$0]);",
                ".dd": "dd({{expr}});$0",
                "fore": ""
            }
        }
    }
}
```

An abbreviation starting with a dot is a **postfix** template: `{{expr}}` is
replaced by the expression to the left of the dot. An entry whose body is the
**empty string removes** a built-in — the only way to stop a shipped
abbreviation expanding over something you type as ordinary code, since a map has
no settings row. Templates merge per abbreviation across config scopes, so a
project adding one keeps the set you defined globally.

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

A full-screen view with a resizable sidebar: **General**, **Interface**,
**Highlighting**, **Keybindings**, **Command line**, **Plugins**, and one
section per language.

- **General** — the `settings` table above, edited live (theme and language
  switch immediately, including a full RTL re-layout for Hebrew).
- **Keybindings** — every action with its current binding; `Enter` arms a
  capture, the next keypress becomes the binding (persisted to `.porta.json`
  and applied live — IDE-level dispatch and menu shortcut labels re-merge
  immediately).
- **Command line** — Porta outside the IDE, each installed, updated and
  removed from here: the `porta` command (a symlink, `~/.local/bin/porta`
  unless one is already on your `PATH`; a `porta` put there some other way is
  shown and left alone), Porta as [git's difftool and
  mergetool](git.md#as-gits-difftool-and-mergetool), and — for the phar —
  updating Porta itself (`--self-update`, run in the background). A removal
  takes two presses.
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
| `.porta/recent-files.json` | The files this project has had open, newest first, capped at 32 — what `Ctrl+E` offers below the open tabs |
| `.porta/layout.json` | Pane proportions (sidebar widths, bottom-panel height, git view splits) — hand-editable |
| `.porta/history/` | Local-history snapshots (see below) |
| `.porta/reviews/` | Local code reviews an agent writes and you answer — see [review.md](review.md#local-reviews-the-format-an-agent-writes) |
| `.porta/plugins/` | Installed project-scope plugins |
| `enabledPlugins` (in `.porta.json`) | Bundled plugins to load. When the key is ABSENT, `image-viewer`, `graph-viewer`, `todo` and `local-history` are on by default; an explicit list (even `[]`) replaces that default entirely, so a list written before one of them existed silently leaves it off — TODO scanning and local-history snapshots are the two that go quiet |

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
| `PORTA_HOME` | Overrides the home directory used for global plugins (`~/.porta/plugins`) and scratch files (`~/.porta/scratches`) |
