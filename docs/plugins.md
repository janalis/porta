# Plugins

Porta is extensible with small PHP plugins: a directory containing a
`plugin.php` that registers commands, keybindings, menu entries and event
hooks through a stable API. Plugins run in-process on the shared event loop
and are trusted code, like composer scripts.

Fourteen plugins ship **bundled with Porta** ([`plugins/`](../plugins/) in the
package). Bundled plugins are governed by the `enabledPlugins` list; when your
`.porta.json` has no such key, a small default set (the two viewers plus
`todo`, `local-history` and `git`) is enabled — an explicit list (even an
empty one) replaces that default. Four of them are the documented reference
patterns:

| Plugin | What it shows |
| --- | --- |
| `php-cs-fixer` | Async subprocess (`AsyncProcess`), binary discovery, a persisted "Format on save" setting, an extra-args setting |
| `phpstan` | Publishing editor diagnostics (`setDiagnostics`) — inline messages, stripe, Problems panel — with "Analyse on save" |
| `mago` | Both patterns at once: format-in-place + JSON-lint diagnostics, with separate "Format on save" / "Lint on save" settings (both on chains format→lint) |
| `psalm` | The phpstan pattern for Psalm's JSON array output, with "Analyse on save" |

The others: `image-viewer` and `graph-viewer` (the inline viewers — each
owns its whole implementation and PROVIDES its pane; disable one and its files
open as text instead, and note that `graph-viewer` requires `image-viewer`
because a graph is painted with the same rasterizer),
`tempest` (the built-in-quality syntax highlighting engine the Settings ▸
Highlighting page offers), `chroma` (an external-highlighter example),
`phpantom` / `phpactor` (PHP language-server providers — the Welcome screen's
one-keystroke LSP setup), `symfony-lsp` (the official Symfony language server,
serving twig, yaml, xml, json and dotenv — and PHP when neither PHP server is
enabled — with runtime indexing as an explicit opt-in setting; it ships only
as a GitHub-Releases binary, so the plugin's "Symfony LSP: Install Server"
command downloads, checksum-verifies and lands it in `~/.porta/bin` for every
project to share, and the Welcome screen offers it on projects that require
`symfony/framework-bundle`), and `todo`,
`local-history` and `git` (core features that moved out, keeping their ids by
adoption). Every bundled plugin surfaces its commands in the Plugins menu and
its knobs on its own settings page.

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

A plugin directory may hold more than the entry file: an optional
`composer.json`, and — with one — a `src/` of its own classes and a `vendor/`
of packages it depends on (see below).

All three sources are scanned at startup, in that precedence order: a project
plugin shadows a global one of the same directory name, and both shadow a
bundled one — so you can override a bundled plugin by installing your own
under the same name.

## `composer.json`: your own classes, and what you depend on

A plugin may ship a `composer.json` beside its `plugin.php` — composer's own
file, because everything a plugin needs to declare, composer already has a field
for. Every field is optional, and a plugin without one behaves exactly as it did
before there was one.

```json
{
    "name": "acme/tools",
    "require": { "php": ">=8.4" },
    "autoload": {
        "psr-4": { "Acme\\Tools\\": "src/" }
    },
    "extra": {
        "porta": { "requires": ["image-viewer"] }
    }
}
```

- **`autoload.psr-4`** — prefixes and the directories they map to, relative to
  the plugin. `Acme\Tools\Greeting` then loads from `<plugin>/src/Greeting.php`.
  Porta's own composer.json maps only `Porta\ => src/` and cannot be edited by a
  plugin you install, so this is how a plugin ships classes of its own; the
  mapping is registered **before** your `plugin.php` is required, so your classes
  resolve from its first line. A prefix may name several directories, and `"."`
  means the plugin's own root.
- **`extra.porta.requires`** — plugin **names** (directory names) that must load,
  and load **first**. A plugin whose requirement is missing or disabled does not
  activate, and says which requirement in a startup warning. Dependency cycles
  are reported rather than resolved: no member of a cycle loads, because picking
  one to go first would make the bug intermittent.

  They cannot go in composer's own `require`: those are plugin directory names,
  and composer would go looking for them on Packagist.
- **`require`'s platform half is checked, not ignored.** A `php` constraint the
  running PHP does not satisfy, or an `ext-*` that is not loaded, declines the
  plugin **by name at startup** ("needs PHP >=8.5 (this is 8.4.1) — not
  loaded") instead of loading it and fataling on the first missing symbol,
  somewhere far from the cause. Declare what you actually use. A constraint
  Porta cannot parse is trusted rather than refused, and `php-64bit`/`lib-*`
  are not checked.
- **`require`'s package half** behaves like composer's: a `vendor/name`
  package Porta does not ship makes the plugin count as needing
  `composer install`, which Porta runs for you on install/enable. A package
  Porta **does** ship (the symfony components, `tempest/highlight`) asks for
  nothing — the host's copy is the one your code gets either way — so declare
  what you import: it costs nothing and documents reality.
- **`extra.porta.api`** — the plugin-API **major** you wrote against, an
  integer (currently `1`). Optional: undeclared trusts the current API. When a
  future Porta breaks the `PluginApi` surface it will bump the major, and a
  plugin pinning the old one is declined by name instead of failing somewhere
  inside a render pass. Additive API growth never bumps it.
- **`name`** is not read by Porta. A plugin's identity is its **directory name**
  everywhere it matters — `enabledPlugins`, `disabledPlugins`, `requires`, and
  its `plugins.<name>.<key>` settings. Give it one anyway so `composer install`
  inside the plugin doesn't warn.

Three of composer's autoload modes are **not** read from your manifest, and each
says so at boot rather than failing silently later:

| Mode | What happens |
| --- | --- |
| `psr-0` | Not read. It is dead in composer too; use `psr-4`. |
| `classmap`, `files` | Only ever read from a *dumped* map, so declaring one makes your plugin count as needing `composer install` (see below) — after which it works. |
| the empty prefix (`"": "src/"`) | Refused. `str_starts_with($class, '')` is true for every class, so that one plugin would be consulted for every unresolved name in the IDE. |

The manifest is read before any plugin PHP is executed — it has to be, since
`requires` decides load order and load order cannot be discovered by loading.

Enabling a plugin from **Settings → Plugins** enables what it requires along
with it, transitively, and says so ("Also enabled: …"); disabling one disables
whatever required it ("Also disabled: …"), since those cannot work without it.
Startup does **not** rewrite your config that way: a launch that silently
re-enabled things you turned off would be a worse surprise than a message.

> **Upgrading from `plugin.json`.** It is no longer read. `namespace` +
> `autoload` become `autoload.psr-4`, and `requires` becomes
> `extra.porta.requires`. A plugin that still has one loads, with a startup
> warning naming it — but its classes will not resolve until you move them.

## Depending on a composer package

The same file declares what your plugin depends on, and Porta installs them for
you. A plugin whose `require` names a real package — as opposed to `php` or an
`ext-*`, which install nothing — gets `composer install --no-dev` run **inside
its own directory**, never in your project, where a plugin's libraries have no
business.

That happens at three moments, all of them the same run:

| When | What you see |
| --- | --- |
| You install the plugin | the usual install report |
| You **enable** it | `Installing dependencies for "x"…`, then `Plugin "x" enabled` when it lands |
| **Startup**, for a plugin that is enabled but has no `vendor/` | the same two, once the IDE is up |

Enabling a plugin that requires others installs them **one at a time and
requirements first** — a plugin must not load while something it needs is still
installing. If a composer run fails, or there is no composer on the machine, the
plugin stays enabled and says what went wrong; it is not activated (loading it
would fail on the first missing symbol) and the next startup tries again. The
checkbox never flips itself back: the enable is what you asked for, and a failed
download is a fact about the machine.

**Disabling a plugin removes the `vendor/` Porta installed** — and only that
one. A successful install leaves a `vendor/.porta-installed` marker, and without
it nothing is deleted, so a plugin that ships or commits its own `vendor/` keeps
it. Your `composer.lock` is never touched. The trade is worth knowing: turning a
plugin off and on again now costs a fresh install, network and all.

The resolution rule matters, because it decides what happens when your plugin
and Porta want the same package:

> **Porta's copy always wins.** Porta reads composer's *generated maps*
> (`vendor/composer/autoload_psr4.php` and `autoload_classmap.php`) and serves
> them from its own autoloader, which sits **behind** Porta's. It never runs
> your `vendor/autoload.php` — composer registers that one with *prepend*, so
> requiring it would let any plugin replace Porta's dependencies for the whole
> process and break the IDE rather than the plugin.

So a package Porta itself ships (`symfony/*`, `tempest/highlight`, …) resolves
to **Porta's version** whatever you pinned. Porta names each one in a startup
warning so the mismatch is visible rather than turning up as a `TypeError` in
something unrelated. If you need a different major of a package Porta ships,
you cannot have it — vendor the logic you need instead.

Two of composer's autoload modes are **not supported**, and a package that
needs either will simply fail to resolve:

- **PSR-0** (`autoload_namespaces.php`) — effectively dead, and not read.
- **`files`** (`autoload_files.php`) — side-effect includes are not executed,
  because running arbitrary plugin code at discovery time is exactly the
  hazard the whole arrangement avoids.

A plugin that declares packages (or a `classmap`/`files` autoload) and has no
`vendor/` is not loaded at the moment startup reads it — its classes are not
there yet — but it is installed and activated a moment later, and anything
waiting on it comes up with it. A `require` of only `php` or `ext-*` asks for
nothing, so a manifest that exists purely to declare `autoload.psr-4` never
triggers any of this.

## Putting a command in a menu

`addMenuItem()` takes an optional surface:

```php
$api->registerCommand('audit', 'Audit This File', $handler);
$api->addMenuItem('audit');                                     // the Plugins menu
$api->addMenuItem('audit', surface: MenuSurface::EditorContext); // right-click in the editor
$api->addMenuItem('audit', surface: MenuSurface::FileContext);   // right-click a tab or a tree row
```

Plugin items always come **last** in a context menu, grouped behind a
separator: the menu is core's, and a plugin must not push Cut/Copy/Paste down
the list.

The file surface is the one that names a path, so a command there must ask
**`$api->contextFile()`** rather than `activeFile()` — right-clicking
`Other.php` in the tree while you are editing `App.php` has to answer about
`Other.php`. `contextFile()` falls back to the active file, so the same command
still does the right thing from a keystroke or the palette.

A `FileContext` item is offered on file tree rows only, never on directories,
and an `EditorContext` item is reachable only while the code editor holds the
pane — a viewer tab (an image, a `.dot` graph) detaches that widget, so a
command scoped to a viewer belongs in the Plugins menu instead.

## Listening to core events

The six `on*` hooks above are sugar. Underneath, a plugin's listeners are
ordinary `symfony/event-dispatcher` listeners on Porta's own bus, and `on()`
reaches the rest of what you may observe:

```php
$api->on(GitCommitted::class, function (GitCommitted $event) use ($api): void {
    $api->flash('committed');
});
```

A plugin may also **announce** one, with the same set and the same marker:

```php
$api->emit(new WorkingTreeChanged());
```

`emit()` takes an event OBJECT, so the marker is enforced by the type system —
and a plugin never holds the dispatcher, which is what keeps the internal
events out of reach. Core listeners run first; other plugins hear it after.
It is an action method, so it throws during `activate()`.

Only these events are open to plugins. The rest of `Porta\Event` is internal
and changes without notice — a marker interface, `PluginObservable`, is what
says which is which, and naming an unmarked class throws rather than quietly
never firing.

| Event | Carries |
| --- | --- |
| `IdeReady` | `string $projectRoot` — layout applied, services wired, no buffer open yet |
| `IdeShuttingDown` | `string $projectRoot` — services alive, event loop already stopped |
| `ActiveBufferChanged` | `string $path` — the editor is showing a different file |
| `ThemeChanged` | `string $theme` |
| `LocaleChanged` | `string $locale` |
| `GitCommitted` | — a commit was made from the git view |
| `GitRemoteCompleted` | `string $operation` (`fetch`/`pull`/`push`), `bool $ok` |
| `WorkingTreeChanged` | — the working tree moved under version control: a stage, stash, commit, reset or pull |
| `FileRenamed` | `string $from`, `string $to` — both absolute and canonical; `$from` is already gone from disk. A DIRECTORY carries its whole tree, so anything you key by path should ask `ProjectPaths::moved()` rather than compare |
| `FileRewritten` | `string $path`, `string $previousText` — a refactoring rewrote a file that had no open buffer. The payload is what the write REPLACED: the new content is on disk already, and the old exists nowhere else. Not a save, and no `BufferSaved` fires for it |

Four rules govern the list, and they are enforced by a test rather than by
convention:

- **Payloads are scalars or backed enums.** No core objects: `BufferOpened`
  carries a `Buffer`, which is a mutable bag of editor state, so it is *not*
  observable — `onFileOpened()` still works because a core-written adapter
  hands your closure the path and nothing else.
- **Every observable event is listed here**, and the test fails if one is not.
- **The set is pinned.** Adding an event to it is a deliberate, reviewed change.
- **Listeners run after every priority-0 core listener**, so you see the state
  core already reacted to. (One core listener deliberately runs *after* you:
  the highlight-cache drop, so a backend that bakes theme colours rebuilds
  first.)

Register during `activate()`. A listener added later — from a command handler,
say — throws, because registrations are committed atomically when your plugin
loads and one arriving afterwards would silently never fire.

Plugins **listen**; they never dispatch. There is no way to put an event on the
bus from a plugin, and that is deliberate.

## Installing, removing, disabling

Open **Settings → Plugins** and use the form:

- **Install into** — choose the project or the global directory.
- **Install plugin** — type a **`vendor/name` package** (installed straight
  from Packagist), a **git URL** (peeked to learn its package name and
  branch, then required from a `vcs` repository), or a **local directory
  path** (required from a `path` repository, which composer symlinks — so a
  plugin you're actively developing is picked up live, no reinstall needed
  on edit). Every source kind is resolved by a Porta-owned Composer project
  at `.porta/composer.json` (or the global equivalent), and
  `.porta/plugins/<name>` ends up a **symlink** into wherever composer
  actually installed the package. The directory name is the package's own
  `composer.json` — it must declare `"name": "vendor/name"` and
  `"type": "porta-plugin"`, or the install is refused.
- Each installed plugin lists with a **[Remove]** button — press Enter twice
  (a confirm flash arrives after the first press). A composer-managed entry
  runs `composer remove` in the driving project first, then unlinks the
  symlink — the real installed copy under `.porta/vendor/` is untouched by
  anything else that references it. Bundled plugins list as **(built-in)**
  and cannot be removed.

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

**Nothing about a toggle needs a restart**, including the surfaces that used
to: a plugin enabled for the first time in a session gets its panes built,
its `registerView()` view minted and mounted, and its `registerMenu()`
top-level menu added; disabling closes an open contributed view (the editor
takes the workspace back), un-mints its region and drops its menu. Two
caveats, both by design: a **tab already open** in your pane keeps rendering
in it after a disable — only removal drops the pane — and the highlighting
backend a **buffer already open** resolved stays put, so a highlighter toggle
applies to files opened afterwards. If your plugin starts timers or
subprocesses of its own, implement `DisposablePlugin` (see Lifecycle below);
nothing else can stop them for you.

You can still hand-place a plugin directory (or one a previous Porta version
git-cloned/copied) directly under `.porta/plugins/<name>` — it is picked up
on the next launch and removes with a plain directory delete, exactly as
before. That legacy path coexists with composer-managed installs; a plugin
only becomes composer-managed by going through the install form above.

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

### Providing a pane

A plugin can own the editor area for the files it claims — that is what the
bundled image and graph viewers are. Two calls: one says which files are yours,
the other says what shows them.

```php
use Porta\Editor\PaneServices;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\NavigablePaneWidget;

$api->registerViewer('hex', ['bin', 'dat']);
$api->registerPane(
    'hex',
    static fn (PaneServices $pane): EditorPaneWidget => new HexPane($pane->styles, $pane->translator),
    static fn (string $path): string => (string) file_get_contents($path),
);
```

- **The factory** runs once, at startup, after every plugin has activated. It is
  handed a `PaneServices`: the live `Styles` holder (a theme switch swaps its
  contents in place, so a pane that reads it per render follows along), the
  `Translator`, a `Scheduler` for debouncing expensive re-renders, and the
  `ViewerTabHandler` your pane calls for tab cycling, close, go-to-file and the
  "my view moved" ping that refreshes the status bar. That is the whole list —
  a pane is hand-built, because a plugin class cannot be a container service.
- **The loader** runs on every open of a claimed path and returns whatever your
  pane understands — decoded pixels, source text, a parsed model. The buffer
  carries it untouched; the IDE never looks inside. **Throw a `RuntimeException`
  to refuse a file** (too large, undecodable, wrong encoding) and its message is
  flashed to the user as-is. Anything else your loader throws is treated as a
  bug, not a refusal: the user sees a generic message and the trace goes to
  `.porta/error.log`.

Your widget extends `EditorPaneWidget`, or `NavigablePaneWidget` if the pointer
can pan and zoom it:

| Member | Meaning |
| --- | --- |
| `paneId()` | the id your claim returns |
| `show(string $path, mixed $payload)` | display it (your loader made the payload) |
| `clear()` | nothing is open at all |
| `statusRight()` | your status-bar segment, **including your key hints** |
| `hide()` | another pane took the slot — free what only the visible pane needs |
| `forget(string $path)` | that tab closed for good; drop per-path state |
| `refreshTheme()` | the theme changed; re-make anything that baked colours |
| `press(int $row, int $col): bool` | a click, after focus; return true to arm a drag |
| `wheelBy(int $rows)` | a wheel notch, in rows |
| `scrollBy`, `zoomAt`, `magnifyAt`, `selectAt` | `NavigablePaneWidget` only: pan, zoom, select |

Call `$this->afterViewChange()` whenever your view moves — it repaints and tells
the status bar. Set your widget's id in its constructor (`$this->setId('hex')`);
that is the name the tests and the hit-tester know it by.

**Your pane's keys belong on the keymap page.** A pane binds keys the way any
widget does, with `getDefaultKeybindings()` — but a key nobody can find or
rebind is a key the user does not have. Declare them and they appear under
Settings → Keymap, and `.porta.json` can rebind them:

```php
$api->registerActions([
    'hex_next_field' => 'Hex: Next Field',
    'hex_prev_field' => 'Hex: Previous Field',
]);
```

These are **actions, not commands**: they fire only while your pane holds the
editor slot, and they never enter the command palette — which is what you want
for `-`, `0` or an arrow key. The ids are bare, not `hex:next-field`, because a
user types them into their own `.porta.json`; pick a prefix nobody else will use
and keep it forever, since renaming one silently breaks every existing rebind.
An id core already declares is refused at registration — you cannot relabel
`save`. Removing your plugin takes the rows away with it.

One caveat worth knowing before you choose: a rebind of a pane action **applies
on the next launch**, and the settings page says so when it persists one. Keys
matched by a widget resolve through the keymap that widget merged at startup,
which is why the live half of the map — the one a rebind rebuilds immediately —
only covers commands and IDE-scope actions. If an action needs to be
re-bindable *live*, register it as a command (`registerCommand(..., keys:)`)
instead; if it is a pane key like `-`, `0` or an arrow, an action is still the
right choice, because a command would also fire while your pane is nowhere in
sight.

Disabling your plugin stops the routing, so its files fall back to the text
editor; a tab already open in your pane keeps working. Removing the plugin drops
the pane for good.

### Showing a list of results

A tool that finds things — matches, failures, offenders — puts them in the
bottom dock, where Enter jumps to the location:

```php
$api->registerListTool('offenders', 'Offenders', rerun: fn () => $this->scan($api));

// later, from a command or a hook:
$api->showList('offenders', [], ToolRun::Running);          // opens and focuses the tab
$api->updateList('offenders', [
    ['path' => 'src/A.php', 'line' => 41, 'text' => '        $x = 1;'],
]);                                                          // replaces the rows in place
```

- **Paths are project-relative**, lines are **0-based** (the same convention
  `setDiagnostics()` uses). An absolute path inside the project is relativised
  for you; a row missing `path`, `line` or `text` is dropped rather than shown
  blank. An optional `ranges` of `['start' => …, 'length' => …]` display
  columns bolds the match.
- **You publish by id, never by a handle.** The user can × your tab at any
  moment, including while your scan is still running — `updateList()` on a
  closed tab is a silent no-op, which is exactly what you want for an async
  result that arrives late.
- `rerun` is F5 on your tab; `activate` is Enter on a row, and leaving it null
  takes the built-in open-the-file-and-jump, which is what most tools want.
- Your tab coexists with every other tool's. Re-invoking your tool reuses its
  tab rather than adding another.

### Ambient state in the status bar

```php
$api->registerStatusSegment(fn (): ?string => $this->failing > 0 ? "✗ {$this->failing}" : null);
```

Pulled **once per render**, so it must be cheap and must never block — report
something you already know, don't compute it here. Return null to show nothing,
which should be the normal case. A throwing closure shows nothing and is
reported once.

Every plugin returning a plain string shares one dim cell. Return a
`StatusCell` instead when you need a tone or a right-click menu of your own —
the bundled git plugin's branch indicator is one:

```php
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;

$api->registerStatusSegment(fn (): ?StatusCell => null === $this->branch ? null : new StatusCell(
    "⎇ {$this->branch}",
    StatusTone::Accent,
    fn (): array => $this->branchMenuItems(),
));
```

The tone is one of core's three (`Subtle`, `Accent`, `Active`) — you ask for
prominence, core owns the colour. When the value behind a segment changes
between frames, call `$api->refreshStatusSegments()`: the bar caches its
assembled line, so a branch name that resolves out of a subprocess needs to say
so or the old text stays.

### Applying what your tool produced

```php
$api->replaceDocument($formatted);
```

One undoable edit — Ctrl+Z puts back what was there — with the caret kept and a
full LSP sync. It never writes to disk; saving stays the user's verb.

Three silent no-ops, and they are the contract: nothing open, a viewer tab
holding the editor slot, and **an identical text** (a tool that changed nothing
must not mark the buffer modified). If your tool is asynchronous, check
`activeFile()` yourself before applying — the user may have switched tabs while
your subprocess ran, and the buffer you read is not necessarily the one in front
of them.

### Asking the user something

```php
$api->pick('Run which suite?', ['unit' => 'Unit', 'e2e' => 'E2e'], fn (string $v) => $this->run($v));
$api->prompt('New name', $current, fn (string $name) => $this->rename($name));
```

The same overlays the IDE's own pickers use. Both are action methods — call
them from a command handler or a hook, never from `activate()`.

### Lifecycle

- Plugins load during startup, **before** the keybinding map is built, so
  their default keys merge into it.
- `onReady($hook)` runs once the IDE is up — layout applied, services wired,
  and **before the first file opens**, so your setup lands ahead of your own
  `onFileOpened` instead of racing it.
- `onShutdown($hook)` runs as the IDE goes down, while core's services are
  still alive. The event loop has already stopped, so finish synchronously:
  a timer or subprocess you start there will never run. Persisting state is
  what it is for.
- Implement `Porta\Plugin\Api\DisposablePlugin` (`deactivate(): void`) if your
  plugin's own code starts things the registries cannot remove for it —
  event-loop timers, subprocesses, open handles. It runs when the plugin is
  disabled or removed **live**, inside your fault barrier; it does NOT run at
  IDE shutdown (that is `onShutdown()`'s moment). Without it, a live disable
  strips your listeners — the shutdown hook among them — and whatever you
  started keeps running in an IDE that no longer knows you.
  `AbstractSubprocessHighlighter` already implements it for its debounce
  timers.
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
| `addMenuItem(string $commandId, ?\Closure $enabledWhen = null, MenuSurface $surface = MenuSurface::Plugins)` | Put the command in a menu: the trailing **Plugins** menu (default), `MenuSurface::EditorContext` (right-click over the editor text) or `MenuSurface::FileContext` (right-click over a **file** — a tab, or a tree row). With `$enabledWhen`, the item renders dimmed while the closure returns false (re-evaluated per render; a throw reads as disabled) |
| `on(string $eventClass, \Closure $listener)` | Listen to a core event. `$eventClass` must implement `Porta\Event\PluginObservable` (the table below); the listener receives the event object. Runs after every priority-0 core listener; a throw is caught and flashed. There is no `off()` — disabling the plugin removes its listeners |
| `contextFile()` | The file the running invocation is **about**: the right-clicked path when it came from a file context menu, else `activeFile()`. Use it instead of `activeFile()` in any command you put on `FileContext` |
| `registerSetting(string $key, string $label, string $kind = 'text', $default = '', ?array $options = null, ?string $hint = null, ?int $min = null, ?int $max = null, ?int $step = null)` | Declare a user-editable setting shown on the plugin's settings page; kinds: `text`, `bool`, `int`, `choice` (with `$options`). Values persist under `plugins.<name>.<key>` in `.porta.json`. `$hint` is a one-line description, shown on every kind. `$min`/`$max`/`$step` are **`int` only** (declaring them on another kind throws) — without a `$step` the row moves by 1 per keypress, which is unusable for anything measured in bytes, and the range is shown so the user need not find it by hammering the key |
| `registerListTool(string $id, string $title, ?\Closure $rerun = null, ?\Closure $activate = null)` | Declare a bottom-dock list of results with jump-to-location |
| `registerStatusSegment(\Closure $segment)` | Contribute ambient state to the status bar; pulled once per render. Return a string to share the plugins' cell, or a `StatusCell` to claim one with its own tone and right-click menu |
| `registerView(string $id, \Closure $factory)` | Contribute a **full-screen view** — a workspace takeover with its own sidebar, drag separator and content pane, opened with `openView($id)`. `$factory(ViewServices $s): ContributedView` runs after plugins commit, with the region already minted for `$id` — once per activation, so a disable→re-enable runs it again against a FRESH region |
| `registerMenu(string $title, string $after, array $items, ?\Closure $visible = null, string $viewId = '', array $viewSafe = [])` | Contribute a **top-level menu** rather than an entry in the trailing Plugins one. `$items` are `'i:<command-id>'` and separators; `$after` names the core menu it follows; `$viewSafe` lists the ids that stay enabled while `$viewId`'s view owns the main area — a menu without one greys out its own items the moment its view opens |
| `registerDebugAdapter(string $languageId, \Closure $resolveCommand)` | Supply a DAP adapter's argv for a language (consulted below `.porta.json`) |
| `registerLanguageServer(string $languageId, string $name, \Closure $resolveCommand, ?\Closure $initializationOptions = null)` | Provide a language server for a language: `$resolveCommand` returns the launch argv (null = binary missing, the language falls to the next provider; first available in load order wins), and the optional `$initializationOptions` closure returns the LSP `initializationOptions` for the initialize handshake. Both are re-resolved lazily on every LSP (re)configure, so they may read this plugin's live settings. Plugin servers sit below the user's `.porta.json`/`$PORTA_LSP`/`--lsp` config |
| `adoptCommand(string $id, \Closure $handler)` | Implement a core command whose id core has vacated, keeping its label, keys and menu position. Bundled plugins only in practice — the id must be marked adoptable |
| `onReady(\Closure $hook)` | `$hook(string $projectRoot)` once the IDE is up, before the first file opens |
| `onShutdown(\Closure $hook)` | `$hook(string $projectRoot)` as the IDE goes down; synchronous only |
| `registerActions(array $actions)` | Declare rebindable actions (`id => label`) your pane binds — they appear on Settings → Keymap and may be rebound in `.porta.json`. Ids are bare and permanent; a core id is refused |
| `onFileOpened(\Closure $hook)` | `$hook(string $path)` after a file opens |
| `onFileSaved(\Closure $hook)` | `$hook(string $path)` after a buffer saves |
| `onBufferSwitched(\Closure $hook)` | `$hook(string $path)` when the active buffer changes — a real change, not every re-show of the same file |
| `onThemeChanged(\Closure $hook)` | `$hook(string $theme)` after a theme switch, **before** core drops its highlight caches — where a backend that bakes colours rebuilds |
| `onSettingChanged(\Closure $hook)` | `$hook(string $key, bool\|int\|string $value)` when the Settings page writes one of **this plugin's own** settings. Runs after the write, so `setting()` reads the new value. Without it, anything read once in `activate()` is restart-required by construction |

Actions (from handlers/hooks):

| Method | Purpose |
| --- | --- |
| `flash(string $message, float $seconds = 2.5)` | Status-bar notification |
| `notify(string $message, ToastSeverity $severity = ToastSeverity::Info, float $seconds = 2.5)` | The same toast, coloured for what happened: `Success`, `Warn`, `Error`. `flash()` is this at `Info` |
| `refreshStatusSegments()` | Tell the status bar your segment changed; the bar caches its line, so a value that arrives asynchronously needs this |
| `openView(string $id)` / `closeView(string $id)` | Open or close a registered full-screen view. Opening closes whatever other view owns the main area |
| `toggleView(string $id)` | Flip it open or closed — what a keybinding on a view wants. "Is it open?" is the registry's to answer, so a plugin never holds its own controller to find out |
| `showChangeMarkGutter(bool $on)` | Show or hide the version-control gutter STRIP itself. Hiding drops whatever marks were showing, so nothing lingers under a column that is no longer painted |
| `showList(string $id, array $rows = [], ToolRun $run = ToolRun::Finished)` | Open your list tool's tab and show rows |
| `updateList(string $id, array $rows, ToolRun $run = ToolRun::Finished)` | Replace its rows in place; a no-op once the user ×'d the tab |
| `replaceDocument(string $text)` | Replace the active text buffer's whole content as ONE undoable edit; no-op on an identical text, a viewer tab, or nothing open |
| `trans(string $id, array $params = [])` | Translate through the IDE's catalogs; an unknown string passes through |
| `pick(string $title, array $items, \Closure $onPick)` | Ask the user to choose from `value => label`, or from full rows (`['label' => …, 'hint' => …, 'value' => …]`) when the list needs a dimmed second column |
| `prompt(string $label, string $initial, \Closure $onSubmit)` | Ask the user for one line of text |
| `activeFile(): ?string` | Absolute path of the focused buffer |
| `selectedFiles(): list<string>` | The files selected in the project tree, in tree order, directories left out. One is the ordinary case — the tree always has its cursor row selected — so a command that acts on a set checks the count and says what it wanted |
| `documentText(): ?string` | Full text of the focused buffer |
| `caretPosition(): ?array` | `['line' => …, 'column' => …]` (0-based) |
| `insertAtCaret(string $text)` | Insert at the caret |
| `openFile(string $path)` | Open a file in the editor |
| `jumpTo(int $line, int $column = 0)` | Move the caret (0-based) |
| `setDiagnostics(string $path, array $diagnostics)` | Publish editor diagnostics; each item is `['line' => int (0-based), 'message' => string, 'severity' => 1–4 (optional, 1 = error), 'column' => int (optional)]`. An empty list clears. |
| `setFileStatuses(array $statuses)` | Publish the working tree's per-FILE version-control status for the file tree — `[absolute path => 'added'\|'modified'\|'deleted']`. The map REPLACES whole, so a file leaving it is how "back to unmodified" is said; an empty array clears every mark. |
| `setLineAnnotations(array $byLine)` | Show a dim trailing note after lines of the ACTIVE document — `[0-based line => string]`. An empty array clears. Takes no path: the editor drops annotations on every document show, so re-publish from `onBufferSwitched` to keep them. |
| `projectRoot(): string` | The project root (also safe during `activate()`) |
| `setting(string $key): bool\|int\|string` | The current value of a setting this plugin registered — the user's value coerced to the declared kind, or the default. Safe during `activate()`, but `registerSetting()` must run first. |
| `changeSetting(string $key, bool\|int\|string $value)` | Persist a new value for a setting this plugin registered — coerced, kept live and written to `.porta.json`, exactly like a Settings-page edit. An action method (throws during `activate()`). |
| `relaunchLanguageServer()` | Say that this plugin just replaced its language server's BINARY on disk: the servers are re-resolved and the running sessions relaunched. For a plugin that installs its own server. Do NOT use `changeSetting()` for this — the LSP pool skips a reconfigure whose resolved map is unchanged (deliberately, or every setting toggle would tear down every server), and an UPGRADE lands at the same path with byte-identical argv, so the old process keeps answering while the install reports success. An action method (throws during `activate()`). |

Diagnostics published via `setDiagnostics` behave exactly like LSP
diagnostics — inline end-of-line messages, the scroll-stripe, and the
Problems panel — and the two sources replace each other per file (last
writer wins).

`setChangeMarks` and `setFileStatuses` are the same question at two scales, and
they are deliberately on different cadences. The first is a diff of ONE file —
the gutter stripe — and refreshes per buffer. The second is `git status` over
the whole repository — the file tree's marks — so it should refresh only when
the working tree can actually have moved (a save, a commit, a stage or discard),
never per buffer switch: switching tabs changes nothing on disk. Both take
core's three kinds, and in both cases core owns what they look like.

`setLineAnnotations` writes the slot `git blame` uses — one dim note after each
line, in the active document only. It is the one editor decoration a plugin may
write: gutter marks, breakpoints and the stopped-line band stay core's, because
a plugin cannot invent the `Role` they paint with. The bundled `git` plugin's
blame mode is the reference use; a coverage overlay or an inline test-result
strip is the same shape.

### Applying an in-place formatter's output

A tool that rewrites the file (php-cs-fixer, mago format) should put its result
into the BUFFER as well, not leave it for the filesystem poller:

```php
$before = $this->textBefore($api, $file);       // at launch
new AsyncProcess([$binary, 'fix', $file], $api->projectRoot())->start(function () use ($api, $file, $before): void {
    $this->applyFormatted($api, $file, $before); // when it exits
});
```

`AbstractToolPlugin` carries both. The user gets the change immediately instead
of on the next poll tick, one Ctrl+Z away, and it works while the buffer is
dirty — the poller refuses that case and strands the result on disk.

`$before` is the version guard: if the document no longer matches what it was at
launch, the user typed while your subprocess ran and their keystrokes win. Pass
null for a run that touches many files (a whole-project fix) — the poller is the
right answer for files the user is not looking at.

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
formatter pattern (rewrite the file, then apply the result into the buffer with
`applyFormatted()`) and the linter pattern (`setDiagnostics`).
