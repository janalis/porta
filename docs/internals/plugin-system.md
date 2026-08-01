# The plugin system

How plugins are found, ordered, loaded and unloaded. For the API a plugin
compiles against, see [plugins.md](../plugins.md); this page is the runtime
behind it.

## Where plugins come from

Three sources, in precedence order:

1. `<project>/.porta/plugins/*/plugin.php`
2. `~/.porta/plugins/*/plugin.php` (`PORTA_HOME` overrides the home dir)
3. the plugins bundled in the Porta package at `<package>/plugins/`
   (`PORTA_EMBEDDED_PLUGINS` overrides)

Earlier sources shadow later ones by directory name. Project and global plugins
are **opt-out** — loaded unless named in `disabledPlugins`. Bundled ones follow
`enabledPlugins`: when the key is absent
`IdeConfig::DEFAULT_ENABLED_PLUGINS` applies, so the viewers, TODO scan, local
history and git blame work on a fresh install while the rest stay off.

An explicit list **replaces** the default, even an empty one. That is why
`PortaJsonWriter::persistBundled*` materialises the default before editing:
disabling the last defaulted name has to write an explicit `[]`, because an
unset key would flip the default back on.

phpunit pins `PORTA_EMBEDDED_PLUGINS` to `/nonexistent` — bundled plugins opt in
per suite via `UsesBundledPlugins` — and points `PORTA_HOME` at
`tests/Support/fixtures/home`, which installs `tempest` globally so the suite
renders coloured.

## A plugin class cannot be a container service

Discovery globs `src/*`, autoconfiguration is consumed and the container
compiled before any plugin PHP runs, the dump is cached across boots on a key
that knows nothing about plugins, and the only post-compile mutation is `set()`
on the four synthetics.

Everything a plugin's object needs is therefore hand-passed. That is what
`Editor/PaneServices` is: a four-member DTO (the `Styles` holder, the
`Translator`, the `Support/Scheduler`, the `ViewerTabHandler`) handed to a pane
factory. **Growing it is how "my pane needs one more thing from core" becomes
visible in review**, and it should stay hard to add to. The same argument
governs `Plugin/Runtime/PluginRuntime`, the twelve-member surface the action
verbs reach.

## The manifest is `composer.json`

Every field a separate manifest would have held has a native composer one, and a
plugin that vendors packages needs the file anyway.

- `autoload.psr-4` gives the class map, so `$autoload` is
  `array<string, list<string>>`. A prefix whose directories are all absent is
  dropped. The **empty prefix** (composer's fallback directory) is refused:
  `str_starts_with($class, '')` is true for every class, so that one plugin
  would answer for every unresolved name in the process.
- `extra.porta.requires` holds cross-plugin dependencies, because those are
  plugin *directory* names and composer's own `require` would send composer to
  Packagist for something that is not a package.
- `name` is read by nothing. Identity is the directory name, which
  `enabledPlugins`, `disabledPlugins`, `requires` and the `plugins.<name>.<key>`
  settings all assume.

`needsInstall()` means "declares something an install would produce" — an
installable requirement (the name contains a `/`, which is composer's own
discriminator) or an `autoload.classmap`/`files` entry. It deliberately does not
mean "has a composer.json and no `vendor/`", which would decline the entire
bundled set now that every plugin has one.

## Load order

`Plugin/Runtime/PluginOrder::resolve()` topologically sorts the manifests.
A missing or disabled requirement skips the plugin with a named warning, and the
skip propagates to its dependents. A **cycle is reported rather than resolved** —
no member loads, because picking one to go first would make the bug
intermittent.

Manifests are read **before any plugin PHP is required**. That is a requirement,
not a preference: `requires` decides load order, so it cannot be discovered by
loading.

Enabling through `PluginLifecycle` auto-enables requirements transitively and
says so. Disabling cascades to dependents. **Boot deliberately does not rewrite
config** — a startup that silently re-enables what the user turned off is the
worse surprise.

## Autoloading a plugin's own classes

`Plugin/Runtime/PluginAutoloader` holds one `spl_autoload_register` over a
prefix → directories map, registered from `PluginLoader` at boot and from
`tests/bootstrap.php` so plugin classes resolve under paratest, including CI's
`--classmap-authoritative` install.

A plugin may vendor its dependencies, and the autoloader extends to them by
reading composer's **generated maps** — `vendor/composer/autoload_psr4.php` and
`autoload_classmap.php`.

**Composer's own autoloader is never run, and that is the design.**
`autoload_real.php` ends in `$loader->register(true)` — prepend — so requiring a
plugin's `vendor/autoload.php` would let any plugin replace Porta's dependencies
process-wide, and would execute the package's `autoload.files` at discovery
time, before load order has been decided. Reading the maps keeps Porta's copy in
front by construction. PSR-0 and `autoload.files` are unsupported and say so.

Two boot reports exist for this: a plugin that needs an install is declined by
name, and `shadowedPackages()` names each package whose plugin copy will never
be reached.

## Dependencies follow the enabled state

`composer install --no-dev` runs at three moments — install, enable, and a boot
drain for a plugin that is enabled with no `vendor/`.
`PluginInstaller::installDependencies()` returns synchronously so the caller
knows whether to wait, and the **guard order is load-bearing**: "not needed" is
answered *before* the single-flight slot is consulted, so a chain of
dependency-free plugins is never refused because someone else is cloning.

**Activation must never precede the install.** `PluginAutoloader::register()`
reads `autoload_psr4.php` eagerly, so loading first bakes in an empty vendor
map.

**Nothing is ever rolled back**; the next boot retries. **Disable removes only
what Porta installed**: a successful run writes `vendor/.porta-installed`, and
removal refuses without it, so a plugin that commits its `vendor/` cannot lose
tracked files to a keypress. `composer.lock` stays.

**Boot never shells out.** `PluginLoader::loadInto()` runs while the container is
still being built, so it records the set and `Bootstrap/StartupWiring` drains it
at the constructor tail. The pending set is sorted deps-first, because discovery
order is alphabetical.

## Registration and the drain

`Plugin/Runtime/PluginHost` builds the settings/manager/loader triple from the
synthetic `IdeConfig`. `ensureLoaded()` is idempotent and triggered by the
`Keybindings` factory, so it runs **before** the immutable keybinding map is
built and plugin default keys merge into it.

Registration buffers in the per-plugin `PluginApi` and commits atomically after
`activate()`. Ten of the fifteen extension points share the generic
`PluginBuffer`; the other five stay plain arrays, each saying why at its
declaration.

Consumer-owned interfaces are how a subsystem consumes plugin registrations
without depending on `Porta\Plugin`: `Editor/EditorPaneSource`,
`Editor/ViewerProviderSource`, `Config/RebindableActionSource`,
`Search/TokenSpanProvider`, `Highlight/SyntaxHighlightProviderSource`,
`Lsp/LanguageServerProvider`, `Workbench/Dock/DockToolSource`. The interface
lives beside its consumer; `PluginManager` implements it.

Every plugin handler and hook is wrapped in try/catch to a status-bar flash, so
a faulting plugin never kills the loop. The wrapper takes a **single parameter**
on purpose: the dispatcher calls listeners as `($event, $eventName,
$dispatcher)`, so a `(...$args)` wrapper would hand every plugin the bus.
Plugins listen; they never dispatch.

**Prefer anonymous classes in plugin files** — cross-plugin class redeclaration
is an uncatchable fatal.

## Panes and viewers

`registerViewer(id, claims)` says which files are mine; `registerPane(id,
factory, loader)` says what shows them. The loader runs on every open and
**throws to refuse** a file (too large, undecodable, not UTF-8); its message is
flashed as-is.

`Buffer` carries `?string $paneId` and `mixed $panePayload` — core never looks
inside — and `isViewer()` is the one kind question core still asks.

**Disable and removal differ.** Disabling stops the routing claim and leaves the
pane standing, because a tab opened before the toggle still has to render.
Removal drops it. A pane that loses the editor slot gets `hide()`, a closed tab
gets `forget($path)`, and the last tab closing gets `clear()`.

## Adopting a vacated core command id

`adoptCommand('ide_todo', $handler)` registers under the **bare** core id
instead of the namespaced one, inheriting the label, the default keys and the
menu position — so a feature that moves out of `src/` keeps its `.porta.json`
binding, its keymap row and its place in a core menu.

Only ids `ActionCatalog` marks `'adoptable' => true` may be taken, which is what
stops a third party becoming `save`. **The flag cannot precede the vacancy** —
two registrations would both dispatch — so marking it and deleting core's
registration are one commit.

`MenuModel::items()` skips an `i:` spec whose id is adoptable and unregistered.
An adopted verb is the one kind that may go unanswered, since its plugin can be
disabled. The rule stays narrow for that reason.

The ids that moved this way: `ide_todo`, `ide_local_history`, `git_blame`,
`git_toggle_blame`. The last two are keyless in the catalog — neither had a
default binding when core implemented them, and a move is not the moment to
invent one.

## A plugin file wires; `src/` implements

Four abstract bases in `Plugin/Api/` hold what plugin files used to copy:
`AbstractPlugin` (name, claimed languages, `coreSetting()`),
`AbstractToolPlugin` (binary discovery, the single-flight slot, the active-file
guard, the report verbs), `AbstractAnalyzerPlugin` (the whole
run → decode → `setDiagnostics` → count flash) and
`AbstractSubprocessHighlighter` (the debounce, the latest-wins stamp, the
process ceiling, the token → themed-ANSI conversion).

**The test for what belongs in a base is the gate boundary, not
deduplication.** Plugin files sit outside mago's analyzer and outside the
coverage gate, so logic that must be covered and analysed moves into `src/` even
at one caller.

Third-party plugins may extend these or implement `PortaPlugin` directly;
`PluginLoader` only ever checks `instanceof PortaPlugin`.

## Coverage runs a second, serial pass

`composer coverage:check` is the parallel run over `src` **plus**
`composer coverage:plugins` — serial phpunit over the `Plugins` testsuite,
graded with `--only=/plugins/`.

Putting `plugins/*/src` into phpunit's `<source>` for the parallel run collapses
the total to 86–95%, differently every run. Each paratest worker serialises
coverage with paths relative to its own longest common directory, while the
merger keeps only the first worker's base path — so a worker that covered a
plugin file reduces to the repo root instead of `src`, every path it reports
resolves wrong, and that whole worker's data is dropped silently. No
project-side configuration prevents it. A serial run never merges.

`composer coverage` also sets `pcov.directory` and `pcov.exclude` itself,
because pcov has its own instrumentation window with a machine-level default,
and a file outside it is never measured no matter what phpunit says.

**A plugin's code must therefore be 100% covered by its own tests.** A corollary
worth knowing: a plugin file loaded by *another* plugin's tests enters the
report, because booting an IDE loads every default-enabled bundled plugin. Two
cures, both used — a suite's `TempProject`s name `enabledPlugins` explicitly
(including whatever the plugin under test `requires`, or `PluginOrder` skips it
and the suite reads as a mystifying timeout), and each plugin's E2e lives in its
own suite.
