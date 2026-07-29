# Plugins

Porta is extensible with small PHP plugins: a directory containing a
`plugin.php` that registers commands, keybindings, menu entries and event
hooks through a stable API. Plugins run in-process on the shared event loop
and are trusted code, like composer scripts.

Ten plugins ship **bundled with Porta** ([`plugins/`](../plugins/) in the
package). Bundled plugins are governed by the `enabledPlugins` list; when your
`.porta.json` has no such key, the two viewer plugins (`image-viewer`,
`graph-viewer`) are enabled by default — an explicit list (even an empty one)
replaces that default. Four of them are the documented reference patterns:

| Plugin | What it shows |
| --- | --- |
| `php-cs-fixer` | Async subprocess (`AsyncProcess`), binary discovery, a persisted "Format on save" setting, an extra-args setting |
| `phpstan` | Publishing editor diagnostics (`setDiagnostics`) — inline messages, stripe, Problems panel — with "Analyse on save" |
| `mago` | Both patterns at once: format-in-place + JSON-lint diagnostics, with separate "Format on save" / "Lint on save" settings (both on chains format→lint) |
| `psalm` | The phpstan pattern for Psalm's JSON array output, with "Analyse on save" |

The other six: `image-viewer` and `graph-viewer` (the inline viewers as
routing-claim plugins — disable one and its files open as text instead),
`tempest` (the built-in-quality syntax highlighting engine the Settings ▸
Highlighting page offers), `chroma` (an external-highlighter example), and
`phpantom` / `phpactor` (PHP language-server providers — the Welcome screen's
one-keystroke LSP setup). Every bundled plugin surfaces its commands in the
Plugins menu and its knobs on its own settings page.

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
| Bundled | `<package>/plugins/<name>/plugin.php`, available everywhere (opt-in) |

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

`plugin.php` must **return** an instance of `Porta\Plugin\Api\PortaPlugin`.
Prefer an **anonymous class** — two plugins declaring the same named class
is an uncatchable PHP fatal.

```php
<?php

declare(strict_types=1);

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PortaPlugin;

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

### Base classes

`PortaPlugin` is the contract, but most plugins do the same handful of things,
so four abstract classes in `Porta\Plugin\Api` carry them. Extending one is
optional — the loader only checks `instanceof PortaPlugin` — but a plugin that
shells out to a binary should not hand-roll discovery and a busy flag.

| Class | Gives you |
| --- | --- |
| `AbstractPlugin` | `name()` from the constructor, `claimedLanguages($api)` (a comma-separated `languages` setting, `'*'` for everything) |
| `AbstractToolPlugin` | Everything above plus: `binary($api)` (the `binary` setting → `<root>/vendor/bin/<tool>` → `PATH`; pass `BinarySearch::PathOnly` for a tool composer never installs), `binaryAvailable($api)` for `addMenuItem`'s dim predicate, `acquire($api)`/`release()` (one run at a time, with the "already running" and "not found" flashes), `activeFileOrFlash($api)`, `registerBinarySetting($api, $via)`, `registerToggle(…)`, `flashExit(…)`/`flashCount(…)` |
| `AbstractAnalyzerPlugin` | Everything above plus the whole analysis run — you supply `command()`, `acceptsExitCode()`, `diagnostics()` and optionally `noun()`, and `registerAnalysis($api, 'MyTool', ['ctrl+alt+x'])` wires the command, the on-save toggle, the menu entries and the save hook. Use `analyseWith($api, $binary, $file)` instead of `analyse()` when you already hold the slot — that is how a format-then-lint chain stays one run |
| `AbstractSubprocessHighlighter` | Everything `AbstractToolPlugin` gives, plus a highlighting backend that shells out: `registerSubprocessHighlighter($api, 'MyTool', 'rust,go')` declares the `languages`/`debounce`/`maxBytes` settings and wires it, and the base debounces per language, drops superseded answers, caps concurrent processes and turns the JSON token stream into themed ANSI. You supply `lexerArgs($language)` and `tokenMap()` (type-name prefix => `TokenTypeEnum`, longest prefix first) |

A complete linter, using the analyzer base:

```php
<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;

return new class('mylint', 'mylint') extends AbstractAnalyzerPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'acme/mylint']]);
        $this->registerAnalysis($api, 'MyLint', ['ctrl+alt+x']);
    }

    protected function command(string $binary, string $file): array
    {
        return [$binary, '--json', $file];
    }

    protected function acceptsExitCode(int $code): bool
    {
        return 0 === $code || 1 === $code; // 1 = findings, not a crash
    }

    protected function diagnostics(array $report): array
    {
        return array_map(static fn (array $row): array => [
            'line' => max(0, (int) $row['line'] - 1), // the tool is 1-based
            'message' => (string) $row['message'],
        ], $report['issues'] ?? []);
    }
};
```

The constructor arguments are the plugin name and the binary name; the `binary`
setting row, the install offer, the "Analyse Current File" command, the
"Analyse on save" toggle and both menu entries come from the two calls in
`activate()`.

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

A **synchronous** highlighter needs none of that — call `$deliver` before
returning, as the bundled `tempest` plugin does.

See the shipped reference plugins for complete, tested examples of the
formatter pattern (rewrite the file on disk — the IDE's filesystem poller
reloads clean buffers within a second) and the linter pattern
(`setDiagnostics`).
