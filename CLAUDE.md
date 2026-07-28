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

### Definition of done (every commit must pass all five)

- `composer test` green. Two tests drive real git/opener subprocesses and were hardened
  against timing (`GitViewControllerTest::testLineStagingAndUnstagingThroughTheDiffPane`,
  the async URL-open E2e in `IdeDocsTest`); if one ever fails under heavy machine load,
  re-run with `--filter` to confirm before assuming a regression.
- `composer coverage:check` — **100% line coverage is required**. New code needs tests that
  hit every branch; a new branch with no covering test fails the gate.
- `composer analyze` — mago reports **zero** issues, warnings, or help messages.
- `composer lint` — **zero** findings. The `[linter.rules]` section of `mago.toml` disables
  the rules that conflict with house style (with rationale comments); everything else
  applies at full strength.
- `composer arch` — the **architecture ratchet** (`tools/architecture-check.php`): the
  exact namespace import graph, class-level acyclicity (two documented SCCs allowed),
  and the E2e suite-size bound. It fails on drift in EITHER direction — a new edge needs
  adding to the embedded allowlist **in the same PR** (that is the review point), and a
  vanished edge must be removed from it. CI (`.github/workflows/ci.yml`) runs all five gates.

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

## File organization

FQCN is 100% determined by path (`Porta\ => src/`, `Porta\Tests\ => tests/`; no aliases), so a
move is a pure rename — edit the `namespace`, every `use`, the mirrored test's path + namespace,
and (only for a public service in the `porta.ide` locator list, or an `Ide`-resolved one) its
`use` in `config/services.php` / `src/Ide.php` (the `X::class` entries name the short symbol, so
they don't change). `composer analyze` + the container compile (`composer test`) catch any
dangling reference; grep the old namespace to prove zero. Membership rules:

- **`Overlay/`** — floating surfaces, three tiers: `State/` (mutable per-surface models),
  `Presenter/` (per-frame span producers), `Region/` (geometry/hit-test VOs). Stateless render
  helpers stay in `Widget/Render/` (see Floating surfaces below).
- **`Ui/`** — the primitive vocabulary ONLY (theme: `Role`/`Sgr`/`Glyphs`/`CursorStyle` +
  `Ui/Theme/`; `BidiTui`, `PaneSegmenter`, `TextField`, `MultilineInput`, `ClickZones`,
  list/shortcut helpers…). It is the codebase's most-depended-on namespace, so it imports
  nothing feature-owned — anything that composes features does not belong here.
- **`Workbench/`** — the composition tier over the features: the view-interlock registry
  (`WorkspaceViews`/`WorkspaceView`/`FullScreenView`), `Notifier`/`NotificationLog`
  (**`Notifier::notify()` ends in `requestRender()`** on both the toast and status-bar paths —
  never follow a flash with your own render request; round 8 deleted 41 such dead calls),
  `StatusBarProvider`, focus (`FocusRing`/`FocusCoordinator`), the chrome sweep pair, and the
  two subsystem **models** — `Workbench/Menu/` (menu model + opener/builder/rebuilder) and
  `Workbench/Dock/` (dock tab model, `BottomDock`). Their **widgets**
  (`MenuBarWidget`, `ContextMenu`, `DockTabsWidget`, `ResultsPanelWidget`) live in `Widget/`.
- **`Settings/`** — all settings controllers + the row model (`SettingsRow`/`SettingsRowKind`).
  `Config/` keeps only `.porta.json` parsing (`IdeConfig`) and the Action/Command catalogs.
  Settings **widgets** stay in `Widget/Settings/`.
- **`Widget/`** — classes that render (`#[AsWidget]` or produce `string[]`). Non-rendering
  widget-internal helpers (row DTOs, enums, traits) go in `Widget/Support/`. `Widget/Render/` is
  the stateless render-algorithm tier. Round 8's shared-row traits live in `Widget/Support/`:
  `StylesDefault` (the `$styles ?? Styles::dark()` ctor pair; `EditorRowAssembler` is the one
  non-adopter — a **readonly class cannot use a trait with a non-readonly property**, and mago
  rejects readonly writes outside a constructor, so it keeps the inline pair),
  `ProviderFingerprint` (template-typed provider + `setProvider` + the json_encode repaint guard;
  `StatusBarWidget` deliberately keeps its own — its lastLeft/lastRight compare is load-bearing
  render state, not a repaint guard), `StripScroll` (scroll offset + ‹/› indicator zones),
  `ColumnHover` (column-stored hover + `clearHover`/`storeHover`; `TabBarWidget`'s CELL-stored
  hover is a different model and stays), `MenuNavigation` (the two menus' onNavigate/afterNavigate;
  their keymaps do NOT merge — the bar carries left/right the context menu has no axis for), and
  `PanelWidth` now defaults `minWidth`/`maxWidth` to 24/200 (override only where a panel genuinely
  differs). The single tab cell lives in `Widget/Render/TabStripRenderer` as
  `measureCell`/`renderCell` (TabCell merged in — 100% co-change, same tier).
- Suffix homes: mouse/keymap routers in `Input/`, typed events in `Event/`, LSP controllers in
  `Lsp/`, `*Store` state beside its domain.

**Autoloading.** `composer.json` sets `config.optimize-autoloader` (precomputed classmap every
boot; PSR-4 fallback keeps new files working in dev). CI's `composer install` adds
`--classmap-authoritative` (safe: `src/` is PSR-4-clean, the only dynamic `class_exists`/`new
$class` pass `autoload: false`, and bundled `.porta/plugins/*/plugin.php` are `require`d directly,
never Composer-autoloaded). Every test namespace must mirror its path under `tests/` or the
authoritative classmap rejects it — `composer dump-autoload --classmap-authoritative` surfaces any
PSR-4 violation. Making `composer.json` itself `classmap-authoritative` was considered and
**deliberately not done**: it would drop the PSR-4 fallback and break new-file autoload in dev
until a manual re-dump, so authoritative mode stays a CI-install-only concern (the dumped container
class is `require_once`d by path, never Composer-autoloaded, so it is unaffected either way).

## Architecture

**`Ide` (`src/Ide.php`, ~400 lines) is a thin composition root over a Symfony DI container.**
Its constructor builds the container via `Container/IdeContainerFactory`, hand-builds only the
bootstrap infra (Renderer/`Ui/BidiTui`/NonBlockingTerminal + StyleSheet), and otherwise
**resolves** the graph into private properties — but only the ones its own runtime logic reads.
Tests reach every other service through the single `@internal Ide::resolve(X::class)` seam (a
`porta.ide` locator lookup with a container fallback for the synthetics), never by reflecting
private fields, so `Ide` holds no test-only alias fields or `get*()` getters. Resolution is
**deliberately two methods** (round 8's measured verdict on the merge candidate): the private
`service()` is locator-only, so a class that forgets `#[AsIdeService]` fails loudly instead of
silently resolving through the raw container; `resolve()` adds the container fallback that exists
solely for the four synthetics. Both assert the type; the shared docblock in `Ide.php` carries the
rationale — do not merge them. The `build*` steps
(`buildEditorArea`, `buildGitView`, `buildSettingsWidgets`, `buildLayoutAndOverlay`) are now
resolution + arrangement only, and the ctor tail runs
`Command/CommandRegistryPopulator::populate()` (tagged providers), `Bootstrap/WidgetHooks::wire()`,
`Bootstrap/EagerServices::boot()` (tagged eager services — see below), the menu bake, registers
the `Input/InputDispatcher` listener, and finally `Bootstrap/StartupWiring::wire()` (hands the
plugin handlers their live `Plugin/PluginRuntime` seam + flashes the config/plugin load warnings —
a `WidgetHooks` sibling kept separate so neither constructor grows unwieldy) — commands register
and menus bake at the **end** of the constructor. Services that must be constructed **eagerly for a self-registering side effect** (they
attach to `Workbench/WorkspaceViews` or hook `BidiTui::onBeforeFrame` and have no inbound reference, so
without a forced resolve they never exist) carry **`#[Container/AsEager(priority)]`**; the attribute
tags them `porta.eager`, and `Bootstrap/EagerServices` iterates the tag once at the ctor tail to
construct them (the `CommandRegistryPopulator` tagged-iterator pattern). The priority fixes
contractual construction order (the find→picker→toast presenters). Adopters:
`GitViewController`/`SettingsViewController`/`WelcomeModalController` (attach to WorkspaceViews),
the three overlay presenters, and `ResponsiveLayout`. The **chrome roots** the live-setting
appliers sweep (theme/locale/cursor/tab-position → recolor/relabel the whole tree) declare
themselves the same way with **`#[Container/AsChromeRoot]`** (tag `porta.chrome_root`);
`Workbench/ChromeInvalidator` injects them as a `#[AutowireIterator]` (order-independent — it only clears
render caches), so there is no `register()` list in `Ide`. The eight chrome widgets carry the
attribute; the ninth root (`$main`, the `porta.widget.main` getter service) is tagged at its def —
the `Keybindings`/`event_dispatcher` exception. `tests/Unit/Container/ChromeRootMembershipTest`
guards the tagged set.
`run()` sets up the terminal (alternate screen + SGR mouse modes 1000/1003/1006), calls
`LspBootstrapper::start()`, arms a 1s filesystem-poll timer, and enters `tui->run()`. Teardown
(mouse disable, `History/SessionRestorer::save`, LSP/git/run shutdown) is in the `finally`.

**The container is dumped and cached across boots.** Per-boot values travel through `$_ENV`
(never `putenv` — `PtyProcess` leaks `getenv()` into child shells) and resolve at runtime via
`%env()%` placeholders, so the compiled container is project-independent: scalars
(`rootDir`/`locale`/`ansi`/`theme`/`lspCommand`/`lspDisabled`/`docsDir` — the nullable one uses
`env('default::PORTA_LSP_COMMAND')`, where `''` means null) plus the per-project **config arrays**
JSON-encoded by `IdeContainerFactory` and consumed at the **consumer param** with
`#[Autowire('%env(json:PORTA_LANGUAGES)%')]` (never central binds — the param names its own channel,
so the `$overrides` split stays explicit: `SettingsLanguageController` ← `PORTA_LANGUAGES`,
`SettingsLspController` ← `PORTA_LSP_SERVERS`; dump-safe because the arg is just an env placeholder).
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
  merge into the immutable map); `Overlay/State/MenuOverlayPanes`; `PluginManager`/`PluginLoader`/
  `PluginSettings` exposed off `PluginHost`; `SearchEverywhereController` via
  `Search/Everywhere/SearchEverywhereFactory`. **Static self-factories declare themselves on the
  class** with `#[Autoconfigure(constructor: '…')]` instead of a central `->factory()` def:
  `HighlightPreferences::fromConfig` (its `%env(json:PORTA_HIGHLIGHT)%` arg via `#[Autowire]` on the
  param), `Plugin/PluginInstaller::create` and `Plugin/ToolInstaller::create` (environment-derived
  scalars; their `$rootDir`/`Translator` args autowire). A collaborator-instance factory
  (`[PluginHost, 'manager']`) or a cross-class one (`Themes::createSwitchable` → `Styles`) can't use
  it and stays an explicit def.
- **Duplicate-typed widgets** from the composite tree are per-getter services
  (`porta.widget.editor_body` → `[WorkspaceLayout, 'editorBody']`, same for main/git/settings
  areas and separators). The consumer selects which one it wants with
  **`#[Container/LayoutSlot('editor_body')]`** on the param — a thin `#[Autowire]` subclass
  (`service: 'porta.widget.'.$slot`), so the stock AutowirePass resolves it natively (matched via
  `IS_INSTANCEOF`, no compiler pass), the way `#[AutowireIterator]` does for tags. That moves the
  disambiguation to the param that knows the answer, so there are **no central binds** and the two
  same-type-same-name `SeparatorWidget $separator` routers just carry different slots
  (`separator` / `git_separator` / `settings_separator`). The scoped Search Everywhere instances
  (`porta.go_to_file`/`command_palette`/`go_to_symbol`) are selected the same way with a plain
  `#[Autowire(service: 'porta.go_to_file')]`.
- **Interface aliases are auto-derived by Symfony's loader itself** — round 9 measured the old
  `SingleImplAliasPass` against it and deleted the pass (it added ZERO aliases):
  `PhpFileLoader::load()` ends in `registerAliasesForSinglyImplementedInterfaces()`, which aliases
  every `Porta\` interface with exactly **one** discovered implementation
  (`FileOpener`→`BufferService`, `EditorAreaPanes`→`PaneLayout`,
  `ActiveFileProvider`→`CodeEditorWidget`, …) and skips any that gains a second. A **multi-impl**
  interface (`FloatingRegions`, `SyntaxHighlightProviderSource` — two service impls each) stays an
  explicit `alias()`; cross-vendor interfaces (`ClockInterface`, `TerminalTheme`,
  `EventDispatcherInterface`) are out of scope and stay explicit too.
  `tests/Unit/Container/ContainerAliasesTest` pins the derivation (load-only builder — compiling
  strips private aliases) and the compiled FrameHooks wiring.
- **Tagged command providers**: `Command/CommandProvider` autoconfigures into the
  `porta.command_provider` tag; each provider carries `#[AsTaggedItem(priority: N)]`
  (Workbench 700 → Debug 600 → PluginCommandProvider 500 → File 400 → Search 300 → View 200 →
  Git 100) and `CommandRegistryPopulator` iterates them via `#[AutowireIterator]` — **priority
  order is contractual**: registration order IS the palette display order and core registers
  before plugins so core wins key collisions.
- **Tagged settings sections**: `Settings/SettingsSection` (`handles`/`rows`/`change`)
  autoconfigures into `porta.settings_section`; the six adapters in `Settings/Section/` delegate to
  the eight settings controllers (which keep their differing `rows()` arities and setter spellings
  — that is what the adapters are for). `SettingsViewController` iterates them via
  `#[AutowireIterator]` and takes the **first** section whose `handles()` claims the nav id.
  **Priority is contractual for a sharper reason than the palette's**: `Section/GeneralSection`
  claims *every* id as the fallback, so promoting it above another section makes that section's
  page unreachable **silently** (the general form still renders). `Interface 600 → Highlighting
  500 → Plugins 400 (owns both `plugins` and `plugin:<name>`) → Git 300 → Language 200 (merges the
  languages/lsp/debug controllers) → General 100`, pinned by
  `tests/Unit/Container/SettingsSectionOrderTest`. `keybindings` is deliberately **not** a section:
  it is the one nav id that swaps the content *widget*, so it stays in `selectSection()`. Adding a
  page still touches `Widget/Settings/SettingsNavWidget` (nav order/headers/language list).

**Role interfaces over the editor widget.** `CodeEditorWidget` has 91 public methods, so consumers
depend on the narrow role they use, not the widget: `Editor/ActiveFileProvider` (`getFilePath`),
`ActiveDocumentProvider` (+`getDocument`), `EditorTextAccess` (+`insertText`/`jumpTo` — the seam
`Plugin/PluginRuntime` exposes to plugins, so the whole widget is *not* public plugin API),
`EditorInvalidator`, `ModalHost` (`openListOverlay`/`openPrompt`), `EditorDecorationSink` (the eight
decoration setters), `EditorViewOptions` (the display toggles; the six flags themselves live in
`Editor/EditorDisplayOptions`, which takes the invalidate closure and repaints only on a real
change — re-selecting a setting you already have must not flash the pane), `EditorIndentTarget`, `EditorKeymapProvider`, and `Workbench/Menu/EditorCommandTarget`
(the menu chain). the loader's native single-impl aliasing covers each with **zero** container config. A consumer
whose methods span TWO roles takes **two params of the same widget** (each resolves through its
own alias natively; tests hand in two narrow fakes): `History/LocalHistoryController`
(`ActiveFileProvider` + `EditorInvalidator`), `Git/BlameAnnotations` (`ActiveFileProvider` +
`EditorDecorationSink`), `Search/Everywhere/SearchEverywhereFactory` (`SearchOverlay` +
`EditorTextAccess`). Consumers stay concrete **on purpose**, each noted at the site:
`Lsp/AbstractLspController` (its three subclasses reach the inherited `$editor` for eleven
methods), `Git/GitRemoteCommands`, `Editor/EditorFileActions` and `Run/RunController` (they also
hand the widget to `setFocus()` / `EditorFindTarget`), `Input/EditorLayoutPanes` (the mouse
routers use a wide slice by design — narrowing it is the rejected `Clickable` idea), and the
one-method callers whose method lives in no role — `Tree/TreeOperations` (`showDocument`),
`Editor/BreadcrumbTrailProvider` (`scopes`), `Workbench/Menu/ContextMenuOpener` (`caretScreenCell`):
**one-consumer interfaces are forbidden**, as is widening an existing role for one caller.
`WorkspaceLayout`/`EditorArea`/`WorkbenchCommands` want *a widget*; typing them `AbstractWidget`
would make the param ambiguous to autowiring, so they stay too. `BufferService` (25 public
methods, 13 concrete consumers) was re-measured: no consumer uses only the `FileOpener` slice,
so it keeps its concrete hints beyond the extracted `DiagnosticsSink`. `Editor/BufferNavigator`
IS the image viewer's handler (round 8: `ImageViewerActions` was 80% verbatim forwarding to it,
so it implements `ImageViewerHandler` itself — four hooks are its own tab actions, plus the
`viewChanged` status-bar ping); `Help/HelpViewerActions` stays a real adapter on purpose —
folding it would push six deps into whichever caller absorbed it.

**Full-screen views are a registry, not three fields.** `Workbench/WorkspaceViews` keys
`Workbench/FullScreenView` controllers (isOpen/open/close/toggle/focusTarget/`cycleFocus(int): bool`)
by the `Workbench/WorkspaceView` pure enum, whose exhaustive `ownsMainArea()` match makes Welcome's
floating nature declarative: Welcome is *active* without *detaching* the main area, so
`isAnyOpen()`/`isAttached()` range over `ownsMainArea()` cases only — a 4th view fails
`composer analyze` until it decides. Every verb (toggle-with-interlock, closeAll, focusTarget,
cycleFocus) ranges over the registry; a new view is one enum case + one `attach()` call. Only
the two view-specific capabilities keep concrete controllers, via the typed `attachGit`/
`attachSettings` wrappers (`openGitFileHistory`, `refreshSettings`) — callers can't hold the
controllers themselves (the construction cycles the registry breaks). Welcome's
`cycleFocus` returns **false** so `FocusCoordinator` falls through to the editor ring while the
dispatcher's modal branch swallows keys. `FullScreenView` has three impls, so no alias is
derived for it — instances travel through `attach()`, never autowiring. The mouse/hover routers'
per-view rects and `Input/ActiveView` stay as they are on purpose: the routers own wide
per-view slices by design, and `ActiveView` is a different vocabulary (has `Editor`, lacks
`Welcome`), not a duplicate of `WorkspaceView`.

**Namespace direction (measured, round 5).** The import graph had 42 top-level cycle pairs;
the two stable-dependency inversions worth fixing were fixed: `Support` no longer holds a Ui
service (`DocsUrlOpener` consumes the Support-owned `FlashSink`, which `Notifier` implements),
and `Ui` was split — its efferent count dropped **20 → 6** (Container/Editor/Highlight/Input/
Overlay/Support, all vocabulary-benign) while 19 namespaces still depend on it. `Workbench/`
absorbed the feature-composing half; its 18 outbound edges are its job. The **cross-tier VO
relocation was measured and mostly rejected**: for Git/Settings/Tree/Debug/Highlight the
widget→domain-VO edges are the *correct* layering direction (views reading domain shapes) and
the remaining cycles are controller/domain cohabitation artifacts — relocating those shapes
would make parsers depend on views, and `SettingsRow`'s home is documented membership. Only
`RowSelection` moved (a vocabulary enum that sat in `Overlay/State`; its docblock already
named `Widget/Support`'s `OptionTagState` as its mirror). Do not re-litigate the other
thirteen without new evidence. Round 6 verified the cycle question at **class**
granularity (550 classes, 1534 code edges): exactly one real SCC exists — the seven
classes around `WorkspaceViews`, closed solely by its two documented `attach*()` setter
fields, with construction remaining a DAG — plus a degenerate `Ide::VERSION` constant
read and one pure `{@see}`-docblock artifact (`SpanSlicer`/`FloatingRegions` import
`MenuOverlayController` for prose only — a naive import-graph tool will report an Overlay
cycle that does not exist in code). **The namespace-cycle topic is closed.**

What stays hand-built in `Ide` (deliberate, ~520 lines total): the bootstrap infra above, the
widget-tree mount + pane arrangement calls, per-buffer runtime objects
(`Buffer`/`Document`/`CodeHighlighter`), and `$initialFile`/`$initialLine` handling in `run()`.
The widgets' runtime hook registrations (providers, viewer handlers, panel/menu close-focus flow,
the tree's open hook) live in the container-built `Bootstrap/WidgetHooks::wire()`, called once
at the ctor tail; the theme/locale menu-rebuild + RTL re-mirroring are the compiled
`Workbench/Menu/MenuLayoutRefreshListener` (priority 0, ahead of the −100 chrome sweep). `Plugin/PluginRuntime`
and `Widget/EditorCallbacks` are plain services (the runtime consumes the narrow
`Editor/DiagnosticsSink` seam instead of BufferService). Four objects are handed to the container
as **synthetic** services right after `Ide` creates them: `Renderer`, `Tui`, `BidiTui` (external
`symfony/tui` infra) and `IdeConfig` (parsed before the container exists — its settings steer the
env parameters). They are declared **public** so that, with the `porta.ide` locator, they form the
container's public surface — `Ide::resolve()`'s container fallback returns them for tests.

**Rules for the container** (`config/services.php`, deliberately **outside `src/`** so the wiring
stays off the 100%-line-coverage gate): services are **autoregistered** via
`$services->load('Porta\\', '../src/*')` excluding `Ide.php`, `Container/`, and (round 9) the
measured 100%-non-service tiers — `Console/`, `Event/`, `Git/*Parser.php`, `Highlight/Json/`,
`Widget/Render/`, `Widget/Support/` (a class there that becomes a real service must be
un-excluded; that edit is the review point — Symfony's error names the exclusion explicitly).
Non-service classes in MIXED directories still register private and are pruned at compile
because nothing public references them (*fragile*: if a **public** service ever gains a
dependency on a pruned class, compile fails with a Symfony autowiring error — explicit at boot,
caught by the first E2e test). `Config/EditorConfig/` looked non-service but is not
(`EditorConfigResolver` autowires into `BufferService`) — measure with the compiler, not the
dump.
`_defaults` stays **private**; the single public boundary is the `porta.ide` `ServiceLocator`
(plus the synthetics) — `Ide` resolves everything through its typed `service()` helper. **Locator
membership is declared on the class with `#[Container/AsIdeService]`** (autoconfigured into the
`porta.ide_service` tag; the locator is a `tagged_iterator` keyed by service id = FQCN, so
`service(X::class)`/`$locator->get(X::class)` resolve unchanged) — a new member marks itself, no
central list to update. Two members can't carry the attribute and are tagged at their def:
`Keybindings` (factory-built) and `event_dispatcher` (with an explicit `key => EventDispatcherInterface`
since the interface is an alias). **`tests/Unit/Container/IdeLocatorMembershipTest`** still guards the
`Ide` side — it tokenizes `Ide.php` for `service(X::class)` targets and fails if any isn't a member,
so a forgotten `#[AsIdeService]` is a loud test failure, not a boot crash. Every def is **shared**;
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
(`Workbench/ChromeRefreshListener`) run at **-100**, after core listeners.

**Git.** `Git/GitProcessRunner` owns the only mutable state in the layer — the in-flight process
map — so `Git/GitInfo`'s thirty-odd porcelain verbs are stateless argv-builders over it.
`GitInfo::isBusy()` stays on GitInfo as a delegator: it is a **load-bearing E2e completion barrier**
(git mutates the tree before the exit callback fires, so `!isBusy()` means the op landed on disk).
Output parsing lives in the `Git/*Parser` tier — `BlameParser` (whole-file `parse()` **and**
single-line `parseLine()`), `BranchParser`, `LogParser`, `StatusParser`, `HunkParser`.
The view's axes are all enums, all **pure** (nothing crosses a persistence boundary — backed is
for `.porta.json`-crossing values like `Theme`): `GitViewMode` (Changes|History left-pane tab;
`GitTabsWidget` renders `cases()` with an exhaustive label match) and `DiffSide`
(Worktree|Cached|Untracked — what a staging selection *means* in `GitStagingService`), beside
`CommitMode`/`RefreshMode`/`HistoryReload`/`WhitespaceMode`. The two git list widgets share
`Widget/Git/GitPaneKeys` (fetch/pull/push + exit/focus-next), a trait that **composes**
`use PaneNavigationCallbacks;` internally — that is what satisfies mago's isolated trait
analysis without byte-identical redeclarations; `PaneNavigationCallbacks` itself stays narrow
for its settings-pane users.

**Single event loop.** `revolt/event-loop` drives both the `symfony/tui` render/input loop and
all async work (LSP over stdio, git subprocesses, the integrated terminal PTYs — one per
`Terminal/TerminalSession`, tabbed in the single `TerminalPanelWidget`, owned by `Run/RunController` — run
configurations). Nothing blocks; long operations are timers/callbacks on the shared loop.

**Bottom dock.** The terminal panel, the debug panel and the list tools share one dock above
the status bar, coordinated by `Workbench/Dock/BottomDock` (also the owner of `Workbench/Dock/ResultsPanelMode` routing
and the shared focus-return/flash choreography). All three extend
**`Widget/DockPanelWidget`** (itself an `OverlayWidget`), which is why `BottomDock::visiblePanel()`
returns one type instead of a three-way union: it declares `statusText()`, `setVisibleRows()` and
the two verbs that genuinely differ — `wheelBy(int $rows)` (the terminal scrolls its **scrollback**,
so wheel-up goes into history and the sign flips; the two lists `scrollBy`) and `clickRow(int $row)`
(a no-op on the terminal, whose body clicks only refocus). Those two retired the `instanceof`
downcasts in `Input/MouseRouter`. It is a **class, not an interface**, deliberately: `Workbench/FocusRing`
and `Layout/PaneResizer` compare the panel against the focused widget and the router hands it to
`setFocus()`/the hit-tester, all of which need an `AbstractWidget`. Note `wheelBy` is **not** named
`scrollBy` (the `Widget/Support/WheelScrollable` verb) precisely because the terminal inverts
direction — sharing the name would make two opposite meanings look interchangeable. **Every list tool lives as a `Workbench/Dock/DockToolTab`
in the `Workbench/Dock/DockToolTabs` model** — the model, not the widget, is the source of truth: the single
`ResultsPanelWidget` displays exactly one tab, and tool tabs **coexist** (each Find-in-Project
query, Problems, TODO, each Usages/Choose-target list, the LSP/notification logs, Local History
— one strip tab apiece, closed only by its ×, no cap). Instance identity is **(mode, title)**:
re-invoking a tool reuses and refreshes its tab; a different title appends. Switching tabs
(`BottomDock::bind`) preserves rows/title/searching (+ a Search tab's frozen
`Search/SearchTabState` — query/options/scope/mask, re-adopted by `ProjectSearchController` on
bind so F5/replace/export act on the displayed tab's own query); filter text, collapse, context
and selection reset — the accepted v1 trade-off of rebinding through the widget's `open()`.
Async completions (search runs, TODO scans, the live Problems/log refreshes via
`Workbench/Dock/DockLiveRefreshListener` on `Event/DiagnosticsChanged`/`LspLogAppended`/`NotificationLogged`)
write into their captured tab and reach the widget only through `refreshIfBound` — an inactive
tab stays fresh in the model and a late grep can never clobber the tool on display.
`Widget/OverlayWidget` carries the panels' two axes: `isOpen()` (alive) vs `isVisible()`
(open AND revealed); at most one panel renders; `Esc` is `dismiss()` → dock hide (everything
stays alive). The transient pickers (palette, go-to-symbol) are not dock tools at all —
they are Search Everywhere scopes on the floating picker path (see the pickers note under
Rendering). `Widget/DockTabsWidget` is the one-row strip —
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

**Floating surfaces — the `Overlay/` subsystem, three tiers.** Every modal floats over the whole
workspace on one per-pane span path (spans pushed through `setModalOverlay` + backdrop dim,
presented by `BidiTui::onBeforeFrame` hooks): the Welcome modal, Find in Files, and the
**pickers** — the centered list overlay (recent files, quick fixes, peek, unsaved-changes…),
Search Everywhere (all scopes incl. the palette) and the mini prompt. The subsystem is organized
by a **strict three-tier rule** — each tier is a directory under `Overlay/`, and a new
floating feature lands one class in each:
- **`Overlay/State/`** — the mutable per-surface model (open/selection/query), never returns
  `string[]`: `OverlayHost` (pickers), `FindInFilesModal`, `SearchModalState`,
  `MenuOverlayController`/`MenuOverlayPanes`, `Toast`/`ToastCenter`/`ToastSeverity`.
- **`Overlay/Presenter/`** — the eagerly-resolved per-frame span producer: `PickerModalPresenter`,
  `FindModalPresenter`, `ToastPresenter`, `WelcomeModalController` (a presenter despite the name).
  All four share **`OverlayDelivery`** (pane-union `bounds()` with the pre-first-frame
  requestRender retry, the per-pane `SpanSlicer`+`ModalShadow` slice loop for both span slots, the
  all-panes clear sweep — delivery targets `current()` panes, clearing targets `all()`, because a
  surface may close across a view swap). It lives in the Presenter tier because it *mutates panes
  per frame* — `Widget/Render/` stays stateless. Each presenter keeps its gate, its state source
  and its own `$shown` clear-once guard: that guard is the invariant that lets four writers share
  the span slots without clobbering each other — do not centralize it. The shadow-clamped RTL
  region math is `DropdownRegion::shadowClamped`.
- **`Overlay/Region/`** — pure geometry / hit-test value objects: `ModalBox`, `PaneBounds`,
  `DropdownRegion`, `SpanSlicer`, `FloatingRegions`/`CompositeFloatingRegions`.
- **`Ui/FrameHooks`** is the per-frame hook seam the presenters register on (`onBeforeFrame`),
  implemented by `Ui/BidiTui`; they take it as a nullable dep and no longer downcast. The alias is
  spelled out in `config/services.php` **on purpose**: `BidiTui`'s def is overridden to synthetic
  AFTER the loader's load-time interface counting, so a natively derived alias here would be a
  load-order accident — and a nullable dep fails *silently*.
  `ContainerAliasesTest::testEveryPresenterReceivesTheFrameHooksSynthetic` pins the compiled wiring.
- **The stateless render tier stays in `Widget/Render/`** (`ModalFrame`, `PopupFrame`,
  `ModalShadow`, `OverlayCompositor`, `MenuOverlay*`, `ToastCard`) — it returns `string[]` bearing
  the `$innerWidth + CHROME_COLS` invariant and is cross-cutting (the editor renders here too), so
  it is **not** overlay-owned. Do not collapse it into `Overlay/`. `Widget/Render/PickerLayout` (the
  three centered picker layouts) and `Widget/Render/FindModalRows` (the find modal's leaf rows) live
  here. **`FindInFilesModal`'s layout methods deliberately do not** — they read ~40 of its fields, so
  moving them would need a 40-field VO or a back-reference to the modal; `OverlayHost`'s layouts were
  extractable only because they already took their state as parameters. Measure before assuming.
- Outbound hooks travel as **one callbacks DTO**, not per-slot fluent setters:
  `Widget/EditorCallbacks`, `Overlay/State/FindModalCallbacks`, `Widget/Git/DiffViewCallbacks`,
  and (round 8, user-elected) `HistoryListCallbacks`/`ChangesListCallbacks`/`DebugPanelCallbacks`/
  `TerminalPanelCallbacks`/`DockTabsCallbacks` — every ≥3-slot widget wires one `setCallbacks()`
  call; a bare widget holds an empty DTO and fires nothing. `ResultsPanelWidget`'s four-slot bag
  is still left alone on purpose. The modal's former `BoxPosition`/`BoxSize`/`ScopeChoices` VOs
  were **reversed back into `FindInFilesModal` as plain fields** in the same round (single
  consumer each; the cleaned logic they introduced stayed) — a single-consumer VO needs a second
  consumer to justify itself here.

`OverlayHost` owns picker state/layout/hit-testing (SCREEN-space boxes; `layout()` per frame);
`PickerModalPresenter` delivers spans + `ModalShadow` + dim, gated on an attached editor. The
span slot has one writer per frame: opening any floating surface closes the others
(`CodeEditorWidget::closeSiblingFloaters` → `WorkspaceViews::close(WorkspaceView::Welcome)` +
find close; the full views close all three). Pickers are fixed-centered (third down over the pane
union) — not draggable — and the open modal owns the caret (topmost surface only: prompt >
search). That precedence has ONE spelling: `OverlayHost::topmostSurface()` (prompt > search >
list, tagged by the `Overlay/State/PickerSurface` enum) and its `pickerClickAt`/`pickerHoverAt`/
`moveSelection` dispatchers — the widget only delegates. `moveSelection` skips the prompt (no
selection): a wheel with a prompt and a list open moves the list, pinned by test. A full
`PickerSurface` *interface* was measured and rejected — the three surfaces' open signatures, two
input paths and three click semantics share no seam; the enum is a tag, not a role. The menu
dropdowns and the context menu wear the tighter `Widget/Render/PopupFrame` (border-only modal
language, `DropdownRegion(framed:)` hit-testing: item runs / border swallows / outside
dismisses); the completion popup and signature hint stay editor-pane-composited, framed the
same way.

**Commands & keybindings are single-sourced.** `Config/ActionCatalog` is the descriptor table
for every rebindable action (label + default keys). `Config/CommandRegistry` holds IDE-level
commands (id → handler, key-bound?, in-palette?). `Workbench/Menu/MenuModel` builds the menu bar / context
menus from these. Don't hand-maintain parallel keybinding/palette/menu lists — extend the
catalog/registry. View gating rides ONE `?\Closure(): ?WorkspaceView $activeView` param and one
per-view `VIEW_SAFE` map keyed by case name (a new view = one map entry); moving those
allowlists into `CommandRegistry` descriptors was measured and rejected — it would touch all
seven tagged providers plus a default policy for plugin commands, which are implicitly
view-unsafe today and must stay so. `Input/EditorActionRouter`'s two action tables are
single-sourced the same way: the handler maps' **keys** drive both membership and dispatch
(`simpleHandlers()`/`positionalHandlers()`; the handlers take `EditorCallbacks` + the caret as
parameters so both resolve only at fire time) — never reintroduce a parallel id const.

**Editor core.** `Editor/Document` is the text buffer (byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indent via `.editorconfig`); the whole-line verbs
keep their mutation, cursor clamping and undo here but delegate the algorithm to the pure statics in
`Editor/LineOperations` (`indentSelection`/`dedentSelection` are deliberately excluded — they track
cursor and anchor deltas *inside* the mutation loop). The coordinate math (byte↔UTF-16↔display
columns, offset/position, the visual-lines tab cache) lives in the internally-newed
`Editor/PositionMapper` — round 1 proposed this as "ColumnGeometry" and the record never
noted the name it landed under — which delegates to the pure-static `Editor/TextMetrics`;
`clampLine`/`clampCol` stay on `Document` because the mapper calls them back. Note:
`Document` is #2 in `src/` by size and branch mass yet pins **no** gate — its mass is
spread over 85 small methods. Correct, but it means the ratchet gives no signal if it
grows; watch it in review, not in lint. `Editor/MultiCaret`
and `Editor/EditorActions` layer multi-cursor and editing operations; `Widget/CodeEditorWidget`
+ `Widget/Render/EditorRenderer` render the viewport (the caret is spliced in as a zero-width
APC marker that `ScreenWriter` converts to a hardware cursor position).

**Syntax highlighting is pluggable; the engine is not privileged.** `Highlight/CodeHighlighter`
is per-buffer and owns the caching, the document-version bookkeeping, the 512 KB cap, the
line-count guard and the `AnsiCodeTracker` per-line SGR re-open. The engine behind it is a
`Highlight/SyntaxHighlightProvider` — `id()` / `supports(language)` /
`highlight(HighlightRequest, HighlightSink)`.

- **The seam sits *below* `highlight()`'s version-cache check** (`CodeHighlighter.php`, the
  `$version === $this->cacheVersion` early return), so a backend runs once per *document
  version*, not once per frame. Wire it above and cursor blink invokes third-party code;
  `HighlightProviderTest` pins this with an N-renders-one-call test. Don't move it.
- **One delivery path for sync and async.** A synchronous backend calls `$sink->deliver()`
  before returning; an asynchronous one returns and delivers later. Core tells them apart by
  whether the cache moved, so there is a single state machine. `deliver()` drops anything whose
  version isn't the in-flight one — that check covers stale, abandoned and unsolicited answers.
- **Async is paint-then-refine**: paint the previous cache (or plain, when the line count
  changed), then repaint on delivery. Delivery both **invalidates the editor widget and**
  requests a frame — widgets memoise rendered lines, so `requestRender()` alone repaints from a
  stale cache. Async requests carry a `Support/Scheduler` deadline; three faults (throw, mangled
  line count, or timeout) drop the backend to plain for that buffer.
- **The incremental splice is synchronous-only.** It re-highlights one line inside the frame,
  which an async backend cannot do, and splicing two engines' colors into one cache is incoherent.
- Resolution (`Highlight/HighlightRegistry`): configured id → first claiming plugin in load
  order → `PlainTextHighlightProvider`. **There is no built-in engine in that chain** — Tempest
  is the bundled `tempest` plugin. Plain is constructed, never resolved, so it cannot be removed.
  Loose fragments (diff hunks, the empty-editor placeholder) bypass the registry entirely
  (`CodeHighlighterFactory::forFragments()`): they are non-contiguous lines from different files
  under a reused version counter.
- Selection lives in a **top-level `.porta.json` `highlight` block** (`{default, languages}`),
  keyed by **grammar name**. Not under `settings` (it coerces every value to a scalar, so it
  cannot nest) and not under `languages` (LSP-keyed, which would leave `jsonc`, `terraform`,
  `blade`, `sql`, `bash`, `diff` and `ini` unconfigurable). Travels via `PORTA_HIGHLIGHT`.
- `Support/LanguageMap` is the single source of truth for "what language is this file?", with
  **two columns**: the highlighting grammar name and the LSP `languageId`. They deliberately
  differ (`tsconfig.json` is `jsonc` but LSP must hear `json`). A file with no entry resolves to
  `null` and **no backend is ever asked** — that is why languages with no built-in grammar are
  still named there.
- `Highlight/TempestBackend` (the house grammars: PHP docblocks, JSON, JSONC) and the four
  `TerminalTheme` classes stay in `src/` deliberately: `tempest/highlight` is a composer
  requirement the diff view and project search use directly, and `.porta/plugins` is **outside
  the 100% coverage gate** — moving grammars there would launder them past it. Bundled plugin
  files are registration only.
- Plugins register via `PluginApi::registerHighlighter()` and, separately,
  `registerTokenSpans()` for project search's comment/string classification. Separate because
  spans run once per *matched file* (synchronous, off the render path) and degrade to
  "classify nothing"; a subprocess backend should decline them (500 hits = 500 processes).
  Both are consumed through interfaces the *consuming* namespace owns
  (`Highlight/SyntaxHighlightProviderSource`, `Search/TokenSpanProvider`), so neither subsystem
  depends on `Porta\Plugin` — the `Lsp/LanguageServerProvider` precedent.
- **Tests install `tempest` as a *global* plugin** via `PORTA_HOME=tests/Support/fixtures/home`
  (global plugins are opt-out, so no per-project config). Writing config or plugin files into
  each `TempProject` was tried and rejected — it perturbs the git fixtures (working-tree
  assertions) and the tree/tab fixtures. The suites that *enumerate* plugins
  (`IdeEmbeddedPluginsTest`, `IdePhpLspPluginsTest`, one `IdeSettingsThemeTest` case) opt out via
  `PORTA_HOME` in `setUp` and **restore it in `tearDown`** — paratest reuses the worker, and a
  leaked value silently uncolors later tests.

**LSP.** `Lsp/LspSessionPool` routes traffic to per-language sessions started lazily on the first
`didOpen` of a matching file; sessions are keyed by command (shared processes for JS/TS on one
server). Feature controllers (`Lsp/NavigationController`, `Lsp/RefactorController`,
`Lsp/CompletionController`) extend `Lsp/AbstractLspController`. `NavigationController`'s
position-bound verbs (hover/references/call hierarchy/peek/file structure) ride one private
scaffold — `requestAt` (ready-guard + position params; `requestForDocument` for uri-only) with
transport-error and nothing-to-show funnelled into a `$notFound` **closure**, not a message: the
verbs flash through two different sinks (status bar vs results panel). `send()` is the chaining
seam — call hierarchy's inner `incomingCalls` hop enters there and deliberately never re-runs
`ready()`. `requestGoTo` stays separate: already factored, and it holds the one
`FlushMode::Keep`. A collaborator was rejected — the scaffold consumes inherited
`AbstractLspController` members, so extraction would just re-thread the parent.
`Lsp/LspMapper` is **dense by nature** — pure statics whose branch count is `??`-coalescing
over optional wire fields, exactly what defensive protocol mapping looks like; its one real
duplication was folded (`firstLocation` is `allLocations()[0] ?? null`, with the accidental
malformed-leading-element divergence fixed and pinned). Leave the rest alone.

**Debugger (DAP).** `Debug/DapClient` speaks the Debug Adapter Protocol over a child process's
stdio — the whole wire layer (proc_open spawn, non-blocking pipes, Revolt watchers, the frame
drain, write-error → terminate convergence, teardown) is **`Support/JsonRpcTransport`**, shared
verbatim with `Lsp/LspClient` (each keeps only its envelope: JSON-RPC 2.0 handshake/whenReady vs
DAP's seq/type + closed-once onClose; log wording rides the transport's `$peerName`).
`Debug/DapSession` is one launch's
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
`Debug/DapSession`'s three collection verbs share one `requestList` scaffold (command/args/
body-key/row-map are the only per-verb facts; the row maps take the wide row type and narrow
via inner `@var` — a narrowed closure param is contravariance-unsound); the advertised
capabilities are one `DapCapabilities` VO, all-false before initialize. The rest of the
session (0/1-based conversions, the run-to-cursor one-shot, the termination idempotence
guard) is deliberate protocol structure — dense by nature, leave it.

**Input routing.** `Input/MouseRouter` maps every SGR mouse event to the pane under the pointer
and owns cross-event drag/click state; its `route()` guard order **is behaviour** (documented in
its class docblock). Hit-testing uses the renderer's per-widget rects (`Input/HitTester`, whose
`contains()` is the shared pointer-in-rect predicate). Satellites carry the parts that need no
drag state: `Input/WheelRouter` (all wheel routing), `Input/ContextMenuRouter` (all right-click
routing — it resolves which item list the pointer is over, then hands off to `ContextMenuOpener`),
`HoverRouter`, `ClickCounter`, `GitMouseRouter`, `SettingsMouseRouter`, `WelcomeMouseRouter`.
The extraction test that produced these: a method whose dependencies it **exclusively** owns
(counted inside the method vs across the file) and which touches none of the six cross-event drag
fields. Applying the same test to `BufferService`'s pane-swap cluster **rejected** it — only one
dependency was exclusive. It also rejected **`CodeEditorWidget`'s ~270-line render cluster**, a
stronger failure: 3 of ~23 touched dependencies exclusive, and the cluster *writes* four fields
the mouse paths *read* (`lastGutterWidth`/`lastRenderRows`/`lastStripeCol`/`stickyLines` feed
`viewport()`, `caretScreenCell()`, `documentLineAt()`, `gutterWidth()`) —
render-event-writes/mouse-event-reads is exactly the shared cross-event state clause. Round 4
re-measured the remaining large candidates and rejected them all on the same clauses:
**`GitViewController`'s history tier** (1/11 exclusive — `$historyPageSize` only; the
diff-toolbar's `toggleWhitespace` reads `$shownCommit`/`$shownCommitPath` that the history path
writes, and `switchMode` writes `$historyPath`), **both `HelpViewerWidget` clusters** (history
stack 0/6 — both stack fields feed `headerLine()`'s arrow states; FindTarget adapter 1/7 —
`render()` itself bumps `$findVersion`, and `byteAtColumn` is shared with link highlighting),
and **unifying the four multi-field form state machines** (FindBar/CommitBox/FindInFilesModal/
ResultsPanel: 8 variance axes across 4 participants — heterogeneous `MultilineInput` field,
cycling-disabled and field-absent flags, last-field-escapes, three Esc dispositions, per-field
post-edit side effects — and ResultsPanel has no `$activeField` at all). Also measured and
rejected: **splitting any of the four widget-roster enumerations** (`WidgetHooks`,
`WorkspaceLayout`, `ContextMenuOpener`, `MenuOverlayPanesFactory`) — every split *increases*
total coupling edges (WidgetHooks 25→30+ with three shared deps duplicated; ContextMenuOpener
per-branch 22→~66, 8 of its 20 deps are cross-branch), and the three roster enumerations are
different-order, different-purpose (Z-order assembly / hit-test pane lists / focus dispatch), so
no single list serves them. Only the
slice that already took its state as parameters left: `Widget/Render/ErrorStripe` (the
right-margin stripe, pure static). Named zone rows share `Ui/ClickZones`
(`clear`/`add(name, start, end)`/`at(col)`, half-open, first match wins — the help header's
overlapping padded zones rely on that); adopted by the help header, the find bar's option tags,
the diff toolbar and the find modal's options+footer rows. The five *other* zone lists
(`GitTabsWidget`, `BreadcrumbsWidget`, `MenuBarWidget`, `TabBarWidget`, `DockTabsWidget`) are
deliberately not converted: non-string payloads (enum modes, line ints) and positional lookups
(dropdown anchoring, drag-reorder) would launder worse than the loops they keep.
Round 6 completed the audit's last measurement: **`DiffViewWidget`'s clusters all fail** —
the selection model 0/8 exclusive (`render()` writes `$lastWidth`, the mouse-only
`sideAt`/`pointCol` read it; `$anchor`/`$charSel` cross the same seam the other way), the
document/highlight cluster 2/10 (both exclusives total three lines), the narrowest
highlight-cache slice is the four-field-DTO lateral shape. Its kan-defect co-pin is
deliberate structure. **The standing audit is hereby closed**: six rounds, every candidate
measured, every lint threshold on its pin. Future SOLID work is *feature-driven* — apply
the extraction test and the recorded precedents to new code as it lands; re-opening a
recorded rejection requires new evidence (a changed dependency count, a removed cross-event
seam), not a fresh sweep. Round 7 made the closure self-enforcing: `composer arch`
(the fifth gate) pins the namespace graph, class acyclicity and the E2e suite bound.
`Layout/LayoutController` holds one `Layout/SidebarResizeState` per sidebar (tree/git/settings:
armed drag, start width, persisted percent), so arm/drag/nudge/restore are each written once;
the public `arm*`/`nudge*` names stay as delegators (`MouseRouter`/`PaneResizer` call them), and
the old readonly `SidebarResize` ferry-spec is subsumed and gone.

**Plugins.** User PHP loaded at boot from three sources in precedence order —
`<project>/.porta/plugins/*/plugin.php`, `~/.porta/plugins/*/plugin.php` (`PORTA_HOME` overrides
the home dir), and the plugins **bundled in the Porta package** (`PORTA_EMBEDDED_PLUGINS`
overrides; earlier sources shadow later ones by directory name). Project and global plugins are
**opt-out** (loaded by default); **bundled ones are opt-in** — only names in `enabledPlugins`
load, which is why the shipped `tempest` highlighter is off unless asked for. Names in
`disabledPlugins` are skipped at boot in every source. phpunit pins `PORTA_EMBEDDED_PLUGINS` to
`/nonexistent` (bundled plugins opt in via putenv) and points `PORTA_HOME` at
`tests/Support/fixtures/home`, which installs `tempest` globally so the suite renders coloured.
The subsystem is container-owned: `Plugin/PluginHost` builds the
settings/manager/loader triple from the synthetic `IdeConfig`, and `ensureLoaded()` (idempotent,
triggered by the `Keybindings` factory) runs each file's `activate(PluginApi)` **before** the
(immutable) `Keybindings` map is built so plugin default keys merge into it (user `.porta.json`
overrides them; ids are
namespaced `plugin:command` and bypass the ActionCatalog keymap validation — plugin ids stay
OUT of `ActionCatalog::ACTIONS`). Registration buffers in the per-plugin `PluginApi` and commits
atomically after `activate()`. Exactly **four** of the eight extension points share the generic
`Plugin/PluginBuffer` (language servers, installers, highlighters, token spans — the
validate/append-a-`'plugin'`-stamped-row/bare-foreach-drain shape); the other four stay plain
arrays **on purpose**, each saying why at its declaration (commands: paired `$ids` dedup index;
menu items: drain-time `PluginMenuSpec` transform; hooks: four writers + positional tuples;
settings: keyed map sinking into `PluginSettings`) — unifying them was measured as needing five
variance axes for four conforming cases. The eight `register*` signatures are frozen public
plugin API. Action methods (flash/editor access/openFile) resolve services
lazily via `Plugin/PluginRuntime` (wired late in the ctor) and throw during `activate()`. Every
plugin handler and hook is wrapped in try/catch → status-bar flash, so a faulting plugin never
kills the loop. Hooks (`fileOpened`/`fileSaved`/`bufferSwitched`/`themeChanged`) fan out from
`Plugin/PluginEvents`, which is a `#[AsEventListener(priority: -100)]` bridge on the app event
bus: `BufferService` dispatches typed `Event/BufferOpened`/`BufferSwitched`/`BufferSaved`
events (symfony/event-dispatcher, wired kernel-less in `config/services.php` — the
`event_dispatcher` service id is mandatory for `RegisterListenersPass`), core listeners
(git marks, debug decorations, LSP save/inlay) react at priority 0, and the plugin bridge
observes last, deduplicating consecutive switch paths for plugins only. `themeChanged` rides the
same -100 bridge, and core's highlight-cache drop (`Highlight/HighlightThemeListener`) sits at
**-200** deliberately, so a backend that bakes the theme's escape codes rebuilds *before* the
caches it feeds are dropped. That is why the -100 chrome sweep is no longer globally last — it is
still after every *rebuild* listener, which is what its contract means. Plugin commands land in
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
built-in and refuse removal). The live lifecycle lives in `Plugin/PluginLifecycle`, behind two
seams: **`Plugin/PluginEnablement`** (`enable`/`isDisabled`/`listedNames`) is what the *offer*
paths take (`Settings/SettingsHighlightController` offering the bundled `tempest` engine,
`Onboarding/WelcomeSteps` offering the same plus the server/fixer/analyzer), while
**`Plugin/PluginLifecycleOps extends PluginEnablement`** adds install/deactivate/disable/refresh
and is driven only by the plugins settings page
(activate/deactivate/setEnabled + the settings-nav sync): **live activation** —
`PluginLoader::loadFile` + `PluginManager::registerCommands` (incremental via a watermark) +
`Workbench/Menu/MenuRebuilder::rebuild` — and **live
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
`Settings/SettingsPluginsController`).

**Per-project config: `.porta.json`** — **reading and writing are separate classes**:
`Config/IdeConfig` parses (and is the synthetic service), `Config/PortaJsonWriter` persists. They
share no state, only that file's value vocabulary (`IdeConfig::LEGACY_SETTINGS`, `stringList()`,
`commandString()`); every writer verb is one read-modify-write through its `edit()` helper, so two
settings pages editing different blocks cannot clobber each other. Keys: `keymap`, `settings`,
`languages`, `lsp`. Settings
(`Settings/SettingsController` + the `Settings/` view) all apply live through
`Settings/LiveSettingsApplier` — the theme id is the `Ui/Theme/Theme` **backed enum**, which carries
both halves of a scheme (`definition()` for the chrome, `terminalTheme()` for syntax) so the two can
never disagree; both matches are exhaustive, so adding a case fails at analysis rather than falling
back to dark. Boundaries (`.porta.json`, `$PORTA_THEME`) convert with `Theme::fromId()`. Theme swaps the `SwitchablePalette`/`SwitchableTerminalTheme`
holders (buffer highlight caches are dropped by the -200 `HighlightThemeListener`, not inline),
the locale relabels the tree, the cursor shape swaps the
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

`tests/Unit`, `tests/Integration`, `tests/E2e`. E2e suites are **thematic** (the
round-5 split of the old 208-method monolith): one suite per subject (LSP features, context
menus, git flows, …). Keep new E2e tests in their subject's suite — the too-many-methods and
kan-defect ratchets now gate src, and a regrown monolith would re-pin them.
`IdeTest::testFullScenario` stays one continuous session on purpose (see the mago.toml
calibration comment). E2e tests boot a real `Ide` against a `VirtualTerminal` and a
`TempProject` through the shared `tests/Support/RunsIde` trait: steps
(`ms => fn($tui, $terminal, $ide)`) run **sequentially chained** — each schedules the next by
key delta after it completes, so a step may `$this->await(fn: bool)` (pumping the live loop for
subprocess/async effects) without a later step firing underneath it. Without an explicit
`$duration` the loop stops ~80ms after the last step; pass a duration only when effects land
after the final step — better, await them in the step. When a constructor gains a dependency,
fix the direct construction sites in these tests too.

**Never hand-roll a test rig `tests/Support` already provides** (round 8 collapsed ~700
duplicated lines onto these): `PreservesPath` + `ClearsPortaLsp` (attribute hooks — the env rig;
classes keep their own setUp/tearDown), `PumpsLoop` (`await()`/`pump()`, composed by `RunsIde`),
`ScreenReads` (screen scrape + the force-`frame()` idiom, composed by `RunsIde`),
`FindModalReads`, `RendersWidget` (`plain()`/`rows()` ANSI-stripping), `StatusReads`
(`flashText()` reads the MODEL, `statusRow()` the RENDERED row — pick deliberately; `Toasts`
makes the same model argument for toasts), `GitBinary` (`skipWithoutGit()`/`whichGit()`),
`TempProject` (`git()`/`initGit(branch:/name:/email:)` — pass name/email when a test asserts the
author — /`commitAll()`), `TestOverlayPane` + `RendersRoot` (the presenter rigs),
`TestTui::create(cols, rows)` (the three-arg Tui rig; terminal-only one-arg stays inline), and
`lsp-stub.php` (`sendMessage`/`readFrames` for stub servers; deviant-I/O stubs keep the deviant
side hand-rolled). **`await()` THROWS on timeout, and its condition must re-evaluate**: an arrow
fn (`static fn () => null !== $x`) captures `$x` BY VALUE at creation and can never observe the
async callback's by-reference write — round 8 found 64 such conditions silently burning their
full timeout as de-facto sleeps. Write `static function () use (&$x): bool {...}` (or read
`$this->…` state); when there is genuinely nothing to await, say so with `pump()`.
