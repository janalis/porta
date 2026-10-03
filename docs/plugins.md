# Plugins

Porta is extensible with small PHP plugins: a directory containing a
`plugin.php` that registers commands, keybindings, menu entries and event
hooks through a stable API. Plugins run in-process on the shared event loop
and are trusted code, like composer scripts.

A set of plugins ships **bundled with Porta** (`plugins/` in the
package), each documented in its own directory — the
[Bundled plugins](README.md#bundled-plugins) table lists them. Bundled plugins
are governed by the `enabledPlugins` list; when your `.porta.json` has no such
key, a small default set (the two viewers plus `todo`, `local-history` and
`git`) is enabled — an explicit list (even an empty one) replaces that default.
Four of them are the documented reference patterns:

| Plugin | What it shows |
| --- | --- |
| `php-cs-fixer` | Async subprocess (`AsyncProcess`), binary discovery, a persisted "Format on save" setting, an extra-args setting |
| `phpstan` | Publishing editor diagnostics (`setDiagnostics`) — inline messages, stripe, Problems panel — with "Analyse on save" |
| `mago` | Both patterns at once: format-in-place + JSON-lint diagnostics, with separate "Format on save" / "Lint on save" settings (both on chains format→lint) |
| `psalm` | The phpstan pattern for Psalm's JSON array output, with "Analyse on save" |

Others worth reading for a pattern: `image-viewer` and `graph-viewer` each own
their whole implementation and PROVIDE their pane (`graph-viewer` requires
`image-viewer`, because a graph is painted with the same rasterizer); `review`
is the reference use of `setCommentMarks` and `setLineBlocks` (and requires
`git` for its classes); `chroma` is an external-highlighter example;
`symfony-lsp` downloads and updates its own server binary; `todo`,
`local-history` and `git` are core features that moved out, keeping their ids
by adoption. Every bundled plugin surfaces its commands in the Plugins menu and
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
of packages it depends on (see below), and a `docs/` of its own
documentation (see [Documenting your plugin](#documenting-your-plugin)).

All three sources are scanned at startup, in that precedence order: a project
plugin shadows a global one of the same directory name, and both shadow a
bundled one — so you can override a bundled plugin by installing your own
under the same name.

## Documenting your plugin

A plugin carries its own documentation: Markdown pages in a `docs/` folder
beside its `plugin.php`, with `docs/README.md` as the front page.

```
my-plugin/
├── plugin.php
└── docs/
    ├── README.md      # what it does, its settings, its keys
    └── advanced.md    # linked from README.md
```

Once the plugin is installed — enabled or not — its page under **Settings →
Plugins** gains a **Documentation** row that opens `docs/README.md` in the
documentation window (`F1`), rendered like Porta's own guides, with the
plugin's name in the breadcrumb. Links between your pages work there; a
relative link out of your `docs/` — into the plugin's `src/`, say — is
refused, and a web address opens in your browser.

Every bundled plugin follows this layout, which makes them the examples to
copy: what the plugin does first, then a `## Settings` table, then a `## Keys`
table for every key it binds.

## `composer.json`: your own classes, and what you depend on

A plugin may ship a `composer.json` beside its `plugin.php` — composer's own
file, because everything a plugin needs to declare, composer already has a field
for. Every field is optional, and a plugin without one behaves exactly as it did
before there was one.

```json
{
    "name": "acme/tools",
    "require": { "php": ">=8.5" },
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
  plugin **by name at startup** ("needs PHP >=8.6 (this is 8.5.0) — not
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

## Shipping a console command

A **bundled** plugin can answer `porta <name> …` from a shell — the `review`
plugin's `porta review` is the reference. Name the command in the manifest:

```json
"extra": { "porta": { "commands": { "review": "Porta\\ReviewPlugin\\Console\\ReviewCommand" } } }
```

The class must extend `Porta\Plugin\Api\PluginCommand` — `porta` refuses any
other, a plain Symfony command included — and is built with no arguments. The
base gives it `projectRoot()` (the nearest directory up from where it was run
holding `.porta` or `.git`, since no IDE is open to say) and `errors($output)`
(stderr, so a failure never lands in a stdout a script reads). The plugin's
classes and its `requires`' are loadable when it runs. Only the plugins shipped
with Porta are asked — a project's `.porta/plugins/` never runs from a shell
prompt — and whether a project enabled the plugin does not matter.

### Opening projects on a server

A plugin that reaches a kind of server can open projects there, the way the
bundled `ssh` and `ftp` plugins do. Core keeps the cache, decides what to fetch
and when, orders the uploads and owns every conflict. The plugin only moves
bytes. It implements `Porta\Plugin\Api\RemoteProjectPlugin` beside
`AbstractPlugin`:

```php
return new class('webdav') extends AbstractPlugin implements RemoteProjectPlugin {
    public function activate(PluginApi $api): void {}

    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
    {
        return 'ftps' === $spec->scheme ? new MyTransport($spec) : null;
    }
};
```

The transport extends `Porta\Filesystem\RemoteTransport`:

- **Verbs:** `list`, `fetch`, `put` (with the stamp the file must still have),
  `mkdir`, `rename`, `remove`, and `close` when the project closes.
- **Answers:** each verb answers once, through its callback, from the event
  loop. A failure is the string a person is shown.
- **Paths:** paths are relative to the project's directory on the server, and
  `RemoteSpec::remotePath()` makes them the server's.
- **Processes:** a transport that drives a command-line tool extends
  `Porta\Filesystem\CommandTransport`. It gets `run()`, `failure()` and
  `close()`.

Opening one from a shell is a console command (above) extending
`Porta\Plugin\Api\OpenRemoteCommand`. You give it `schemes()` and
`connect()`, which is whatever needs a person at a terminal: a password, a host
key. The base reads the location, makes the cache with
`ProjectLocation::prepare()` and opens Porta on it.

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
| `GitRemoteCompleted` | `string $operation` (`fetch`/`pull`/`push`, or `cancelled` when the user stopped one), `bool $ok` |
| `WorkingTreeChanged` | — the working tree moved: a stage, stash, commit, reset or pull, or one of the file tree's own verbs (new file, new folder, move, copy, delete). It is defined by what a listener owes it — "recompute" — not by who moved the tree |
| `FileDeleted` | `string $path`, `?string $previousText` — a file or directory was deleted (the tree, a refactoring, an agent). `$path` is canonical, resolved before the removal; a DIRECTORY carries its tree, so ask `ProjectPaths::isUnder()`. `$previousText` is what the file held — the open buffer's text, unsaved edits included — or null for a directory or a file too large to be text. `local-history` keeps it, so a deleted file is restorable from Recent Changes |
| `FileRenamed` | `string $from`, `string $to` — both absolute and canonical; `$from` is already gone from disk. A DIRECTORY carries its whole tree, so anything you key by path should ask `ProjectPaths::moved()` rather than compare |
| `DiffToolLaunched` | `string $left`, `string $right` — Porta was launched as git's difftool (`porta --diff`), right after `IdeReady`; either file may be a temp blob outside the project. The bundled git plugin answers with its view |
| `MergeToolLaunched` | `string $local`, `string $remote`, `string $base`, `string $merged` — launched as git's mergetool (`porta --merge`); `$merged` is the file to resolve. Core sets the exit code from what is left in it at quit |
| `QuitRequested` | — a COMMAND you may `emit()`: quit the way Ctrl+Q does (unsaved buffers still ask). The git plugin ends a difftool session with it |
| `CommandRunRequested` | `string $command` — a COMMAND you may `emit()`: run a command by id, a core action's (`ide_save`) or another plugin's (`local-history:recent_changes`), as the palette would. With nothing answering to the id — its plugin disabled — the user is told so. The way one plugin reaches another's verb without depending on it |
| `AgentActivity` | `string $agent`, `string $name`, `string $path`, `AgentActivityKind $kind` (`Reading`/`Editing`), `int $line`, `int $column`, `float $fadeAfter`, `int $anchorLine = -1`, `int $anchorColumn = 0` — a fact you may `emit()`: an agent is at work on a file. Core shows it — a caret in the agent's colour with its name at the end of the line, a ✦ on the file's tab and tree row — until `$fadeAfter` seconds pass with no new report. The position is 0-based line and byte column; a negative line marks the file with no caret. A non-negative `$anchorLine` makes the text from the anchor to the caret its selection, tinted in its colour. The mcp plugin sends it for every tool that reads or edits |
| `AgentLeft` | `string $agent` — a fact you may `emit()`: everything `AgentActivity` showed for that agent goes at once |
| `ParticipantJoined` | `string $id`, `string $name`, `string $via` — someone else is now in the participant roster: another Porta on the same folder (`local`) or server directory (`ssh`, `ftp`), or a plugin's collaborator (`$via` is that plugin's name). `$id` is unique per source |
| `ParticipantLeft` | `string $id`, `string $name`, `string $via` — they are out of the roster: they quit, went quiet for three heartbeats, or the link to them stayed lost for a minute |
| `ChangeProposed` | `string $path`, `string $proposedPath` — a fact you may `emit()`: an agent proposed new text for a file and waits for the user's verdict. `$path` is the file on disk, `$proposedPath` a temporary copy of the proposal with the same file name. The bundled git plugin answers with its side-by-side comparison |
| `AgentEditing` | `string $path`, `string $previousText` — a fact you may `emit()`: an agent is about to change a file for the first time since it connected, and this is what the file held. `local-history` keeps it as a snapshot |
| `ToolsChanged` | — the tools plugins offer with `registerTool()` changed under a live enable or disable. Never at boot. Whatever serves them re-reads `tools()` |
| `DebugStatusChanged` | — the debug session started, paused, resumed or ended. It carries nothing: read the session with `runs()->debugger()` |
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

Only the events in the table can be announced, for the reason they are the
only ones you can hear: the rest of `Porta\Event` is internal.

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
- Each installed plugin lists with a **Remove** button — press Enter twice
  (the first press arms it: its border turns double, and a confirm flash
  arrives). A composer-managed entry
  runs `composer remove` in the driving project first, then unlinks the
  symlink — the real installed copy under `.porta/vendor/` is untouched by
  anything else that references it. Bundled plugins list as **(built-in)**
  beside a disabled (dashed) button, and cannot be removed.

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
  "my view moved" ping that refreshes the status bar, and `TerminalGraphics`:
  whether the terminal draws real pixels, and the channel that sends them
  (`Porta\ImageViewer\PixelPlacement` is the bundled viewers' use of it). That is the whole list —
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
| `statusClicks()`, `runStatusAction(string $action): bool` | the stretches of that segment a click acts on (`StatusClickSpan`s in its columns), and running one — the zoom panes' `+`, `-` and fit |
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

### Previewing a file beside its text

A pane can also be a **live preview**: the file opens in the text editor as
usual, and your pane sits beside it showing what the text becomes — the bundled
SVG and diagram previews, and core's own markdown one. One more call:

```php
$api->registerPane('hex', /* factory */, /* loader */);
$api->registerPreview('hex', ['hexdump']);
```

- **Your pane is fed the editor's TEXT**, not what your loader reads from disk:
  `show($path, $text)` is called when the tab comes on screen, and again each
  time typing pauses (0.15s), saved or not. So a pane that previews takes its
  own source format as its payload — the image pane an SVG's markup, the graph
  pane DOT. The loader is still needed; it is what `registerViewer()` uses.
- **`show()` is called with the same path over and over.** Treat a new text for
  the path already shown as an update: keep the zoom, the scroll and the last
  good picture on screen until the new one is ready, and return early when the
  text is unchanged. A text that does not parse is ordinary here — the user is
  halfway through typing it — so keep the last good rendering. Say what is
  wrong in the editor, with `setDiagnostics()`, as every other analyser does.
  Core calls `textBeside(bool)` on your pane each time it is arranged: false
  means the text is off screen (Preview, or a viewer tab), and its errors are
  then yours to show over the picture too.
- **A preview claim is asked before every viewer claim**, but only for a file
  small enough to edit (2 MB). Claim the same files with `registerViewer()` too
  and the viewer still takes one too large for the editor.
- **Click-to-source.** When a click lands on something the text wrote, call
  `$this->handler?->revealSource($line, $byteCol)` with where it was written
  (0-based line, byte column — `Porta\Support\Text::lineAndColumn()` turns an
  offset in the text into both). The IDE puts the caret there, centred, and
  hands the text the keys when it is on screen; on a viewer tab the call does
  nothing, so a pane that is both need not tell them apart. The bundled panes
  do it for a node (the graph pane) and a shape (the image pane's SVG).
- **Following the caret** is the other direction: override
  `followSource(int $line, int $byteCol)` and bring what that position of the
  text wrote into view. It is called when the caret reaches another line of
  the previewed text, not per keystroke; the default does nothing.
- The user switches between **Editor**, **Split** and **Preview** with the
  toggles at the right of the breadcrumbs bar or `Ctrl+Alt+V`, and drags the divider;
  the split's proportion is one setting for every previewed file. You do nothing
  for any of it — in Split your pane is simply narrower, and `hide()` is called
  when it leaves the screen, as for any pane.

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
    tooltip: $api->trans('Git branch %branch% — click to switch, create or delete a branch', ['%branch%' => $this->branch]),
));
```

The optional `tooltip` is what the cell says, in words, when the pointer rests
on it. A widget of your own (a contributed view's pane, say) can answer the
pointer too: implement `Porta\Ui\TooltipProvider`, whose
`tooltipAt(int $row, int $col): ?Tooltip` is asked in the widget's own
coordinates on every move. It must be cheap and must not change state; return
a `Porta\Ui\Tooltip` with a stable key, the words, and the cells it explains.
Any pane in an open view's region is asked, so nothing needs registering.

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

### Offering a tool to agents

```php
$api->registerTool(
    'status',
    'The working tree as `git status --porcelain` prints it',
    ['type' => 'object', 'properties' => new \stdClass()],
    static fn (array $args): string => $git->status(),
    effect: ToolEffect::Reads,
);
```

A tool is a verb something OUTSIDE the IDE may call — an agent connected
through the bundled **mcp** plugin, which serves every registered tool over the
Model Context Protocol ([Agents](../plugins/mcp/docs/README.md)). Core only keeps the list, so your plugin offers tools
without requiring mcp; they are served whenever it is enabled.

- The schema is the JSON Schema of the arguments, and it must be an `object`.
  The description is all a caller reads before calling, so say what comes back.
- The handler also receives a `Porta\Plugin\Api\ToolCaller` as a second
  argument, which it may leave undeclared: `$caller->name` is who is calling (an
  MCP client's own name), and `$caller->id` tells two callers with one name apart.
- Return a string, or an array to be sent as JSON. **Throw** to answer with an
  error: the message goes to the caller, and nothing is flashed in the IDE.
- The handler runs in its own fiber, so it may wait on a subprocess or
  anything else that answers through a callback, and the IDE paints on
  meanwhile. `Porta\Support\FiberWait::for()` does the waiting — and is safe
  when the callback fires at once, which a bare suspension is not:

  ```php
  [$code, $out] = FiberWait::for(fn (\Closure $done) => $runner->run($args, fn ($c, $o) => $done([$c, $o])));
  ```
- A tool that changes things should change what the user can undo — a buffer
  edit, not a file write.
- Say what calling it does with `$effect`, a `Porta\Plugin\Api\ToolEffect`:
  `Reads`, `Edits` (the IDE only — a tab, a message, an undoable buffer
  edit), `Writes` (a file on disk, a process), or `Publishes` (something
  other people see, such as a comment on a forge). An agent's client uses it to
  run a `Reads` tool without asking and to ask first for the rest. A tool with
  none says nothing, and a careful client treats it as the worst case.

A plugin that serves tools reads them with `tools()` and re-reads on
`ToolsChanged`.

### Working with open buffers

```php
$buffers = $api->openBuffers();
foreach ($buffers->files() as ['path' => $path, 'dirty' => $dirty]) { /* … */ }
$text = $buffers->text($path);            // as on screen, unsaved edits included
$why = $buffers->edit($path, 3, 0, 3, 4, 'next'); // null once applied, one undo step
$why = $buffers->save($path);             // null once saved
```

| Method | Purpose |
| --- | --- |
| `files(): list<array>` | Every open TEXT tab in tab order, `['path' => absolute, 'dirty' => bool]`; viewer tabs are left out |
| `text(string $path): ?string` | An open buffer's text, unsaved edits included; null when no text tab has it open |
| `open(string $path): ?string` | Open a file as a text tab BEHIND the one in front — no tab comes forward, no focus moves — so it can be changed where the user sees it without being taken there. Null once it is open as text, else why not (not a file, an image, too large) |
| `selection(string $path): ?array` | What is selected in it: `['line', 'column', 'endLine', 'endColumn', 'text']`, 0-based line and byte column as `caretPosition()` gives them, the start first. Null when nothing is |
| `edit(string $path, int $line, int $column, int $endLine, int $endColumn, string $text): ?string` | Replace a range with `$text`, in the same 0-based lines and byte columns, the end exclusive; an empty range inserts. It is ONE undo step and it is not saved; the editor and the language server hear of it. Null once applied, else why not: not open as text, or not a range of the document |
| `edits(string $path, array $edits): ?string` | Several `['line', 'column', 'endLine', 'endColumn', 'text']` ranges at once, each in the text as it is now, in document order and not overlapping — still ONE undo step. Null once applied, else why not, and then nothing was |
| `version(string $path): ?int` | A number that changes whenever the buffer's text does, to read the text only when there is something new; null when it is not open as text |
| `delegateHistory(string $path, \Closure $undo, \Closure $redo): bool` | Answer Ctrl+Z and redo in that buffer yourself (each closure says whether it changed anything) — for a shared session, where undo takes back only the user's own edits. The buffer's own history is forgotten. False when it is not open as text |
| `reclaimHistory(string $path)` | Give the buffer its own undo back, from that moment on |
| `makeReadOnly(string $path): bool` / `makeWritable(string $path)` | Refuse the user's keystrokes that would change the buffer (the caret, the selection and copy still work, and a flash says why), or allow them again. Edits from code still land |
| `create(string $path, string $text = '')` / `move(string $from, string $to)` / `delete(string $path)`: `?string` | Make, move or remove a file the way a refactoring does — open buffers follow, history keeps what was deleted. Null once done, else why not. **Only inside the project**: a path outside it, or through a symlink leading out, is refused like a language server's would be |
| `save(string $path): ?string` | Save it as Ctrl+S does — local history, the language servers and `onFileSaved` all hear of it. Null once saved (or with nothing to save), else why not. A file that **changed on disk** under the edits is refused, never overwritten: the keyboard asks the user to press Ctrl+S again, and a plugin cannot ask for them |

### Running a task

```php
$runs = $api->runs();
$runs->start('phpunit', ['vendor/bin/phpunit'], function (int $code) use ($runs): void {
    $result = $runs->output('phpunit'); // ['running' => false, 'exitCode' => 0, 'output' => '…']
});
```

| Method | Purpose |
| --- | --- |
| `configurations(): list<array>` | What the Run menu would offer now — the active PHP file, composer scripts, Makefile targets — as `['label' => …, 'command' => list<string>]` |
| `start(string $title, array $command, ?\Closure $onExit = null)` | Run `$command` in the `run:<title>` terminal session. The tab appears, but the panel is not opened and focus stays where the user left it. A run of the same title already going is replaced. `$onExit(int $code)` gets the exit code, or `-1` when the run is replaced or closed before it ends |
| `output(string $title): ?array` | What that run printed, as plain text (its last 400 lines), whether it is still `running`, and its `exitCode` once it ended by itself; null when there has been no such run |
| `terminals(): list<array>` | Every terminal tab — a shell, a run, the debugger's output — as `['id' => 'shell:1' \| 'run:<title>' \| …, 'title' => …, 'running' => bool]` |
| `scrollback(string $id): ?string` | What a terminal shows, as it would be printed again — colours kept, lines ended with CR LF; null without such a tab |
| `watch(string $id, ?\Closure $onOutput): bool` | Read a terminal's output as its process prints it — raw bytes, escape sequences and all — until called again with null; false without such a tab |
| `write(string $id, string $bytes): bool` | Type into a terminal's process; false without such a tab or a process running in it |
| `mirror(string $id, string $title, ?\Closure $onInput)` | A terminal tab whose process runs elsewhere (someone else's): made once, retitled after, shown behind the tab in front. It shows what `feed()` gives it; the keys typed into it go to `$onInput`, already encoded as a terminal sends them |
| `feed(string $id, string $bytes)` | Output for a mirrored tab, shown as though its process printed it |
| `debugger(): Debugger` | The debugger the Run menu starts; see below |

`debugger()` reads the debug session and steps it, as the Run menu does. Paths
are absolute and lines 0-based. Listen to `DebugStatusChanged` with `on()` to
know when to read it again: it fires as a session starts, pauses, resumes and
ends.

```php
$api->on(DebugStatusChanged::class, function () use ($api): void {
    $debugger = $api->runs()->debugger();
    if ('paused' === $debugger->status()) {
        $top = $debugger->frames()[0];
        $debugger->variables($top['id'], function (array $scopes): void { /* … */ });
    }
});
```

| Method | Purpose |
| --- | --- |
| `status(): string` | `idle` with no session, else `running` or `paused` |
| `stoppedAt(): ?array` | `['path' => …, 'line' => …]` where it is paused; null when it is not |
| `frames(): list<array>` | The paused stack, innermost first, as `['id' => int, 'name' => …, 'path' => …, 'line' => int]`; empty unless paused |
| `variables(int $frameId, \Closure $deliver)` | That frame's scopes and their variables, `$deliver(list<array{scope: string, variables: list<array{name: string, value: string, reference: int}>}>)` once all have arrived; empty unless paused. A non-zero `reference` marks a variable that holds more |
| `children(int $reference, \Closure $deliver)` | What that variable holds, an array's items or an object's properties, `$deliver(list<array{name: string, value: string, reference: int}>)`; empty unless paused |
| `continue()` / `stepOver()` / `stepIn()` / `stepOut()` | Step it as the Run menu does. Not paused, the user is told so and nothing happens |
| `pause()` | Stop a running session where it is; `DebugStatusChanged` follows once it has. Not running, the user is told so |
| `stop()` | End the session |

### Asking about the code

```php
$code = $api->codeIntelligence();
$problems = $code->diagnostics($path);
$code->definition($path, $line, $column, function (array $locations, ?string $error): void { /* … */ });
```

`codeIntelligence()` returns a `Porta\Plugin\Api\CodeIntelligence`, one
object for everything the IDE's language servers and analysers know. Like the
other action methods it throws during `activate()`. Lines and columns are
0-based; a column counts UTF-16 code units, as the protocol does.

| Method | Purpose |
| --- | --- |
| `languageServers(): array` | The server each language starts first, keyed by language id: its `command` (argv), `name`, and `initializationOptions` when it has some — for running one the same way on someone else's behalf |
| `serverTransport(array $server, string $rootDir, string $peerName): JsonRpcTransport` | A transport that runs one of those servers the way the IDE runs its own: in `$rootDir`, not yet started, and ended with the IDE however the IDE ends — use it rather than building a `JsonRpcTransport` yourself, or the server can outlive the IDE |
| `diagnostics(?string $path = null): list<array>` | What every language server and analyser said about the open buffers, or one of them, merged as the Problems panel shows it: `path`, `line`, `column`, `endColumn` when the finding ends on its own line, `severity` (1 = error … 4 = hint), `message`, and `source`/`code` when known. A file with no open buffer has none |
| `definition(string $path, int $line, int $column, \Closure $onDone)` | Where the symbol at a position is declared, asked of the server for that file — any file of the project, open or not; nothing is shown and no tab moves. `$onDone(list $locations, ?string $error)`: each location is `path` (absolute), `line`, `column` and the line's `text`; the error says why when there is no answer (no server for the language, one that refused) |
| `references(string $path, int $line, int $column, \Closure $onDone)` | Every place the symbol is used, its declaration included; `definition()`'s shape |
| `hover(string $path, int $line, int $column, \Closure $onDone)` | What the server says about the symbol — signature and documentation — as plain text: `$onDone(?string $text, ?string $error)` |
| `symbols(string $query, \Closure $onDone)` | The project's symbols matching `$query` from the running server (`workspace/symbol`): `$onDone(list $symbols, ?string $error)`, each `path`, `line`, `name` and the protocol's `kind` number. With no server running, the error says so |
| `outline(string $path, \Closure $onDone)` | What one file declares, outermost first (`textDocument/documentSymbol`): `$onDone(list $symbols, ?string $error)`, each `name`, the protocol's `kind` number, `line`, `column` and `depth` — how deeply it is nested |
| `rename(string $path, int $line, int $column, string $newName, \Closure $onDone)` | Rename the symbol everywhere the server finds it, and APPLY it as the editor's Rename does: an open file's buffer is edited and left unsaved, a file that is not open is **rewritten on disk**, and Undo Refactoring takes it all back. `$onDone(?array $outcome, ?string $error)`: `files` changed and `skipped`, the ones that could not be written |
| `format(string $path, \Closure $onDone)` | Format an OPEN file as its server does, by the file's own indentation, as one undoable edit, unsaved. `$onDone(int $applied, ?string $error)`: how many of the server's edits it applied, 0 when the file was already formatted. Refused when the text changed while the server worked |

The server questions answer later, on the loop, and a request to a language
server has no timeout of its own. A tool handler waits with
`FiberWait::within($seconds, …)` so an unresponsive server cannot hang it.

### Asking the user something

```php
$api->pick('Run which suite?', ['unit' => 'Unit', 'e2e' => 'E2e'], fn (string $v) => $this->run($v));
$api->prompt('New name', $current, fn (string $name) => $this->rename($name));
```

The same overlays the IDE's own pickers use. Both are action methods — call
them from a command handler or a hook, never from `activate()`.

### Translating your strings

Ship a `translations/` dir beside your `plugin.php`, one XLIFF 1.2 file per
locale: `messages.en.xlf`, `messages.fr.xlf`, and so on for `de`, `es`, `it`,
`ja` and `he`. **The English source string is the message id**, so a unit reads
`<source>Working tree clean</source><target>Arbre de travail propre</target>`
and your code calls `$api->trans('Working tree clean')`. A locale you ship no
file for, or a unit you leave out, shows the English.

The dir is picked up once your plugin loads — nothing to register — and it is
merged into the IDE's own catalog. Two rules follow from that merge:

- **Core wins a shared string.** Its catalog loads last, so a unit of yours
  whose source is one of core's (`Cancel`, `Run`) changes nothing. Use core's
  string as it is.
- **Disabling your plugin keeps its strings until a restart.** They are keyed
  by their English text, so nothing else can pick them up by mistake.

A widget translates through the `Translator` it is handed
(`PaneServices`, `ViewServices`), which serves the same merged catalog.

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
| `registerStatusSegment(\Closure $segment)` | Contribute ambient state to the status bar; pulled once per render. Return a string to share the plugins' cell, or a `StatusCell` to claim one with its own tone, right-click menu and tooltip |
| `registerView(string $id, \Closure $factory)` | Contribute a **view** — a movable, resizable window over the editor with its own sidebar, drag separator and content pane, opened with `openView($id)`. The view's `hostsWindow()` says it opens in that window and its `title()` titles it. `$factory(ViewServices $s): ContributedView` runs after plugins commit, with the region already minted for `$id` — once per activation, so a disable→re-enable runs it again against a FRESH region |
| `registerMenu(string $title, string $after, array $items, ?\Closure $visible = null, string $viewId = '', array $viewSafe = [])` | Contribute a **top-level menu** rather than an entry in the trailing Plugins one. `$items` are `'i:<command-id>'` and separators; `$after` names the core menu it follows; `$viewSafe` lists `$viewId`'s own commands: they run over its open window, where any other command — from a menu, the palette or a key — closes the window first so its effect is seen |
| `registerTool(string $id, string $description, array $inputSchema, \Closure $handler, ?ToolEffect $effect = null)` | Offer a verb to agents outside the IDE — see [Offering a tool to agents](#offering-a-tool-to-agents) |
| `registerDebugAdapter(string $languageId, \Closure $resolveCommand)` | Supply a DAP adapter's argv for a language (consulted below `.porta.json`) |
| `registerLanguageServer(string $languageId, string $name, \Closure $resolveCommand, ?\Closure $initializationOptions = null, ServerRole $role = ServerRole::Primary)` | Provide a language server for a language: `$resolveCommand` returns the launch argv, null when it does not serve the language here, or a `ServerNotFound` when it would and its binary is missing — either way the language falls to the next provider, and with none the status bar names the missing server instead of pointing at Settings, and the optional `$initializationOptions` closure returns the LSP `initializationOptions` for the initialize handshake. Both are re-resolved lazily on every LSP (re)configure, so they may read this plugin's live settings. `$role` decides what happens when several plugins claim one language: the first `Primary` wins and later ones are dropped, while a `Supplementary` one runs **beside** it and is promoted to primary when nothing else claims the language. A supplementary server gets the whole document lifecycle, its diagnostics are kept apart from the primary's rather than overwriting them, and its **completion items, code actions and code lenses are merged into the primary's** — with the follow-up round trips (`completionItem/resolve`, `codeAction/resolve`, `workspace/executeCommand`) routed back to whichever server offered the thing. Everything that picks a single answer — go-to-definition, rename, formatting, hover, semantic tokens — still asks the primary only. A server is asked for a verb only if it advertises the matching capability. Plugin servers sit below the user's `.porta.json`/`$PORTA_LSP`/`--lsp` config, and a `{languageId: command}` config entry replaces that language's list outright |
| `adoptCommand(string $id, \Closure $handler)` | Implement a core command whose id core has vacated, keeping its label, keys and menu position. Bundled plugins only in practice — the id must be marked adoptable |
| `onReady(\Closure $hook)` | `$hook(string $projectRoot)` once the IDE is up, before the first file opens |
| `onShutdown(\Closure $hook)` | `$hook(string $projectRoot)` as the IDE goes down; synchronous only |
| `registerActions(array $actions)` | Declare rebindable actions (`id => label`) your pane binds — they appear on Settings → Keymap and may be rebound in `.porta.json`. Ids are bare and permanent; a core id is refused |
| `onFileOpened(\Closure $hook)` | `$hook(string $path)` after a file opens |
| `onFileSaved(\Closure $hook)` | `$hook(string $path)` after a buffer saves |
| `onBufferSwitched(\Closure $hook)` | `$hook(string $path)` when the active buffer changes — a real change, not every re-show of the same file |
| `onThemeChanged(\Closure $hook)` | `$hook(string $theme)` after a theme switch, **before** core drops its highlight caches — where a backend that bakes colours rebuilds |
| `onSettingChanged(\Closure $hook)` | `$hook(string $key, bool\|int\|string $value)` when the Settings page writes one of **this plugin's own** settings. Runs after the write, so `setting()` reads the new value. Without it, anything read once in `activate()` is restart-required by construction |
| `onFileReloaded(\Closure $hook)` | `$hook(string $path)` when the poller replaced a CLEAN buffer with what the file now holds on disk — an external edit, a branch switch, a formatter run outside the IDE. Sugar over the internal `BufferReloaded`, which carries a mutable `Buffer` and so cannot be observable |
| `onFileRewritten(\Closure $hook)` | `$hook(string $path, string $previousText)` when a refactoring rewrote a file that had NO open buffer. The payload is the text the write REPLACED: the new content is on disk already and the old exists nowhere else. Not a save — no `onFileSaved` fires for it |
| `registerHighlighter(...)` | Provide a syntax-highlighting backend for one or more languages. `$isAvailable` declines a language when e.g. a binary is missing, so the next provider gets it; a backend that faults is dropped for that buffer after three consecutive failures, and the buffer renders as plain text rather than broken |
| `registerTokenSpans(array\|\Closure $languages, \Closure $classify)` | Classify comment and string regions for project search's token-scope filter ("only in comments/strings", "except in …"). Deliberately separate from `registerHighlighter()`: this runs once per FILE over raw content, where highlighting runs per visible line |
| `registerInstaller(string $tool, \Closure $isAvailable, array $via, ?\Closure $describe = null)` | Declare how to install this plugin's tool binary plus a predicate for whether it is there. When it is not, the plugin's settings page offers a one-click install that tries each `[manager, package]` pair in order, running the first whose manager is on the PATH (`composer`, `brew`, `cargo`). A tool no package manager ships passes `[[PluginApi::INSTALL_SELF, '<your command id>']]` instead and the button runs **your** command — that row does not disappear once the tool is there, it re-reads `Update`, because the plugin that downloads its own tool is what replaces it. `$describe` says what is installed in a few words (a version); it is pulled on every settings render and only while the tool is available. Omit the whole call and no install is offered |

Actions (from handlers/hooks):

| Method | Purpose |
| --- | --- |
| `flash(string\|Markup $message, float $seconds = 2.5)` | Status-bar notification. A string is shown exactly as written; a `Porta\Ui\Markup\Markup` is styled — see [Styled text](#styled-text) |
| `notify(string\|Markup $message, ToastSeverity $severity = ToastSeverity::Info, float $seconds = 2.5, ?string $slot = null)` | The same toast, coloured for what happened: `Success`, `Warn`, `Error`. `flash()` is this at `Info`. A `$slot` makes the message replaceable: a later notify in the same slot takes over the card still showing — say "Fetching…" for as long as it takes, then the outcome in its place |
| `refreshStatusSegments()` | Tell the status bar your segment changed; the bar caches its line, so a value that arrives asynchronously needs this |
| `openView(string $id)` / `closeView(string $id)` | Open or close a registered view. Opening closes whatever other view is open |
| `toggleView(string $id)` | Flip it open or closed — what a keybinding on a view wants. "Is it open?" is the registry's to answer, so a plugin never holds its own controller to find out |
| `showChangeMarkGutter(bool $on)` | Show or hide the version-control gutter STRIP itself. Hiding drops whatever marks were showing, so nothing lingers under a column that is no longer painted |
| `showList(string $id, array $rows = [], ToolRun $run = ToolRun::Finished)` | Open your list tool's tab and show rows |
| `setChangeMarks(string $path, array $marks)` | Paint the version-control gutter for one file: `0-based line => 'added'\|'modified'\|'deleted'`. `showChangeMarkGutter(false)` hides the strip and drops them |
| `setCommentMarks(string $path, array $marks)` | Mark the lines a conversation hangs on — a review thread: `0-based line => one-line summary`. Each gets `✎` in the gutter's marker column and on the scrollbar stripe, and the pointer over it reads the summary. Scoped to your plugin like `setDiagnostics`, kept across tab switches; `[]` clears |
| `copyToClipboard(string $text)` | Put text on the system clipboard — for a view that copies a commit hash or a path and has no business injecting a service to do it |
| `syntaxTheme()` | The `TerminalTheme` core is currently painting with, for a backend that bakes colours into its own output. Safe during `activate()`; pair it with `onThemeChanged()`, since a theme switch has no other hook |
| `updateList(string $id, array $rows, ToolRun $run = ToolRun::Finished)` | Replace its rows in place; a no-op once the user ×'d the tab |
| `replaceDocument(string $text)` | Replace the active text buffer's whole content as ONE undoable edit; no-op on an identical text, a viewer tab, or nothing open |
| `trans(string $id, array $params = [])` | Translate through the IDE's catalogs and your own — see [Translating your strings](#translating-your-strings); an unknown string passes through |
| `pick(string $title, array $items, \Closure $onPick): \Closure` | Ask the user to choose from `value => label`, or from full rows (`['label' => …, 'hint' => …, 'value' => …]`) when the list needs a dimmed second column. Calling what it returns withdraws the question, when the list is still open |
| `prompt(string $label, string $initial, \Closure $onSubmit, TextEcho $echo = TextEcho::Visible)` | Ask the user for one line of text; `TextEcho::Masked` draws bullets, for a password |
| `activeFile(): ?string` | Absolute path of the focused buffer |
| `selectedFiles(): list<string>` | The files selected in the project tree, in tree order, directories left out. One is the ordinary case — the tree always has its cursor row selected — so a command that acts on a set checks the count and says what it wanted |
| `documentText(): ?string` | Full text of the focused buffer |
| `caretPosition(): ?array` | `['line' => …, 'column' => …, 'top' => …]` (0-based); `top` is the first line the editor shows |
| `openBuffers(): OpenBuffers` | The open buffers — which are open and edited, their text, saving one; see [Working with open buffers](#working-with-open-buffers) |
| `participants(): PluginParticipants` | Your plugin's people in core's participant roster: `set(array $people)` (id ⇒ name, replacing the last list whole; `[]` takes them out), `outOfTouch()` (the link is lost: they stay as last seen for a minute, then go unless `set()` comes first), `yourName()` (the user's name as the others see it — the `displayName` setting, else the login name). Removing your plugin takes its people out |
| `runs(): Runs` | The Run menu's tasks and the terminal runs a plugin starts; see [Running a task](#running-a-task) |
| `codeIntelligence(): CodeIntelligence` | What the IDE knows about the code — see [Asking about the code](#asking-about-the-code) |
| `tools(): list<array>` | Every tool every running plugin registered, in load order: `plugin`, `id`, `description`, `inputSchema`, `handler`, `effect`. For a plugin that serves them; re-read on `ToolsChanged` |
| `insertAtCaret(string $text)` | Insert at the caret |
| `openFile(string $path)` | Open a file in the editor |
| `openProject(string $path, string $repeat)` | Reopen the IDE on the folder at `$path` — File › Open Project's switch, so a path that is no directory or is already open flashes instead, unsaved buffers hold it until the user does it again (`$repeat`, already translated, ends that refusal: what they repeat), and an untrusted project is asked about. Then this session ends |
| `jumpTo(int $line, int $column = 0, ?int $top = null)` | Move the caret (0-based); with `$top`, scroll so the editor shows that line first — the next caret move snaps the view back |
| `setDiagnostics(string $path, array $diagnostics)` | Publish editor diagnostics; each item is `['line' => int (0-based), 'message' => string, 'severity' => 1–4 (optional, 1 = error), 'column' => int (optional)]`. An empty list clears **this plugin's** findings for the file and leaves everyone else's — a language server publishing on every keystroke and an analyser publishing on every save no longer overwrite each other. |
| `setFileStatuses(array $statuses)` | Publish the working tree's per-FILE version-control status for the file tree — `[absolute path => 'added'\|'modified'\|'deleted']`. The map REPLACES whole, so a file leaving it is how "back to unmodified" is said; an empty array clears every mark. |
| `setLineAnnotations(array $byLine)` | Show a dim trailing note after lines of the ACTIVE document — `[0-based line => string]`. Replaces only YOUR plugin's notes, so an empty array clears yours; a line two plugins annotate shows both. Takes no path: the editor drops annotations on every document show, so re-publish from `onBufferSwitched` to keep them. |
| `setLineBlocks(array $byLine)` | Show rows of text UNDER lines of the ACTIVE document — `[0-based line => list<string>]`, plain text painted dim; each line grows by its rows, the way a code lens adds one above. Your plugin's own, like `setLineAnnotations`; re-publish from `onBufferSwitched` |
| `projectRoot(): string` | The project root (also safe during `activate()`) |
| `setting(string $key): bool\|int\|string` | The current value of a setting this plugin registered — the user's value coerced to the declared kind, or the default. Safe during `activate()`, but `registerSetting()` must run first. |
| `changeSetting(string $key, bool\|int\|string $value)` | Persist a new value for a setting this plugin registered — coerced, kept live and written to `.porta.json`, exactly like a Settings-page edit. An action method (throws during `activate()`). |
| `relaunchLanguageServer()` | Say that this plugin just replaced its language server's BINARY on disk: the servers are re-resolved and the running sessions relaunched. For a plugin that installs its own server. Do NOT use `changeSetting()` for this — the LSP pool skips a reconfigure whose resolved map is unchanged (deliberately, or every setting toggle would tear down every server), and an UPGRADE lands at the same path with byte-identical argv, so the old process keeps answering while the install reports success. An action method (throws during `activate()`). |

### Styled text

Messages and view rows use Symfony Console's formatter markup. The styles come
from the live theme, so a theme switch recolors them and monochrome stays
monochrome:

```php
use Porta\Ui\Markup\Markup;
use Porta\Ui\Markup\MarkupParser;

$api->flash(Markup::of('Pushed <info>'.MarkupParser::escape($branch).'</>'));
$row = $styles->format('<modified>'.MarkupParser::escape($sha).'</> '.MarkupParser::escape($subject));
```

**Tags.**
- Console's four named styles: `<info>`, `<comment>`, `<question>` and `<error>`.
- Every theme role by its kebab-case name: `<added>`, `<diag-warn>`, `<tab-accent>`, and so on.
- Inline styles: `<fg=…;bg=…;options=…;href=…>`.

**Closing and escaping.** `</>` closes the innermost tag, and `</name>` closes
the named one. A `<` written as `\<` is text. A tag that does not parse is
printed as written. Console would throw on it, but a render has nowhere to
throw to.

**Inline values.**
- **Colors:** Console's names (`red` to `bright-white`, `gray`, `default`), `#hex`, or a 0–255 palette index.
- **Options:** `bold`, `underscore` (or `underline`), `reverse`, `dim`, `italic` and `strikethrough`. `blink` and `conceal` are accepted and ignored.
- **`href=`:** makes an OSC 8 link.

**Escaping what you splice in.** Always pass interpolated text through
`MarkupParser::escape()`, so a `<` in a file name is never read as a tag. A
bare string passed to `flash`/`notify` is escaped for you. To build markup
from a message, use `Markup::of()`. `Markup::literal()` and `->append()` keep
plain text plain.

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
line, in the active document only. Each plugin writes its own notes, and a line
two plugins annotate (blame, and a review's thread summary) shows both. The
bundled `git` plugin's blame mode is the reference use; a coverage overlay or an
inline test-result strip is the same shape. `setCommentMarks` is the one gutter
mark a plugin may place, and like change marks its look is core's: a plugin says
WHICH lines carry a conversation, never what the mark looks like. Breakpoints and
the stopped-line band stay core's, because a plugin cannot invent the `Role` they
paint with.

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
