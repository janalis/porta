# Debugger

Porta speaks the [Debug Adapter Protocol](https://microsoft.github.io/debug-adapter-protocol/)
over a child process's stdio, so any DAP adapter works — configuration is
per language, no code required.

## Configuring an adapter

Either in **Settings → your language** ("Debug adapter" + "Launch config
(JSON)") or directly in `.porta.json`:

```json
{
    "languages": {
        "php": {
            "debug": {
                "adapter": "node /path/to/vscode-php-debug/out/phpDebug.js",
                "launch": {"port": 9003}
            }
        }
    }
}
```

- `adapter` — the command that starts the DAP adapter (string or argv list).
- `launch` — a JSON object merged into the DAP `launch` request on top of
  the defaults: `program = ${file}`, `cwd = ${workspaceRoot}`,
  `stopOnEntry = false`. The placeholders `${file}` and `${workspaceRoot}`
  are substituted anywhere in your overrides.

Changes apply to the next debug session. For PHP, the
[vscode-php-debug](https://github.com/xdebug/vscode-php-debug) adapter with
Xdebug is a good fit.

## A session

| Key | Action |
| --- | --- |
| `Shift+F9` | Start debugging the current file |
| `Ctrl+F8` (or click the gutter) | Toggle a breakpoint on the caret's line |
| `F8` | Step over |
| `F7` | Step into |
| `Shift+F8` | Step out |
| `F9` | Resume |
| `Ctrl+F2` | Stop |

Breakpoints render as gutter dots and survive buffer switches. The
step/resume/stop keys only capture input while a session runs (paused for
stepping) — otherwise they keep their editor meanings (`F7`/`Shift+F7`
conflict navigation, etc.).

When the program stops, the editor jumps to the stopped line and bands it;
the status bar shows `⏸ file:line` while paused and `▶ debug` while
running.

## The debug panel

A resizable bottom panel opens with the session:

- **Frames** — the call stack; `Enter` or a click jumps to a frame's source
  and loads its scopes.
- **Variables** — scopes with lazily-expanded children; `Enter`/click
  expands one level.

The panel header shows the session state and a stepping-key legend. Program
output streams into a terminal buffer titled `Debug: <file>`.
