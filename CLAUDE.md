# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Porta is a terminal IDE for PHP projects (namespace `Porta\`, PHP 8.4+), built on the
experimental `symfony/tui` component with an LSP client, syntax highlighting and
autocompletion. It runs the whole UI and all async I/O on a single Revolt event loop.

## Commands

```bash
composer dev                 # run the IDE (bin/porta)
composer test                # full suite via paratest (parallel, ~20s)
composer test:serial         # plain phpunit (serial) — use when debugging ordering/races
composer analyze             # mago static analysis — MUST be clean (zero issues/warnings/help)
composer coverage:check      # runs the suite with coverage and enforces 100% line coverage
composer format              # mago format (apply)
composer format:check        # mago format --check
composer lint                # mago lint

# Run a single test / filter
vendor/bin/phpunit --filter testName
vendor/bin/phpunit tests/Unit/I18n/BidiTest.php
```

Run gate commands capturing output rather than piping to a pager, e.g.
`composer analyze > /tmp/x.log 2>&1; echo "exit=$?"`.

### Definition of done (every commit must pass all four)

- `composer test` green. Two tests drive real git/opener subprocesses and were hardened
  against timing (`GitViewControllerTest::testLineStagingAndUnstagingThroughTheDiffPane`,
  the async URL-open E2e in `IdeDocsTest`); if one ever fails under heavy machine load,
  re-run with `--filter` to confirm before assuming a regression.
- `composer coverage:check` — **100% line coverage is required**. New code needs tests that
  hit every branch; a new branch with no covering test fails the gate.
- `composer analyze` — mago reports **zero** issues, warnings, or help messages.
- `composer lint` — **zero** findings. The `[linter.rules]` section of `mago.toml` disables
  the rules that conflict with house style (with rationale comments); everything else
  applies at full strength. CI (`.github/workflows/ci.yml`) runs all four gates.

## Conventions

- **Final classes + constructor injection everywhere.** Collaborators are threaded as
  constructor arguments — no class reaches for a global. Services and singleton widgets are
  **autoregistered** by a **Symfony DI container** (see the Architecture note on `Ide`); only the
  `$this`-closure-wired objects and the composite widget-tree assembly stay hand-constructed in
  `Ide`. When you add a dependency to a container-built class, autowiring handles it (a new
  autowirable class needs no config unless `Ide` resolves it by id — then mark it public); when
  you add one to a hand-built class, inject it and update every construction site (including tests).
- **No `$this` inside injected `static fn` closures** — mago rejects it.
- The formatter (`mago format`, Symfony-style) **never re-wraps lines** (print-width 200,
  preserve-breaking). Keep your own line breaks; don't fight it.
- mago analyzes `src` only (`tests`/`tools`/`vendor` excluded from reporting), so type
  annotations matter in `src`. `vendor` is included for type info: **`symfony/tui`'s `Renderer`
  and `ScreenWriter` are `final`** and cannot be subclassed — see `Porta\Ui\BidiTui` for the
  pattern used when a vendored seam doesn't exist (subclass `Tui`, override `processRender`).

## Architecture

**`Ide` (`src/Ide.php`, ~520 lines) is a thin composition root over a Symfony DI container.**
Its constructor builds the container via `Container/IdeContainerFactory`, hand-builds only the
bootstrap infra (Renderer/`Ui/BidiTui`/NonBlockingTerminal + StyleSheet), and otherwise
**resolves** the graph: shared services/widgets land in its private properties (kept as aliases
because ~100 reflection-based test seams read them), the `build*` steps
(`buildEditorArea`, `buildSidePanels`, `buildGitView`, `buildSettingsWidgets`,
`buildLayoutAndOverlay`) are now resolution + arrangement only, and the ctor tail runs
`Command/CommandRegistryPopulator::populate()` (tagged providers), `Bootstrap/WidgetHooks::wire()`,
the menu bake and registers the `Input/InputDispatcher` listener — commands register and menus bake at the **end** of the
constructor. Two services are resolved **eagerly for their side effect**: `GitViewController` and
`SettingsViewController` attach themselves to `Ui/WorkspaceViews` in their constructors and have
no inbound service references (without the eager resolve they would never exist).
`run()` sets up the terminal (alternate screen + SGR mouse modes 1000/1003/1006), calls
`LspBootstrapper::start()`, arms a 1s filesystem-poll timer, and enters `tui->run()`. Teardown
(mouse disable, `History/SessionRestorer::save`, LSP/git/run shutdown) is in the `finally`.

**The container is dumped and cached across boots.** Per-boot values travel through `$_ENV`
(never `putenv` — `PtyProcess` leaks `getenv()` into child shells) and resolve at runtime via
`%env()%` placeholders, so the compiled container is project-independent: scalars
(`rootDir`/`locale`/`ansi`/`theme`/`lspCommand`/`lspDisabled`/`docsDir` — the nullable one uses
`env('default::PORTA_LSP_COMMAND')`, where `''` means null) plus the per-project **config arrays**
JSON-encoded by `IdeContainerFactory` and consumed as `env('PORTA_LANGUAGES')->json()`-style
per-service args (never binds — `$overrides` means different things per consumer).
`IdeContainerFactory` dumps once into the user cache dir (`$PORTA_CACHE_DIR`, else
XDG/`~/.cache` + `/porta`), guarded by a debug-mode `ConfigCache` whose tracked resources
(services.php, the src/ glob, per-class reflection incl. method attributes) rebuild it
automatically on any wiring-relevant change. `PORTA_CONTAINER_CACHE=off` forces an in-memory
build (a builder used directly must `compile(resolveEnvPlaceholders: true)`; the dump path keeps
placeholders unresolved), and deleting the cache dir is always safe — do that if a
stale-container symptom ever survives an edit. `tests/bootstrap.php` pins the cache into
`.phpunit.cache/` and forces fresh compiles under pcov, and phpunit.xml.dist sets
`includeUncoveredFiles="false"` — together they keep php-code-coverage's static analyser (whose
executable-line map disagrees with pcov's on `return match` lines) out of the paratest merge,
which otherwise poisons the 100% gate with phantom uncovered lines.

The container owns everything expressible as service+scalar injection — **including every
singleton widget** (`#[Container/AsWidget]` declares tree id/flex, autoconfigured into
`setId`/`setStyle` calls) and all controllers. The non-obvious mechanics:
- **Typed factories**: `Keybindings` via `Config/KeybindingsFactory` (resolving it triggers
  `Plugin/PluginHost::ensureLoaded()`, the idempotent one-time plugin load, so plugin default keys
  merge into the immutable map); `Overlay/MenuOverlayPanes`; `PluginManager`/`PluginLoader`/
  `PluginSettings` exposed off `PluginHost`; `PluginInstaller::create` (environment-derived
  scalars); `SearchEverywhereController` via `Search/Everywhere/SearchEverywhereFactory`.
- **Duplicate-typed widgets** from the composite tree are per-getter services
  (`porta.widget.editor_body` → `[WorkspaceLayout, 'editorBody']`, same for main/git/settings
  areas and separators) fed through **typed-named binds**
  (`bind(ContainerWidget::class.' $editorBody', …)` — no leading backslash). A configured bind
  with **zero consumers fails the compile**, and same-type-same-name params
  (`SeparatorWidget $separator` in both mouse sub-routers) can't bind — those get explicit
  per-service `->arg('$separator', …)`.
- **Interface aliases** (one impl each): `FileOpener`/`BufferReopener`→`BufferService`,
  `EditorAreaPanes`→`PaneLayout`, `PluginLifecycleOps`→`PluginLifecycle`,
  `ActiveFileProvider`→`CodeEditorWidget`, `DapClientFactory`, `Scheduler`, `FloatingRegions`.
- **Tagged command providers**: `Command/CommandProvider` autoconfigures into the
  `porta.command_provider` tag; each provider carries `#[AsTaggedItem(priority: N)]`
  (Workbench 700 → Debug 600 → PluginCommandProvider 500 → File 400 → Search 300 → View 200 →
  Git 100) and `CommandRegistryPopulator` iterates them via `#[AutowireIterator]` — **priority
  order is contractual**: registration order IS the palette display order and core registers
  before plugins so core wins key collisions.

What stays hand-built in `Ide` (deliberate, ~520 lines total): the bootstrap infra above, the
widget-tree mount + pane arrangement calls, per-buffer runtime objects
(`Buffer`/`Document`/`CodeHighlighter`), and `$initialFile`/`$initialLine` handling in `run()`.
The widgets' runtime hook registrations (providers, viewer handlers, panel/menu close-focus flow,
the tree's open hook) live in the container-built `Bootstrap/WidgetHooks::wire()`, called once
at the ctor tail; the theme/locale menu-rebuild + RTL re-mirroring are the compiled
`Ui/MenuLayoutRefreshListener` (priority 0, ahead of the −100 chrome sweep). `Plugin/PluginRuntime`
and `Widget/EditorCallbacks` are plain services (the runtime consumes the narrow
`Editor/DiagnosticsSink` seam instead of BufferService). Four objects are handed to the container
as **synthetic** services right after `Ide` creates them: `Renderer`, `Tui`, `BidiTui` (external
`symfony/tui` infra) and `IdeConfig` (parsed before the container exists — its settings steer the
env parameters).

**Rules for the container** (`config/services.php`, deliberately **outside `src/`** so the wiring
stays off the 100%-line-coverage gate): services are **autoregistered** via
`$services->load('Porta\\', '../src/*')` excluding only `Ide.php` and `Container/` — the
non-service classes register private and are pruned at compile because nothing public references
them (*fragile*: if a **public** service ever gains a dependency on a non-service class,
compile fails with a Symfony autowiring error — explicit at boot, caught by the first E2e test).
`_defaults` stays **private**; the single public boundary is the `porta.ide` `ServiceLocator`
(plus the synthetics) — `Ide` resolves everything through its typed `service()` helper, and a new
class `Ide` needs by type must be added to the locator's entry list. Every def is **shared**;
**never `lazy: true`** — it proxy-subclasses, which breaks `final` classes.

**Events.** `symfony/event-dispatcher`, kernel-less: `config/services.php` registers the
`#[AsEventListener]` attribute autoconfiguration and the `RegisterListenersPass` (the service id
must be exactly `event_dispatcher`). Typed domain events live in `src/Event/`: the buffer
lifecycle (`BufferOpened`/`BufferSwitched`/`BufferSaved`, emitted by `BufferService`),
`GitCommitted`, `DebugStatusChanged`/`LspStatusChanged` (status-bar refresh), and the live-setting
events (`ThemeChanged`/`LocaleChanged`/`CursorShapeChanged`/`TabBarPositionChanged`, dispatched by
`Settings/LiveSettingsApplier`). Emitters autowire the **PSR** interface; `Ide` uses the component
interface for runtime `addListener` on hand-assembled state (menu rebuild, RTL re-mirroring).
Priorities are contractual: plugin bridge (`PluginEvents`) and the chrome sweep
(`Ui/ChromeRefreshListener`) run at **-100**, after core listeners.

**Single event loop.** `revolt/event-loop` drives both the `symfony/tui` render/input loop and
all async work (LSP over stdio, git subprocesses, the integrated terminal PTYs — one per
`Terminal/TerminalSession`, tabbed in the single `TerminalPanelWidget`, owned by `Run/RunController` — run
configurations). Nothing blocks; long operations are timers/callbacks on the shared loop.

**Bottom dock.** The terminal panel, the debug panel and the list tools share one dock above
the status bar, coordinated by `Ui/BottomDock` (also the owner of `Ui/ResultsPanelMode` routing
and the shared focus-return/flash choreography). **Every list tool lives as a `Ui/DockToolTab`
in the `Ui/DockToolTabs` model** — the model, not the widget, is the source of truth: the single
`ResultsPanelWidget` displays exactly one tab, and tool tabs **coexist** (each Find-in-Project
query, Problems, TODO, each Usages/Choose-target list, the LSP/notification logs, Local History
— one strip tab apiece, closed only by its ×, no cap). Instance identity is **(mode, title)**:
re-invoking a tool reuses and refreshes its tab; a different title appends. Switching tabs
(`BottomDock::bind`) preserves rows/title/searching (+ a Search tab's frozen
`Search/SearchTabState` — query/options/scope/mask, re-adopted by `ProjectSearchController` on
bind so F5/replace/export act on the displayed tab's own query); filter text, collapse, context
and selection reset — the accepted v1 trade-off of rebinding through the widget's `open()`.
Async completions (search runs, TODO scans, the live Problems/log refreshes via
`Ui/DockLiveRefreshListener` on `Event/DiagnosticsChanged`/`LspLogAppended`/`NotificationLogged`)
write into their captured tab and reach the widget only through `refreshIfBound` — an inactive
tab stays fresh in the model and a late grep can never clobber the tool on display.
`Widget/OverlayWidget` carries the panels' two axes: `isOpen()` (alive) vs `isVisible()`
(open AND revealed); at most one panel renders; `Esc` is `dismiss()` → dock hide (everything
stays alive) except the transient pickers (palette, go-to-symbol), which stay strip-less,
close for real, and on close **restore the tool tab they displaced** (`openQuery` captures it;
the palette routes through `openQuery`). `Widget/DockTabsWidget` is the one-row strip —
**the single level of bottom-pane tabs**: keyed entries (`aliveTabs()` — one per terminal
session (key = session id, label = session title + `○` when exited), one per tool instance,
Debug; the terminal panel renders no strip of its own), the displayed tab's `statusText()`
right-aligned (the terminal's carries `[scrolled +N]`), overflow drops LEADING tabs so the
active one stays visible; the strip row doubles as the panel-resize grab, and dragging a tab
reorders it **within its family** (session tabs → `TerminalSessions::move`, tool tabs →
`DockToolTabs::move`; cross-family endpoints no-op — `DockTabsWidget::familyIndexAt` /
`MouseRouter::continueDockTabDrag`). ctrl+` toggles the active session's tab
(`RunController::showActiveSession` via `onTerminalReveal`; a session-tab click routes
`makeActive` first); alt+page_down/up cycle every strip tab (`dock_next_panel`/
`dock_prev_panel`); a session tab's × closes that session for real (`onTerminalSessionClose` →
`RunController::closeSession`); ×ing a Search tab records its query/mask into history
(`closeSearch`); the last tool × closes the panel (MRU reveal). All three panels share one height (`bottomPanelHeightPercent`
→ `BottomDock::setPanelRows`). A debugger stop auto-reveals the Debug tab (re-opened, watches
reseeded, if ×'d). `Search/TodoScanner` (ide_todo, alt+8) greps `TODO|FIXME` case-sensitively
over the shared `ProjectSearch` engine (single slot — a scan and a find cancel each other,
latest wins).

**Rendering pipeline (`symfony/tui`).** Widgets (`src/Widget/**`) render to `string[]` lines
measured in **display columns** (`mb_strwidth`, `AnsiUtils::visibleWidth`) so CJK/wide chars and
ANSI-styled text align correctly. Widgets cache their rendered output; `invalidate()` only
bubbles *up*, so a change that must relabel the whole tree (e.g. a live locale switch) has to
clear render caches recursively. Layout is horizontal/vertical `ContainerWidget`s; sizes come
from rendered content, with proportions persisted per project.

**Commands & keybindings are single-sourced.** `Config/ActionCatalog` is the descriptor table
for every rebindable action (label + default keys). `Config/CommandRegistry` holds IDE-level
commands (id → handler, key-bound?, in-palette?). `Ui/MenuModel` builds the menu bar / context
menus from these. Don't hand-maintain parallel keybinding/palette/menu lists — extend the
catalog/registry.

**Editor core.** `Editor/Document` is the text buffer (byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indent via `.editorconfig`). `Editor/MultiCaret`
and `Editor/EditorActions` layer multi-cursor and editing operations; `Widget/CodeEditorWidget`
+ `Widget/Render/EditorRenderer` render the viewport (the caret is spliced in as a zero-width
APC marker that `ScreenWriter` converts to a hardware cursor position).

**LSP.** `Lsp/LspSessionPool` routes traffic to per-language sessions started lazily on the first
`didOpen` of a matching file; sessions are keyed by command (shared processes for JS/TS on one
server). Feature controllers (`Lsp/NavigationController`, `Lsp/RefactorController`,
`Lsp/CompletionController`) extend `Lsp/AbstractLspController`.

**Debugger (DAP).** `Debug/DapClient` speaks the Debug Adapter Protocol over a child process's
stdio (framing shared with LSP via `Support/JsonRpcFramer`); `Debug/DapSession` is one launch's
order-agnostic state machine (0-based lines internally, 1-based on the wire; conditional/exception
breakpoints, run-to-cursor via a wire-level one-shot, evaluate); `Debug/DebugController`
(container-autowired) owns the `BreakpointStore`/`WatchStore`/`ExceptionFilterStore` (persisted to
`.porta/debug.json` by `Debug/DebugStatePersistence`, restored before the first buffer opens so
gutter dots paint at boot), the editor decorations (gutter `●`/`◆`, `Role::DebugLineBg` stopped-line
band), the `Widget/DebugPanelWidget` dock panel (frames + watches + lazily-expanded variables) and
a `Terminal/TerminalSession` (`'debug'`) for debuggee output — created and made active but not
shown: it sits one dock tab away (ctrl+` / alt+page_down) while the Debug tab holds the slot. Adapters are config-only:
`.porta.json` `languages.<id>.debug = {adapter, launch}` (Settings → language section), registry
in `Debug/DebugAdapters`. Keys: Shift+F9 debug, Ctrl+F8 breakpoint (also gutter click); F7/F8/F9/
Shift+F8/Ctrl+F2 are gated by `CommandRegistry`'s `when` closures so they fall through to widget
bindings when no session runs. Tests script `tests/Support/mock-dap-adapter.php`.

**Input routing.** `Input/MouseRouter` maps every SGR mouse event to the pane under the pointer
and owns cross-event drag/click state. Hit-testing uses the renderer's per-widget rects.

**Plugins.** User PHP loaded at boot from three sources in precedence order —
`<project>/.porta/plugins/*/plugin.php`, `~/.porta/plugins/*/plugin.php` (`PORTA_HOME` overrides
the home dir), and the plugins **bundled in the Porta package** (`PORTA_EMBEDDED_PLUGINS`
overrides; earlier sources shadow later ones by directory name; phpunit pins BOTH env vars to
`/nonexistent`, so tests opt in via putenv). Names in `.porta.json`'s `disabledPlugins` are
skipped at boot. The subsystem is container-owned: `Plugin/PluginHost` builds the
settings/manager/loader triple from the synthetic `IdeConfig`, and `ensureLoaded()` (idempotent,
triggered by the `Keybindings` factory) runs each file's `activate(PluginApi)` **before** the
(immutable) `Keybindings` map is built so plugin default keys merge into it (user `.porta.json`
overrides them; ids are
namespaced `plugin:command` and bypass the ActionCatalog keymap validation — plugin ids stay
OUT of `ActionCatalog::ACTIONS`). Registration buffers in the per-plugin `PluginApi` and commits
atomically after `activate()`; action methods (flash/editor access/openFile) resolve services
lazily via `Plugin/PluginRuntime` (wired late in the ctor) and throw during `activate()`. Every
plugin handler and hook is wrapped in try/catch → status-bar flash, so a faulting plugin never
kills the loop. Hooks (`fileOpened`/`fileSaved`/`bufferSwitched`) fan out from
`Plugin/PluginEvents`, which is a `#[AsEventListener(priority: -100)]` bridge on the app event
bus: `BufferService` dispatches typed `Event/BufferOpened`/`BufferSwitched`/`BufferSaved`
events (symfony/event-dispatcher, wired kernel-less in `config/services.php` — the
`event_dispatcher` service id is mandatory for `RegisterListenersPass`), core listeners
(git marks, debug decorations, LSP save/inlay) react at priority 0, and the plugin bridge
observes last, deduplicating consecutive switch paths for plugins only. Plugin commands land in
`CommandRegistry` after core (core wins key collisions) and surface in the palette plus a
trailing translated "Plugins" menu (`MenuModel::build`'s `$pluginSpecs`, grouped per plugin
with separators). Prefer anonymous
classes in plugin files — cross-plugin class redeclaration is an uncatchable fatal. Four complete
reference plugins ship in this repo under `.porta/plugins/`: `php-cs-fixer` (async process via
`Support/AsyncProcess`, binary discovery via `PluginApi::projectRoot()`, persisted
`autoFormat` on-save setting), `phpstan` (publishes editor diagnostics via
`PluginApi::setDiagnostics`, which rides `BufferService::applyDiagnostics` — inline messages,
stripe, Problems panel — persisted `autoLint`), `mago` (both patterns in one: format-in-place +
JSON-lint diagnostics, separate `autoFormat`/`autoLint` settings, both on chains format→lint),
and `psalm` (the phpstan pattern for Psalm's JSON array output — 1-based lines/columns,
exit 2 = issues found). The on-save toggle commands flip the persisted settings via
`PluginApi::changeSetting()` (an action method mirroring `flash()`'s runtime gate).
Their E2e tests load the real files from those paths.
Settings → Plugins installs/removes plugin dirs (`Plugin/PluginInstaller`: git clone for URLs,
recursive copy for local dirs; per-install project/global scope; bundled plugins list as
built-in and refuse removal). The live lifecycle lives in `Plugin/PluginLifecycle`
(activate/deactivate/setEnabled + the settings-nav sync): **live activation** —
`PluginLoader::loadFile` + `PluginManager::registerCommands` (incremental via a watermark) +
`Ui/MenuRebuilder::rebuild` — and **live
deactivation** on removal/disable: `PluginManager::deactivate` filters its state, resets the
watermark to the filtered count (safe: everything is registered before any deactivate),
`CommandRegistry::remove` kills palette + key dispatch, `PluginEvents::removeFor` (listeners
are keyed per plugin) drops hooks; removing a shadowing copy reactivates the next copy down.
Default keys still need a restart (immutable `Keybindings`) — a dead binding just falls
through. Every plugin's settings page (a `plugin:<name>` nav sub-item) has an Enabled toggle
(persisted in `disabledPlugins`) plus its `PluginApi::registerSetting` rows
(`Plugin/PluginSettings`, values in `.porta.json` `plugins.<name>.<key>`, read via
`PluginApi::setting()` — works during activate() because the config seeds first). Menu items
take an `enabledWhen` closure (dimmed when false; the Plugins menu sits between Code and Run).
Removal rows use the settings form's `'action'` row kind (two-press confirm in
`Config/SettingsPluginsController`).

**Per-project config: `.porta.json`** (parsed by `Config/IdeConfig`, handed to the container as
a synthetic service) with keys `keymap`, `settings`, `languages`, `lsp`. Settings
(`Config/SettingsController` + the `Settings/` view) all apply live through
`Settings/LiveSettingsApplier` — theme swaps the `SwitchablePalette`/`SwitchableTerminalTheme`
holders and rebuilds the highlighters, the locale relabels the tree, the cursor shape swaps the
`CursorStyle` holder, the tab-bar position re-arranges the strip — each apply ends in a typed
`src/Event/` event (menu rebuild + RTL re-mirroring listen in `Ide`; the -100
`ChromeRefreshListener` sweeps the chrome caches last).
`.editorconfig` is always honored (superseded per language by the indent settings); legacy
setting keys (`IdeConfig::LEGACY_SETTINGS`) load silently. The `restart` descriptor flag is
retained but unused.

**i18n / RTL.** `I18n/Translator` wraps `symfony/translation`; the English source string *is* the
message id (so untranslated lookups fall back to English) with XLIFF catalogs in `translations/`
(`messages.<locale>.xlf`). RTL (Hebrew) mirrors the layout (reversed pane order) and, via `Ui/BidiTui`,
reorders each composed row **per pane** — `Ui/PaneSegmenter` splits the row into each pane's column
run (from the renderer's `WidgetRect`s + the menu/context dropdown regions), and `I18n/PaneReorder`
flips each run within its own span via `I18n/Bidi` (a UAX#9 subset), so the reorder never crosses a
pane boundary. Code panes marked `Ui/LtrPane` (editor, diff, terminal, results) stay verbatim. Mouse
clicks map visual→logical per pane (`BidiTui::logicalColumn` → `PaneReorder`) before hit-testing.

## Tests

`tests/Unit`, `tests/Integration`, `tests/E2e`. E2e tests boot a real `Ide` against a
`VirtualTerminal` and a `TempProject` through the shared `tests/Support/RunsIde` trait: steps
(`ms => fn($tui, $terminal, $ide)`) run **sequentially chained** — each schedules the next by
key delta after it completes, so a step may `$this->await(fn: bool)` (pumping the live loop for
subprocess/async effects) without a later step firing underneath it. Without an explicit
`$duration` the loop stops ~80ms after the last step; pass a duration only when effects land
after the final step — better, await them in the step. When a constructor gains a dependency,
fix the direct construction sites in these tests too.
