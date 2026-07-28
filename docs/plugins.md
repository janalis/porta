# Plugins

Porta is extensible with small PHP plugins: a directory containing a
`plugin.php` that registers commands, keybindings, menu entries and event
hooks through a stable API. Plugins run in-process on the shared event loop
and are trusted code, like composer scripts.

Four complete plugins ship **bundled with Porta** and are available in every
project ([`.porta/plugins/`](../.porta/plugins/) in the package):

| Plugin | What it shows |
| --- | --- |
| `php-cs-fixer` | Async subprocess (`AsyncProcess`), binary discovery, a persisted "Format on save" setting, an extra-args setting |
| `phpstan` | Publishing editor diagnostics (`setDiagnostics`) — inline messages, stripe, Problems panel — with "Analyse on save" |
| `mago` | Both patterns at once: format-in-place + JSON-lint diagnostics, with separate "Format on save" / "Lint on save" settings (both on chains format→lint) |
| `psalm` | The phpstan pattern for Psalm's JSON array output, with "Analyse on save" |

The on-save options are persisted bool settings (each plugin's Settings page),
also flippable live via the palette's "Toggle … on Save" commands. Their menu
entries stay dimmed until the matching tool binary is resolvable (vendor/bin,
PATH, or the plugin's `binary` setting); the Plugins menu groups each plugin's
entries with separators.

## Where plugins live

| Scope | Path |
| --- | --- |
| Project | `<project>/.porta/plugins/<name>/plugin.php` |
| Global | `~/.porta/plugins/<name>/plugin.php` (`PORTA_HOME` overrides the home directory) |
| Bundled | shipped inside the Porta package, available everywhere |

All three sources are scanned at startup, in that precedence order: a project
plugin shadows a global one of the same directory name, and both shadow a
bundled one — so you can override a bundled plugin by installing your own
under the same name.

## Installing, removing, disabling

Open **Settings → Plugins** and use the form:

- **Install into** — choose the project or the global directory.
- **Install plugin** — type a **git URL** (cloned with `git clone --depth 1`)
  or a **local directory path** (copied recursively). The directory's
  basename becomes the plugin directory name.
- Each installed plugin lists with a **[Remove]** button — press Enter twice
  (a confirm flash arrives after the first press) to delete its directory.
  Bundled plugins list as **(built-in)** and cannot be removed.

A successful install **activates the plugin immediately**: its commands
appear in the command palette and the Plugins menu (between Code and Run)
right away, and its default keybindings dispatch at once (the live keymap
re-merges on every plugin toggle). **Removal deactivates the plugin live** —
its menu entries, palette commands, key dispatch and hooks all die
immediately, and the same name can be reinstalled without a restart. When a
removed project plugin was shadowing a global or bundled copy of the same
name, that copy reactivates in its place.

Every plugin — bundled ones included — also has an **Enabled** toggle at the
top of its settings page (each installed plugin appears as a sub-item under
Plugins in the settings sidebar). Disabling deactivates it live and persists
as a `disabledPlugins` list in `.porta.json`, so it stays off across
restarts until re-enabled.

You can always skip the UI and copy/delete plugin directories by hand; they
are picked up on the next launch.

## Writing a plugin

`plugin.php` must **return** an instance of `Porta\Plugin\PortaPlugin`.
Prefer an **anonymous class** — two plugins declaring the same named class
is an uncatchable PHP fatal.

```php
<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;

return new class implements PortaPlugin {
    public function name(): string
    {
        return 'hello'; // /^[a-z0-9][a-z0-9_-]*$/i
    }

    public function activate(PluginApi $api): void
    {
        $api->registerCommand('greet', 'Hello: Greet', static function () use ($api): void {
            $api->flash('Hello from a plugin, '.basename($api->projectRoot()));
        }, ['ctrl+alt+y']);
        $api->addMenuItem('greet');

        $api->onFileSaved(static function (string $path) use ($api): void {
            $api->flash(basename($path).' saved');
        });
    }
};
```

Command ids are namespaced as `<plugin>:<command>` (`hello:greet` above),
appear in the command palette, and can be rebound by users in `.porta.json`
like any core action. Core commands win key-sequence collisions; a user
keymap entry beats a plugin's default key.

### Lifecycle

- Plugins load during startup, **before** the keybinding map is built, so
  their default keys merge into it.
- Registrations buffer inside `activate()` and commit **atomically** — if
  `activate()` throws, the plugin contributes nothing and a warning is
  flashed.
- Action methods (flash, editor access, `openFile`, …) are unavailable
  during `activate()` and throw a `PluginException`; call them from command
  handlers and hooks. `projectRoot()` is the exception — it is static
  configuration and safe anywhere.
- Every handler and hook is wrapped in a catch-all: a throwing plugin
  flashes `Plugin "<name>" failed: <message>` in the status bar instead of
  crashing the IDE.

### `PluginApi` reference

Registration (during `activate()`):

| Method | Purpose |
| --- | --- |
| `registerCommand(string $id, string $label, \Closure $handler, array $keys = [])` | Register a command; `$keys` are default keybindings (e.g. `['ctrl+alt+y']`) |
| `addMenuItem(string $commandId, ?\Closure $enabledWhen = null)` | Add the command to the **Plugins** menu; with `$enabledWhen`, the item renders dimmed while the closure returns false (re-evaluated per render; a throw reads as disabled) |
| `registerSetting(string $key, string $label, string $kind = 'text', $default = '', ?array $options = null, ?string $hint = null)` | Declare a user-editable setting shown on the plugin's settings page; kinds: `text`, `bool`, `int`, `choice` (with `$options`). Values persist under `plugins.<name>.<key>` in `.porta.json` |
| `onFileOpened(\Closure $hook)` | `$hook(string $path)` after a file opens |
| `onFileSaved(\Closure $hook)` | `$hook(string $path)` after a buffer saves |
| `onBufferSwitched(\Closure $hook)` | `$hook(string $path)` when the active buffer changes |

Actions (from handlers/hooks):

| Method | Purpose |
| --- | --- |
| `flash(string $message, float $seconds = 2.5)` | Status-bar notification |
| `activeFile(): ?string` | Absolute path of the focused buffer |
| `documentText(): ?string` | Full text of the focused buffer |
| `caretPosition(): ?array` | `['line' => …, 'column' => …]` (0-based) |
| `insertAtCaret(string $text)` | Insert at the caret |
| `openFile(string $path)` | Open a file in the editor |
| `jumpTo(int $line, int $column = 0)` | Move the caret (0-based) |
| `setDiagnostics(string $path, array $diagnostics)` | Publish editor diagnostics; each item is `['line' => int (0-based), 'message' => string, 'severity' => 1–4 (optional, 1 = error), 'column' => int (optional)]`. An empty list clears. |
| `projectRoot(): string` | The project root (also safe during `activate()`) |
| `setting(string $key): bool\|int\|string` | The current value of a setting this plugin registered — the user's value coerced to the declared kind, or the default. Safe during `activate()`, but `registerSetting()` must run first. |
| `changeSetting(string $key, bool\|int\|string $value)` | Persist a new value for a setting this plugin registered — coerced, kept live and written to `.porta.json`, exactly like a Settings-page edit. An action method (throws during `activate()`). |

Diagnostics published via `setDiagnostics` behave exactly like LSP
diagnostics — inline end-of-line messages, the scroll-stripe, and the
Problems panel — and the two sources replace each other per file (last
writer wins).

### Running external tools

Use `Porta\Support\AsyncProcess` so the UI never blocks:

```php
use Porta\Support\AsyncProcess;

new AsyncProcess([$binary, 'lint', '--json', $file], $api->projectRoot())
    ->start(static function (int $exitCode, string $stdout) use ($api): void {
        // parse $stdout, then $api->setDiagnostics(...) / $api->flash(...)
    });
```

See the shipped reference plugins for complete, tested examples of the
formatter pattern (rewrite the file on disk — the IDE's filesystem poller
reloads clean buffers within a second) and the linter pattern
(`setDiagnostics`).
