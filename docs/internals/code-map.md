# Where code lives

Which namespace a class belongs to, and how to move one.

## Moving a class

FQCN is entirely determined by path — `Porta\ => src/`, `Porta\Tests\ =>
tests/`, no aliases — so a move is a pure rename. Edit the `namespace`, every
`use`, the mirrored test's path and namespace, and, only for a public service in
the `porta.ide` locator list or one `Ide` resolves, its `use` in
`config/services.php` or `src/Ide.php`. The `X::class` entries name the short
symbol, so they do not change.

`composer analyze` and the container compile (which `composer test` performs)
catch any dangling reference. Grep the old namespace to prove zero.

## Membership

**`Overlay/`** — floating surfaces, in three tiers: `State/` for mutable
per-surface models, `Presenter/` for per-frame span producers, `Region/` for
geometry and hit-test value objects. Stateless render helpers stay in
`Widget/Render/`.

**`Ui/`** — the primitive vocabulary only: the theme (`Role`, `Sgr`, `Glyphs`,
`CursorStyle`, `Ui/Theme/`), `BidiTui`, `PaneSegmenter`, `TextField`,
`MultilineInput`, `ClickZones`, the list and shortcut helpers. It is the
codebase's most-depended-on namespace, so **it imports nothing feature-owned**.
Anything that composes features does not belong here.

**`Workbench/`** — the composition tier over the features: `Notifier` and
`NotificationLog`, `StatusBarProvider`, focus (`FocusRing`,
`FocusCoordinator`), the chrome sweep pair, and three subsystem *models* —
`Workbench/View/`, `Workbench/Menu/` and `Workbench/Dock/`. Their **widgets**
(`MenuBarWidget`, `ContextMenu`, `DockTabsWidget`, `ResultsPanelWidget`) live
in `Widget/`.

**`Workbench/View/`** — what a full-screen view is, for core's own and for a
contributed one alike. Git's is a plugin's; Settings' and Welcome's are core's;
nothing in this namespace can tell. `ContributedView` (a view supplies its own string id;
there is no enum of views), `ViewRegistry` (the interlock — one view owns the
main area at a time), `ViewHost` (the main-area swap, done once rather than
per view), `ViewServices` (the seven members core hands a contributed view) and
`ViewRegistrar` (the `PaneRegistrar` twin that drains `registerView()`). The
pointer seam is here too — `ViewPointer`, `ViewPointers`, `WheelGesture`,
`WheelAxis` — because a view CARRIES its pointer (`ContributedView::pointer()`),
so registering the view is what wires it.

A view's sidebar/separator/content is NOT here: `Layout/ViewRegions` mints
those at runtime, because the container is compiled and cached before any
plugin PHP runs and a compiled slot can only describe a view core knew at build
time. A view fills its own region and declares how its sidebar resizes
(`ViewRegion::setSidebarPanes()`); everything that used to name a view's
widgets — the resize nudge, the menu-overlay pane list, the theme-refresh
sweep — asks the region instead.

**`Notifier::notify()` ends in `requestRender()`** on both the toast and
status-bar paths. Never follow a flash with your own render request.

**`plugins/<name>/src/`** — a plugin's own classes, under its own namespace
(`Porta\GitPlugin\`, never `Porta\Git`: core's edge buckets are FQCN-keyed and
sharing a prefix would merge the two halves). **A plugin class can never be a
container service**, so a plugin holds no core service it was not handed: the
git plugin carries `Flash`, `Modals`, `Translate` and `DiffPrefs`, each one
closure from `PluginApi` behind the method name its call sites already used.

A plugin big enough to need interior structure states its own rules, because
this page cannot: the git plugin's are in `plugins/git/plugin.php`'s docblock.
A subdirectory there costs nothing in the graph — `composer arch` attributes a
file by the first segment after `Porta\`, so `Porta\GitPlugin\Process\Foo` is a
`GitPlugin` file exactly as `Porta\GitPlugin\Foo` was.

**`Settings/`** — all settings controllers plus the row model (`SettingsRow`,
`SettingsRowKind`). `Config/` keeps only `.porta.json` parsing and the action
and command catalogs. Settings **widgets** stay in `Widget/Settings/`.

**`Widget/`** — classes that render, either carrying `#[AsWidget]` or producing
`string[]`. Non-rendering widget-internal helpers (row DTOs, enums, traits) go
in `Widget/Support/`. `Widget/Render/` is the stateless render-algorithm tier.
Every form control — text field, button, select, stepper — draws through
`Widget/Render/FieldBox` (with `FieldSpec`, `FieldState`), never a hand-rolled
` Label: value` row; a select's list opens through `Workbench/Menu/ListboxOpener`.

The shared row traits live in `Widget/Support/`:

- **`StylesDefault`** — the `$styles ?? Styles::dark()` constructor pair.
  `EditorRowAssembler` is the one non-adopter: a readonly class cannot use a
  trait with a non-readonly property, and mago rejects readonly writes outside a
  constructor, so it keeps the inline pair.
- **`ProviderFingerprint`** — a typed provider, `setProvider`, and the
  json_encode repaint guard. `StatusBarWidget` deliberately keeps its own: its
  lastLeft/lastRight compare is load-bearing render state, not a repaint guard.
- **`StripScroll`** — the scroll offset and the ‹/› indicator zones.
- **`ColumnHover`** — column-stored hover, with `clearHover`/`storeHover`.
  `TabBarWidget`'s cell-stored hover is a different model and stays.
- **`MenuNavigation`** — the two menus' navigate hooks. Their keymaps do NOT
  merge: the bar carries a left/right axis the context menu has no use for.
- **`PanelWidth`** — min and max width, defaulting to 24 and 200. Override only
  where a panel genuinely differs.

The single tab cell lives in `Widget/Render/TabStripRenderer` as `measureCell`
and `renderCell`.

**`Widget/EditorPaneWidget`** and **`Widget/NavigablePaneWidget`** — the two
abstract bases a pane extends. A pane *is* a widget rather than having one, for
`DockPanelWidget`'s reason: `BufferService` adds it to a container, the routers
hand it to the hit-tester, and `setFocus()` takes it. The base carries the five
pane traits, the `ViewerTabHandler` and the `afterViewChange()` funnel, and
declares `paneId`, `show`, `clear` and `statusRight` plus the inert defaults
`hide`, `forget`, `press`, `wheelBy` and `refreshTheme`. The navigable subclass
adds the pointer surface: `scrollBy` in columns and half-rows, `zoomAt`,
`magnifyAt`, `selectAt`.

Help extends the plain base — its wheel scrolls whole rows, which is why the
wheel verb is `wheelBy` and not `scrollBy`. Two units, two names.

**`Editor/EditorPanes`** — the pane registry, keyed by the id a viewer claim
returns, each pane with the loader that turns a path into its payload.
`Editor/PaneRegistrar` builds plugin-registered panes from
`Editor/EditorPaneSource`, and `Bootstrap/CorePanes` registers the one pane core
still ships, the help browser. The drain is core's deliberately — see
[decisions.md](decisions.md).

**`Plugin/`** — three tiers, one rule each. `Api/` is the surface a plugin file
compiles against. `Runtime/` is what the IDE runs. `Lifecycle/` is enabling,
disabling and acquiring. The tiers are invisible to `composer arch`, whose edge
key is the first segment after `Porta\`, but they are not cosmetic: the split
forced the `PluginApi` ↔ `PluginManager` buffer/drain cycle into the open, where
it is now a pinned SCC. Same-namespace classes need no import, so the class
graph could never see it before. The consumer-owned provider interfaces stay out
of here on purpose — see [plugin-system.md](plugin-system.md).

**Suffix homes**: mouse and keymap routers in `Input/`, typed events in
`Event/`, LSP controllers in `Lsp/`, `*Store` state beside its domain.

## Autoloading

`composer.json` sets `config.optimize-autoloader`: a precomputed classmap every
boot, with the PSR-4 fallback keeping new files working in development.

CI's `composer install` adds `--classmap-authoritative`, which is safe because
`src/` is PSR-4-clean, the only dynamic `class_exists` and `new $class` calls
pass `autoload: false`, and bundled `plugins/*/plugin.php` files are `require`d
directly rather than Composer-autoloaded.

**Every test namespace must mirror its path under `tests/`**, or the
authoritative classmap rejects it. `composer dump-autoload
--classmap-authoritative` surfaces any PSR-4 violation.
