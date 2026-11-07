# The plugin system

How plugins are found, ordered, loaded and unloaded. For the API a plugin
compiles against, see [plugins.md](../plugins.md); this page is the runtime
behind it.

## Where plugins come from

Three sources, in precedence order:

1. `<project>/.porta/plugins/*/porta.php`
2. `~/.porta/plugins/*/porta.php` (`PORTA_HOME` overrides the home dir)
3. the plugins bundled in the Porta package at `<package>/plugins/`
   (`PORTA_EMBEDDED_PLUGINS` overrides)

Earlier sources shadow later ones by directory name. Global plugins are
**opt-out** — loaded unless named in `disabledPlugins`. So are a project's, but
only once the project is **trusted** (`Config/WorkspaceTrust`): loading one is
running it, and a project is something one clones. An untrusted project opens
restricted — its `.porta/plugins` are skipped, its `.porta.json` loses every key
that names something to run (`WorkspaceTrust::restricted()`), tools are not
taken from its `vendor/bin`, and a tool that executes its PHP (phpstan, psalm,
php-cs-fixer: `AbstractToolPlugin::runsProjectCode()`) refuses. `porta` asks
before the IDE boots, the project switcher asks before a switch, and the
palette's Trust This Project / Open This Project Restricted change the answer
and reopen. Answers live in `~/.porta/trusted-projects.json`;
`PORTA_TRUSTED_FOLDERS` trusts every project under a folder (the suite sets it
to the temp dir — a test of the untrusted path uses `UntrustsProjects`). Bundled ones follow
`enabledPlugins`: when the key is absent
`IdeConfig::DEFAULT_ENABLED_PLUGINS` applies, so the viewers, TODO scan, local
history, git blame and opening a project over ssh or FTP work on a fresh
install while the rest stay off.

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
`Editor/PaneServices` is: a five-member DTO (the `Styles` holder, the
`Translator`, the `Support/Scheduler`, the `ViewerTabHandler`, the
`Ui/Graphics/TerminalGraphics` channel) handed to a pane
factory. `Workbench/View/ViewServices` is the same idea one size up — eight
members for a whole view window. **Growing either is how "my pane needs one
more thing from core" becomes visible in review**, and both should stay hard to
add to. The same argument governs `Plugin/Runtime/PluginRuntime`, the surface
the action verbs reach.

What a plugin CANNOT be handed, it wraps. The bundled git plugin owns a
workbench view, and the services that view was written against — `Notifier`,
`CodeEditorWidget`, `Translator`, `LayoutController` — are none of them
available: they are container services, and half of them would hand a plugin far
more than the one verb it wants. So it carries `Flash`, `Modals`, `Translate`
and `DiffPrefs`: one closure from `PluginApi` behind the method name the call
sites already used. (`Translate` is about WHEN a lookup may run, not where the
strings live: git's own are in `plugins/git/translations/`.) **~90 call sites are unchanged**, which is the test of
whether a shim is the right shape — if the bodies have to change, the seam is
in the wrong place.

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
- **The platform half of `require` is checked, not dropped**:
  `unmetPlatform()` grades the `php` constraint (composer/semver; an
  unparseable constraint is trusted, never refused) and `ext-*` against the
  running host, and both load doors decline by name — a plugin needing PHP 8.5
  or a missing extension used to load and fatal far from the cause.
- `extra.porta.api` pins the **plugin-API major** the plugin targets
  (`PluginApi::API_VERSION`); a mismatch declines by name, undeclared trusts
  the current one. Bumped only on a breaking `PluginApi` change.

`needsInstall()` means "declares something an install would actually DELIVER" —
an installable requirement the host does not already ship
(`installablePackages()`: the `/`-named half of `require` minus
`InstalledVersions`' set, since installing a host-shipped package produces only
the never-used vendor copy `shadowedPackages()` warns about) or an
`autoload.classmap`/`files` entry. It deliberately does not mean "has a
composer.json and no `vendor/`", which would decline the entire bundled set —
every bundled plugin declares the symfony components it imports.

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

Deactivation strips everything a plugin REGISTERED, but what its own code
STARTED — timers, subprocesses — needs the plugin's cooperation: a committed
instance implementing `Plugin/Api/DisposablePlugin` gets `deactivate()` called
first, in its own fault barrier, before the registries empty. Opt-in and
separate from `PortaPlugin` on purpose: adding a method there would break every
existing plugin for a hook most never need, and it must NOT run at IDE shutdown
(`onShutdown()` covers that; process exit reclaims the rest).

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

## A plugin PACKAGE arrives through composer

`PluginInstaller::install()` is the outer mechanism — how a new plugin
directory gets onto disk in the first place, as opposed to
`installDependencies()` below, which is about a plugin's OWN dependencies
once it's already there. Every source kind the Settings → Plugins form
accepts (`vendor/name`, a git URL, a local directory) is resolved by
requiring a real Composer package into a Porta-owned Composer project at
`<scopeBase>/.porta/composer.json` ({@see `PluginComposerProject`}), scaffolded
on first use. Composer installs at its own default location,
`.porta/vendor/<vendor>/<project>`, and `PluginInstaller` symlinks
`.porta/plugins/<name>` to it afterward — every downstream consumer
(directory scanning, `PluginManifest`, load ordering) is unaffected, since
PHP's filesystem functions traverse a symlink transparently and identity is
still the symlink's own basename.

There is deliberately no `composer/installers`-style custom install-path
plugin in play: `composer/installers`' own top-level installer only
recognizes a fixed, hardcoded list of package types and refuses to route an
unregistered one like `porta-plugin`, and the one package that adds that
support (`composer-installers-extender`) is unmaintained and pinned to a
`composer/installers` major this project doesn't want to depend on.
Composer's plain default location plus a symlink gets the same practical
result — a real, versioned, `composer.lock`-pinned package — without it.

A directory path or git URL needs its own `composer.json` declaring
`"name": "vendor/project"` and `"type": "porta-plugin"` — read directly
(never through `PluginManifest`, whose `name` field is deliberately "read by
nothing" for identity purposes; this is a one-time transient read, purely to
learn what package to `require`). A git URL is peeked first: a shallow clone
to a scratch directory OUTSIDE `.porta` (discarded once read), purely to
learn the package name, type, and checked-out branch, since composer cannot
resolve a package from a bare URL. It's then required as `vendor/project:dev-<branch>`
from a registered `vcs` repository — pinned to the peeked branch, not left to
composer's own version resolution, for parity with "clone whatever HEAD is"
rather than a new "pick a release" capability. A local path is required as
`vendor/project:@dev` from a registered `path` repository (`options.symlink:
true`), so a plugin under active local development is picked up live. A bare
`vendor/name` is required directly against Packagist, then verified
post-hoc — the one flow with no way to check before the network round-trip —
rolling back with a best-effort `composer remove` on a mismatch.

Removing a composer-managed plugin (`.porta/plugins/<name>` is a symlink)
runs `composer remove` in the driving project, then unlinks the symlink —
never `Filesystem::remove()`'s recursive delete, which would otherwise
recurse into the real target. A pre-existing git-clone/hand-placed directory
(not a symlink) keeps the old direct delete, unaffected.

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
`activate()`. Sixteen of the twenty extension points each have a generic
`PluginBuffer`, held together on `Plugin/Api/PluginContributions` and drained
through `contributions()`; the other four (commands, menu items, hooks, setting
descriptors) stay plain arrays on the API, each saying why at its declaration.

**Commit is the deadline, and a contributed VIEW arrives after it.** A view's
factory needs the region core mints for it, which cannot exist until the view
has registered — so `registerView()`'s closure runs after the drain, once per
activation. That is a real ordering constraint, not a detail: anything a view
has to be reachable from — a command that toggles it, a listener that answers
it — has to be registered during `activate()`, against a slot the factory
fills. The git plugin's `ViewSlot` is that, and the symptom of getting it wrong
is a `PluginException` about a listener arriving after the plugin was
committed.

## The three registrars are live, not boot-only

`Editor/PaneRegistrar`, `Workbench/View/ViewRegistrar` and
`Workbench/Menu/MenuRegistrar` each drain everything at the `Ide` constructor
tail AND one plugin's slice on a live toggle, so enabling a plugin mid-session
builds its panes, its view and its top-level menus without a
restart. Before that, all three were one-shot: a first-time enable committed a
pane whose widget was never built (the file fell silently to the text path), a
view nothing could open, and a menu that never appeared — and a disable left a
dead plugin's menu rendering forever.

The retraction side is not symmetric, deliberately. A **pane stays** on
disable, because a tab opened before the toggle is still rendering in it; only
removal drops it. A **view is closed and its region un-minted**, because the
main area has to go back to the editor — `ViewRegistrar::forget()` closes an
open view first (which is also what lets the view cancel its own timers),
restores the host by hand if that `close()` throws, and un-mints so the next
enable builds FRESH widgets rather than a container still holding the dead
view's children. `LayoutController::forgetView()` drops the cached resize state
in the same breath, or a drag would resize widgets no longer on screen.

**The lifecycle reaches the view surface through an event, not a constructor
argument.** `PluginContributionsActivated` / `PluginContributionsRetracted` are
facts `Workbench/View/ViewContributionListener` answers, because the direct
edge is a container cycle: `PluginLifecycle → ViewRegistrar → ViewHost →
PaneLayout →` the overlay stack `→ WelcomeSteps → PluginEnablement`, which is
the lifecycle again. Dispatch is synchronous, so the view exists before the
lifecycle's own tail (`keys->rebuild()`, `menuRebuilder->rebuild()`, the
repaint) runs. That listener also mirrors Ide's boot tail in order —
`applyDirection()` then `applyLayout()` — since the arrangement has to mount
the minted region before a persisted sidebar width can land on it.

A view id already registered is refused: `settings` and `welcome` register from
their own constructors, and a plugin may not displace one live. Symmetrically,
`forget()` iterates only the ids the registrar itself built, so it can never
retract a core view.

Consumer-owned interfaces are how a subsystem consumes plugin registrations
without depending on `Porta\Plugin`: `Editor/EditorPaneSource`,
`Editor/ViewerProviderSource`, `Config/RebindableActionSource`,
`Search/TokenSpanProvider`, `Highlight/SyntaxHighlightProviderSource`,
`Lsp/LanguageServerProvider`, `Workbench/Dock/DockToolSource`,
`Workbench/Menu/ContributedMenuSource`, `Workbench/View/ContributedViewSource`,
`Debug/DebugAdapterProvider`, `Workbench/StatusSegmentSource`. The interface
lives beside its consumer; `PluginManager` implements it.

Every plugin handler and hook is wrapped in try/catch to a status-bar flash, so
a faulting plugin never kills the loop. The wrapper takes a **single parameter**
on purpose: the dispatcher calls listeners as `($event, $eventName,
$dispatcher)`, so a `(...$args)` wrapper would hand every plugin the bus.
Plugins listen; they never dispatch.

**Prefer anonymous classes in plugin files** — cross-plugin class redeclaration
is an uncatchable fatal.

## A plugin's strings are its own catalog

A plugin ships `translations/messages.<locale>.xlf` beside its entry file, and
`PluginLoader::loadFile()` hands that dir to `I18n/Translator::addCatalogDirectory()`
**after** `commit` — a plugin that fails to activate contributes no strings.
There is no `PluginApi` verb: the dir is found by convention, on the boot load
and on a live enable alike.

It merges into the one `messages` domain, because core translates what a
plugin hands it — setting labels, menu titles, tab titles, keymap labels — with
the same lookup. Core's catalog is added LAST, so a plugin can add strings but
never retranslate one of core's.

**The split rule:** a string only one plugin's runtime code uses lives in that
plugin's catalog; one that core uses, or that two plugins share, lives in core.
`CatalogCoverageTest::testNoCoreUnitServesASinglePlugin` and
`CatalogCompletenessTest::testNoSourceIsCataloguedInTwoSets` enforce it, and
every catalog gate iterates the sets through `tests/Support/Catalogs`, so a
plugin that grows a `translations/` dir is gated from its first commit.

## A plugin's docs are its own tree

A plugin's guide is `docs/README.md` beside its entry file, found by convention
like its catalog — no `PluginApi` verb. Unlike the catalog it is read from
EVERY installed copy, enabled or not: `PluginInstaller::docsRoots()` lists each
copy's `docs/` and `WidgetHooks` hands that closure to the help browser
(`MarkdownPageWidget::setMoreRoots()`), which re-asks it per navigation so a
live install is reachable. A link must resolve inside Porta's `docs/` or one of
those trees; anything else — the plugin's own `src/` included — is refused.
The Settings page's Documentation row reads `docsIndex()` (the copy that
loads) and raises `HelpPageRequested`, which `HelpViewController` alone
answers.

Every bundled plugin must ship one and be linked from `docs/README.md`
(`DocsIndexTest`), and `KeybindingsDocTest` holds a plugin's keys — its
surfaces' and its commands', the plugins loaded to read them — to that
plugin's docs, not to `docs/keybindings.md`. `tests/Support/DocsTree` is the
one walk over both trees.

## Panes and viewers

`registerViewer(id, claims)` says which files are mine; `registerPane(id,
factory, loader)` says what shows them. `registerPreview(id, claims)` says
which files open as TEXT with that pane beside them, fed the editor's text
rather than its loader's payload; it is asked first, below the 2 MB text cap. The loader runs on every open and
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

The ids that moved this way: `ide_todo`, `ide_local_history`, and the sixteen
git ones — the whole feature is a bundled plugin now, so `ide_git`,
`git_commit`, `git_push` and the rest are all adopted. `git_blame` and
`git_toggle_blame` are keyless in the catalog, because neither had a default
binding when core implemented them and a move is not the moment to invent one;
the others keep the keys and labels they always had, which is the entire point
of adopting rather than re-registering.

`ide_git` is the case that shows why the skip rule earns its narrowness. It was
deliberately NOT adoptable while core owned the git view — flagging it would
have hidden the menu item whenever nothing registered under `git`, which was
wrong for a core-owned toggle. A plugin implements it now, and registers neither
the view nor the verb outside a repository, so the skip is exactly right: the
whole Git group is hidden in the same case.

## A plugin file wires; `src/` implements

Four abstract bases in `Plugin/Api/` hold what plugin files used to copy:
`AbstractPlugin` (name, claimed languages),
`AbstractToolPlugin` (binary discovery, the single-flight slot, the active-file
guard, the report verbs), `AbstractAnalyzerPlugin` (the whole
run → decode → `setDiagnostics` → count flash) and
`AbstractSubprocessHighlighter` (the debounce, the latest-wins stamp, the
process ceiling, the token → themed-ANSI conversion).

**The test for what belongs in a base is the gate boundary, not
deduplication.** Plugin files are analysed like `src/` (`mago.toml` `paths`)
but graded for coverage by that plugin's OWN tests alone (`coverage:plugins`),
so logic core's suites must cover — shared by every plugin that extends the
base — moves into `src/` even at one caller.

Third-party plugins may extend these or implement `PortaPlugin` directly;
`PluginLoader` only ever checks `instanceof PortaPlugin`.

## Coverage runs a second pass, sharded

`composer coverage:check` is the parallel run over `src` (`coverage:src`,
core's Unit, Integration and E2e suites only — a `src` line only a plugin's
test reaches fails it) **plus** `composer coverage:plugins` — which is now `bin/porta-dev
coverage:plugins`, a fan-out of independent **serial** phpunit runs, one per
plugin test FILE, several at a time, each writing its own report into
`coverage/plugins/`. `coverage:check` grades that directory as one report.

Putting `plugins/*/src` into phpunit's `<source>` for the parallel run collapses
the total to 86–95%, differently every run. Each paratest worker serialises
coverage with paths relative to its own longest common directory, while the
merger keeps only the first worker's base path — so a worker that covered a
plugin file reduces to the repo root instead of `src`, every path it reports
resolves wrong, and that whole worker's data is dropped silently. No
project-side configuration prevents it. A serial run never merges.

**That property is per PROCESS, not per pass**, which is what makes the fan-out
legal where parallel paratest is not: 68 shards are 68 merge-free reports, and
the merge that does happen is `coverage:check`'s own, per LINE, over documents
it reads whole. Measured: **151s serial → 20.0 / 20.3s**, same total every run
(`100.00% (4284/4284)`, 127 files).

**One shard per FILE, not per directory, and three quarters of the cores.**
Those two go together, and either alone is worse than the pair. Interleaved,
same box, same run:

| lanes | per directory (16 shards) | per file (68 shards) |
| --- | --- | --- |
| 8 | 33.2 / 33.5s | 35.9 / 36.2s |
| 12 | 46.7 / 35.4s | **23.6 / 22.2s** |

A directory shard cannot finish before its biggest FILE — `git`'s loose shard is
33s on its own — so more lanes buy it nothing and cost it contention. File
shards pay a phpunit boot each (~1.5s × 68) and need lanes to hide it, and then
the floor is one file: `git/tests/View/GitViewControllerTest.php`, ~16s, named
in the run's own report because that is what the next split would have to
attack.

The shard list is derived, never written down: every `*Test.php` under a
plugin's `tests/`, named by its path INSIDE `tests/` — the path and not the
basename, or two `FooTest.php` in different subdirectories would share a report
and one of them would quietly stop counting. A list in a file is a second place
to add a plugin, and forgetting it stops running those tests while the
percentage stays green: the coverage gate only catches a dropped shard when
nothing else covers the same lines.

One thing the split gives up: `executionOrder="random"` no longer mixes two
plugin test files in one process, so order coupling between files is not
exercised HERE. It still is by `composer test`, whose paratest run has all four
suites and distributes by method — which is the run CI's `tests` job repeats
over the intl matrix.

Every shard keeps `--coverage-filter plugins`, the whole tree, not its own
plugin: `plugins/tempest/porta.php` has no tests of its own and is loaded by
every booted IDE, so a per-plugin filter would drop it from every report and
`--require=plugins` would fail a file that is fully covered.

`composer coverage` and each shard also set `pcov.directory` themselves —
`src` and `plugins`, what each pass grades — because pcov has its own
instrumentation window with a machine-level default, and a file outside it is
never measured no matter what phpunit says.

**A plugin's code must therefore be 100% covered by its own tests.** A corollary
worth knowing: a plugin file loaded by *another* plugin's tests enters the
report, because booting an IDE loads every default-enabled bundled plugin. Two
cures, both used — a suite's `TempProject`s name `enabledPlugins` explicitly
(including whatever the plugin under test `requires`, or `PluginOrder` skips it
and the suite reads as a mystifying timeout), and each plugin's E2e lives in its
own suite.
