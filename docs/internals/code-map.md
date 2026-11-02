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

**`Workbench/View/`** — what a view window is, for core's own and for a
contributed one alike. Git's is a plugin's; Settings', the docs' and Welcome's
are core's; nothing in this namespace can tell. `ContributedView` (a view supplies its own string id;
there is no enum of views), `ViewRegistry` (the interlock — one view open at a
time), `ViewWindow` (the window's contents) and `ViewHost` (opening a view in it,
done once rather than per view), `ViewCommandGate` (what a command does to an
open view), `ViewServices` (the seven members core hands a contributed view) and
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
status-bar paths. Never follow a flash with your own render request. A failure
is `error()` (or `warn()` when it half-worked), never a plain `flash()`: the
severity is the card's frame colour and its glyph in the notification log. A
long operation says so in a **slot** (`flash($text, 600.0, 'git.remote')`), and
its outcome is sent to the same slot, which replaces the card in place. A
progress message sent without a slot stays up for its whole timeout beside the
result.

**`plugins/<name>/src/`** — a plugin's own classes, under its own namespace
(`Porta\GitPlugin\`, never `Porta\Git`: core's edge buckets are FQCN-keyed and
sharing a prefix would merge the two halves). **A plugin class can never be a
container service**, so a plugin holds no core service it was not handed: the
git plugin carries `Flash`, `Modals`, `Translate` and `DiffPrefs`, each one
closure from `PluginApi` behind the method name its call sites already used.

A plugin that builds on another's classes says so in `extra.porta.requires`
and imports them directly: `Porta\ReviewPlugin\` (plugins/review) runs its git
through the git plugin's `GitProcessRunner` and reads diffs with its
`DiffDocument`, and owns every instance it uses — plugins share classes, never
live objects.

A plugin big enough to need interior structure states its own rules, because
this page cannot: the git plugin's are in `plugins/git/porta.php`'s docblock.
A subdirectory there costs nothing in the graph — `composer arch` attributes a
file by the first segment after `Porta\`, so `Porta\GitPlugin\Process\Foo` is a
`GitPlugin` file exactly as `Porta\GitPlugin\Foo` was.

**`Filesystem/`** — where the project's files really live. `ProjectFilesystem`
is the seam the editor, the tree, the tree verbs and project-wide replace write
and read-gate through. It has three implementations:

- `LocalFilesystem`, which is Symfony's verbs.
- `RemoteFilesystem`, a server's project through its cache.
- `ProjectFiles`, the alias that picks one of the two at boot from
  `ProjectLocation`.

The rest of this namespace:

- **The remote engine:** `RemoteIndex`, `ChangeQueue` and the
  `RemoteSpec`/`RemoteStamp`/`RemoteEntry`/`RemoteFile` value objects.
- **The plugin contract:** `RemoteTransport`, which plugins implement, and
  `CommandTransport`, the shared base for transports that drive a
  command-line tool.
- **`LocalOnly`:** the gate in front of what runs the project here.

**`Presence/`** — who else is at work on the project. `Participants` is the
roster and its status cell, fed by sources: `PresenceSync` for other Porta
instances on the same files, plugins through `Plugin/Api/PluginParticipants`.
`PresenceBoard` (`LocalPresenceBoard`, `RemotePresenceBoard`) is where the
instances leave their `Beacon`s; `Identity` is the name they see. It depends on
`Filesystem/` for the remote board's transport, never the other way.

**`Markdown/`** — markdown text to a document tree: the CommonMark + GFM
parser (`Block/` for the line phase, `Inline/` for the inline phase), the
immutable nodes (`Node/`) and the source positions every node carries
(`SourceRange`, `SourceText`, `MappedText`). It imports nothing feature-owned
and draws nothing; drawing a tree is `Help/Layout/`'s.

**`Install/`** — Porta outside the IDE: the `porta` link on `PATH`
(`CommandLink`), git's difftool/mergetool config (`GitToolSetup`) and the
running binary (`PortaBinary`). Shared by `Console/` (`--git-setup`) and the
Command line settings page, so neither reaches into the other.

**`Console/PluginCommands`** — `porta <name> …` for a bundled plugin's console
command (`extra.porta.commands`): the plugin's class, which must extend
`Plugin/Api/PluginCommand`, lives in the plugin; core only finds it by name.

**`Settings/`** — all settings controllers plus the row model (`SettingsRow`,
`SettingsRowKind`). `Config/` keeps only `.porta.json` parsing and the action
and command catalogs. Settings **widgets** stay in `Widget/Settings/`.

**`Widget/`** — classes that render, either carrying `#[AsWidget]` or producing
`string[]`. Non-rendering widget-internal helpers (row DTOs, enums, traits) go
in `Widget/Support/`. `Widget/Render/` is the stateless render-algorithm tier.
Every form control — text field, button, select, stepper — draws through
`Widget/Render/FieldBox` (with `FieldSpec`, `FieldState`), never a hand-rolled
` Label: value` row; a select's list opens through `Workbench/Menu/ListboxOpener`.
A list row's selection is `Widget/Render/SelectionBar` in its first column
(`PanelRowStyle::barred()` for the panels); a scrolling column list's `▲`/`▼`
row is `Widget/Render/EdgeIndicator`.
A separating line, with or without a legend, is `Widget/Render/Rule` in
`Role::Rule`, never a hand-repeated `─`.

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

Every horizontal tab strip (editor, dock, git mode tabs) is
`Widget/Render/TabStripRenderer::render()`; the single cell is its
`measureCell`/`renderCell`. Only the vertical editor strip lays out its own
rows. A roomy tab is a pill shaded by `Widget/Render/TabPillStyle`, its halves
`Widget/Render/RowBleed`'s, the half that lands in a workspace rule
`Overlay/Presenter/RuleHalves`'; `TabStripTail` pads the rows. How tall the strips are is
`Layout/ChromeHeight`, from the `Ui/ScreenDensity` it keeps, and a strip learns whether its pane has the keys
through the `Widget/Support/PaneFocusProbe` trait.

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
`Editor/EditorPaneSource`, and `Bootstrap/CorePanes` registers the panes core
still ships: the help browser, the large-file look and the markdown preview
(with its claim in `Editor/PreviewRoutes`). The drain is core's deliberately —
see [decisions.md](decisions.md). What fills the editor slot is
`Workbench/EditorSlot`; the editor-beside-a-preview arrangement it mounts is
`Layout/PreviewSplit`, and the subsystem is in
[subsystems.md](subsystems.md#the-editor-slot-and-live-previews).

**`Plugin/`** — four tiers, one rule each. `Api/` is the surface a plugin file
compiles against. `Runtime/` is what the IDE runs. `Lifecycle/` is enabling,
disabling and acquiring. `Tool/` is the external programs plugins declare
(`extra.porta.tools`): finding them (`ToolLocator`), and installing and
updating them. The tiers are invisible to `composer arch`, whose edge
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
pass `autoload: false`, and bundled `plugins/*/porta.php` files are `require`d
directly rather than Composer-autoloaded.

**Every test namespace must mirror its path under `tests/`**, or the
authoritative classmap rejects it. `composer dump-autoload
--classmap-authoritative` surfaces any PSR-4 violation.
