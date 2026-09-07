# Settled questions

Things that were measured and rejected, or measured and deliberately kept.
Each entry says what would re-open it. Re-proposing one without new evidence
costs a round; that is the only reason this page exists.

## Framework and container

**Adopting `symfony/framework-bundle` or a Kernel — rejected.** Three
findings. The five porta attributes are already Symfony-native (three
`AutoconfigureTag` subclasses, one `Autowire` subclass, and `AsWidget` on the
stock `registerAttributeForAutoconfiguration()` API), so the bundle replaces
none of them — they encode domain concepts no bundle knows. A dry-run pulls ten
packages, all HTTP infrastructure a TUI never touches, to replace ~160 lines
that are the documented component-level pattern. And the decisive regression: a
Kernel resolves `kernel.project_dir` at compile time, so its dumped container is
per-project, where Porta dumps **one project-independent container shared across
every project the IDE opens**.

*Re-opens on:* a TUI-relevant bundle, or opcache preloading becoming applicable
to the CLI boot.

**`inline_factories` dumping — measured, not adopted.** It silently requires
`as_files` and then collapses back to about four files. Container-only
micro-bench 23.09 → 23.23 ms (a wash); resolving 28 service chains through the
full locator 43.66 → 42.07 ms, −3.6%, under the 5% keep gate and diluted to
noise in a real boot that also loads plugins and builds the Tui.

**`SingleImplAliasPass` — deleted on a measured zero-delta.** The loader itself
registers every singly-implemented alias before any pass runs:
`PhpFileLoader::load()` ends in `registerAliasesForSinglyImplementedInterfaces()`.
The pass added zero aliases. `ContainerAliasesTest` pins the derivation.

**`tagged_locator()` for `porta.ide` — rejected.** It is an argument-position
construct; the named public definition is the canonical kernel-less spelling.

**Also considered and not done:** unifying the `$_ENV`/`getenv` channels
(runtime-timing risk against low value); `#[When]` env wiring (the loader gets
no env, so it can never match); `AddEventAliasesPass` (matters only with opcache
preloading); emulating `test.service_container` (the two-method `Ide` seam is
the answer).

**`classmap-authoritative` in `composer.json` — deliberately not done.** It
would drop the PSR-4 fallback and break new-file autoload in development until a
manual re-dump. Authoritative mode stays a CI-install concern.

## Structure

**The extraction audit is closed.** Six rounds measured every candidate against
one test: a method may be extracted when it *exclusively* owns its dependencies
(counted inside the method versus across the file) and touches none of the
cross-event drag state. Rejected, with the count that rejected each:

| Candidate | Exclusive dependencies |
| --- | --- |
| `BufferService`'s pane-swap cluster | 1 |
| `CodeEditorWidget`'s render cluster | 3 of ~23 (see below) |
| `GitViewController`'s history tier | 1 of 11 |
| `HelpViewerWidget`'s history stack | 0 of 6 |
| `HelpViewerWidget`'s FindTarget adapter | 1 of 7 |
| `DiffViewWidget`'s selection model | 0 of 8 |
| `DiffViewWidget`'s document/highlight cluster | 2 of 10 |

The render cluster fails twice over: it also *writes* four fields the mouse
paths read, which is the shared cross-event state clause.

Also rejected: unifying the four multi-field form state machines (eight variance
axes across four participants, and `ResultsPanel` has no active-field concept at
all), and splitting any of the four widget-roster enumerations — every split
*increases* total coupling edges, and the three rosters are different-order,
different-purpose lists.

*Re-opens on:* a changed dependency count or a removed cross-event seam, applied
to one candidate. Not a fresh sweep.

**Namespace cycles are closed.** The import graph had 42 top-level cycle pairs;
the two stable-dependency inversions worth fixing were fixed. Verified at class
granularity: the one real SCC — a seven-class ring closed by the old view
registry's two `attach*()` setter fields — is gone, not pinned. A string-keyed
`ViewRegistry` has no concrete controller fields to close it with. What remains
allowed is two degenerate pairs. Relocating the widget→domain-VO edges was
measured and mostly rejected — for Settings, Tree, Debug and Highlight that is
the *correct* layering direction, views reading domain shapes.

**Git is a bundled plugin. `src/Git` and `src/Widget/Git` are gone, and core
keeps no git-named symbol.**

An earlier attempt stopped at the view on three measurements. Every one of them
changed, and not by trying harder — by changing what the seam had to describe:

| Seam | Bar | Then | Now | Why it moved |
| --- | --- | --- | --- | --- |
| Services DTO | ~8 members | 12 | **7** | a view owning its REGION needs one handle, not three layout slots; a view whose main-area swap is `ViewHost`'s needs neither the menu bar, the editor area nor the pane order. The seventh is `HitTester`, forced by a view owning its pointer |
| Pointer routing | fits >1 consumer | 11-verb union | **5 stages, 3 consumers** | `separatorRect`/`leftRect` existed only because the separator arrived by `#[LayoutSlot]`; core mints the region and hit-tests its own widgets, so they are gone rather than shared. `scrollVertical`'s signature clash was arithmetic, not scrolling — `WheelGesture` carries the resolved step |
| Layout region | net-negative | `ViewRegion` −36/+45 | **forced** | that measurement was of a RENAME which kept both regions as compiled services. The container is built and cached before any plugin PHP runs, so a plugin-owned view can never receive `git_left` under any naming. Minting is the only route, and it deleted six service definitions, five `#[LayoutSlot]` consumers and the getters |

The seams Phase A built, each independently useful:

- `Layout/ViewRegions` mints a view's sidebar/separator/content at runtime.
- `Workbench\View\{ContributedView, ViewRegistry, ViewHost, ViewServices,
  ViewRegistrar}` — views register by string id; `PluginApi::registerView()`.
- `Workbench\View\{ViewPointer, ViewPointers, WheelGesture, WheelAxis}` — five
  pointer stages, carried by the view rather than registered beside it.
- `Workbench\{StatusCell, StatusTone, StatusMenuSpan}` — a status cell carries
  its own context menu, so the branch indicator is an ordinary contributor.
- `Workbench\Menu\{MenuContribution, MenuContributions, MenuRegistrar,
  ViewMenuSource}` — a top-level menu, its view-safe command list and its
  context menus are contributed, not declared.
- The modal layer floats above `main`, which fixed a live bug on its own: the
  command palette is listed view-safe in the Git menu and painted NOTHING while
  the git view was open.

**The move had no valid intermediate state, which was proved by attempting it.**
`config/services.php` discovers `../src/*` only, so the instant a class leaves
`src/` its `#[AsIdeService]`/`#[AsWidget]`/`#[AsEager]` becomes inert and
everything injecting it fails. There is no ordering that keeps the container
satisfied, so widgets, panes, controller, routers, events and commands moved
together: ~5,700 source lines, 148 files, one commit.

What that commit had to build, beyond the move itself:

- **Four closure-backed shims in the plugin** — `Flash`, `Modals`, `Translate`,
  `DiffPrefs` — because `Notifier`, `CodeEditorWidget`, `Translator` and
  `LayoutController` are container services a plugin cannot hold. Each is one
  closure behind the method name its call sites already used, so ~90 call sites
  are unchanged.
- **A `ViewSlot`**, because the phases do not line up: `PluginApi` drains its
  buffers at commit, but a view's factory runs after commit (it needs the region
  core mints for it). Anything registered before the view exists — the `ide_git`
  toggle, the core-bus listeners — goes through the slot.
- **A fact bridge in `plugin.php`.** The plugin's widgets dispatch on its own
  `EventDispatcher`, and three of those events are core `PluginObservable`
  facts. Forwarding them is what keeps the gutter stripe refreshing after a
  commit or a pull; without it they would reach only the git plugin's own
  listeners, silently.
- **`PluginApi` grew five verbs**: `registerMenu`, `notify`, `refreshStatusSegments`,
  `showChangeMarkGutter`, and a `pick()` that takes hint rows. `StatusSegment`
  became `statusCells(): list<StatusCell>`, so a plugin's cell keeps its tone
  and its right-click menu instead of being folded into the shared subtle one.

**The tests moved nearly as-is** — the opposite of what was predicted here. Only
the construction sites changed, because every shim was designed to be
substitutable for the service it replaced: `GitDoubles::flash()` wraps a REAL
`Notifier`, so every `StatusReads`/`Toasts` assertion stands. `plugins/git/tests`
holds ~6,900 lines including the E2e suites, which had to move because the
coverage gate grades `plugins/` from the plugin's OWN tests.

**The persistence migration landed with it**, rather than after: the eight
`git*` settings and the two diff toggles are `plugins.git.*` now, on the
plugin's own settings page under Plugins. Keeping them where they were would
have needed a `registerSettingsSection` verb that `registerSetting` already
covers.

Two adoptions carry an existing project across, and both read the FILE rather
than the resolved settings — the question is "was this key ever written?", and
an absent key and a key set to its default are the same VALUE:

- `GitPlugin\LegacySettings` adopts the eight `git*` keys, per key, and writes
  what it adopted through so the file stops answering the same question twice.
  The old keys join `IdeConfig::LEGACY_SETTINGS`, so core skips them silently
  instead of warning eight times.
- `LayoutStore` reads `gitSidebarWidthPercent`/`settingsSidebarWidthPercent`
  into a `viewSidebarWidthPercent` MAP. Two named fields could not describe a
  view whose id core learns at runtime, which is what removed the last
  git-named symbol from `src/`.

Three regressions the gates would not have caught, each now pinned by a test:

- `PaneLayout::applyDirection()` ran during the eager boot, before a PLUGIN view
  can have minted its region — so the git area mounted empty. It runs after the
  view registrar now.
- The `#[AsWidget]` flex styles vanished with the attribute; `plugin.php`
  applies them by hand beside the ids.
- `ide_git` had to become adoptable. The old note said flagging it would make
  `MenuModel` skip the item when unregistered, "the opposite of what the skip is
  for" — true while core owned the view, and exactly right now that a plugin
  implements it and registers neither outside a repository.

The graph: **224 edges and 3 SCCs became 220 and 2.** `Porta\Git` no longer
exists; the seven `*>Git` edges and the six `Git>*` ones are gone, replaced by
`GitPlugin>{Event,Highlight,Input,Layout,Overlay,Plugin,Support,Ui,Widget,Workbench}`
— a plugin reaching for core's toolkits, the allowed direction, and the same set
`Help` and `GraphViewer` already reach for.

*Re-opens on:* nothing. What is worth watching is the two pins that made it
safe — `GitListenerContractTest` (the eighteen wirings, now read off a
`GitWiring` map rather than an attribute) and `ActionCatalogTest` (the sixteen
adoptable ids and their real labels).

**The round after it found what a move preserves and what it hides.** Behaviour
was preserved exactly, which was the goal; what nobody had done was introduce
the two halves — the editor half (blame, marks, stage/stash) had been written in
a different tier from the workbench half and still duplicated it.

- **`GitInfo::diff()` and `GitClient::diff()` were byte-identical**: same argv,
  same parser. `GitClient` was a 104-line subset — `stage`, `undoLastCommit`,
  `stashPush`, `stashPop` are verbatim on `GitInfo` — written when the halves
  were in different namespaces and `GitInfo` was genuinely unreachable. The
  reason went with the move; the class did not. Deleted.
- **Four process lanes, and the stated reason for two was false.** Three
  classes each built a `GitProcessRunner`, a fourth re-implemented its
  start/track/cancel inline, and two docblocks justified it as "no business
  queueing behind the view's status and diff traffic". `runStatus()` spawns
  immediately and tracks only for cancellation — there was never a queue.
  What the belief cost was quiet and real: `onShutdown` cancelled one lane of
  four, and the E2e completion barrier awaited one of four, so a test could walk
  out with a blame, a mark refresh or a fetch in flight.
- **Two lanes survive, on a reason that holds**: "Cancel Remote Operation" must
  stop a hung fetch without stopping the status poll, and `isBusy()` must mean
  "a remote operation is running". Cancellation SCOPE, not queueing — the
  distinction is now the docblock on `ProcessLane`.

The lesson generalises past git: **a justification survives a move even when the
thing that made it true does not.** Both docblocks were accurate when written.
Nothing re-read them when the world changed, and no gate can — which is why the
mutation sweep, not the reading, is what found the hole underneath.

That sweep is also why the fix is a fix. Five mutations survived its first pass
— shutdown missing the remote lane, shutdown cancelling nothing, and the lane
not reaching any of three constructors — and writing tests for them turned up a
real gap in the working tree: `plugin.php` was still building `GitRemote`
without its lane, so shutdown reached one lane of two. The defect the commit
claims to fix, reintroduced while fixing it, caught only because nothing
asserted the claim.

The fifty files are grouped by tier now (`Process/`, `Parse/`, `Diff/`, `View/`,
`Command/`, `Blame/`, `Setting/`, `Support/`), with the membership rules in
`plugins/git/plugin.php`'s docblock — the only plugin large enough to need its
own `code-map.md`. `composer arch` stayed at 220 edges throughout: the scanner
keys on the first segment after `Porta\`, so a subdirectory cannot invent
coupling.

**`Layout/ViewRegion` — rejected on arithmetic.** Collapsing git's and settings'
sidebar/separator/area triples deletes ~36 lines and adds ~45. The five
`porta.widget.*` getter services do not disappear; they would call
`region('git')->area()` instead, and deleting them means editing the four
`#[LayoutSlot]` consumers. `LayoutController` saves two lines, because
`SidebarResizeState` was already extracted. And the two regions are not the same
shape: git's sidebar is `WorkspaceLayout`'s, settings' is injected into
`PaneLayout`.

**Clickable status segments — rejected.** Giving `StatusSegment` a
`statusCommands()` so the git branch stops being `StatusBarProvider`'s one
special case changes `registerStatusSegment`'s plugin-facing signature for a
capability nothing has asked for. The branch's right-click menu forces the
issue: `newBranch()` and `deleteBranch()` have no registered command ids, so two
new palette commands would have to exist to serve a refactor.

**The pane drain stays in core.** Put it in `Porta\Plugin` and it must name
`EditorPaneWidget`, resurrecting the `Plugin > Widget` edge that was severed.
That is why the factory and loader cross the boundary as bare `\Closure`s and
the registrar type-checks what comes back.

## Plugin API

**`registerWelcomeStep()` — designed and rejected.** The plugins worth
recommending are exactly the ones that are OFF, so they have not loaded and
cannot announce themselves. The only cards such a seam could produce are ones
already ticked.

**Unifying the five plain-array extension buffers — rejected.** It needs five
variance axes for four conforming cases. Each array says at its declaration why
it is not a `PluginBuffer`.

**Graph-viewer's exports on `MenuSurface::EditorContext` — rejected.** That
surface resolves from the code editor widget's rect, and a viewer pane detaches
that widget — so a viewer-scoped command there is gated on exactly the state
that hides it.

## Events

**Seven closure wirings were measured and left alone.** `OverlayWidget`'s
open/close (the base of ~8 overlays; dispatching there broadcasts every overlay
open, and restricting it to dock panels needs a tab identity `DockPanelWidget`
does not have); the menu bar and context menu navigate/close hooks (same shared
base, and `MenuOverlayController::refreshMenu()` clears the overlay panes when
its menu shuts, so one shared event would wipe the other menu's spans); the
seven focus-next hooks (one trait, two live controllers, different targets);
`previewReplacement`, `setStagedChangesProvider`, `onExpandVariable` and
`onInputForward` (byte transport, one dispatch per keystroke); and the nine
Search Everywhere slots (three controllers share one overlay widget, so the
installed closure *is* the identity).

**`Event>Git` is not cut.** Five of those events carry `ChangeEntry` or
`LogEntry`; scalarising them would force listeners to re-look-up the selected
entry.

**`PluginContributionsActivated` / `…Retracted` are events to break a CONTAINER
cycle, not to fan out.** Each has exactly one listener
(`Workbench/View/ViewContributionListener`), which normally reads as a command
wearing the wrong suffix — the direct call is what a reviewer expects. It does
not compile: `PluginLifecycle → ViewRegistrar → ViewHost → PaneLayout →`
the overlay stack `→ WelcomeSteps → PluginEnablement` closes the loop back on
the lifecycle, and Symfony refuses it at build time. Inverting the edge instead
(the view surface polling the lifecycle) trades a cycle for a poll, and moving
the registrar out of the layout's reach means a view that cannot mint the
region it is built with. The dispatcher resolves listeners lazily, so the edge
disappears while the call stays synchronous and correctly ordered — the view is
built before the lifecycle's own tail runs. **Reach for this only when the cycle
is real**; a single-listener fact is otherwise a smell, and this pair is
deliberately not `PluginObservable` (plugins do not get told about each other's
lifecycles).

## Testing and gates

**The `[loop-leak]` escalation is refused on evidence.** Three full serial runs
reported 7, 8 and 7 lines, differing by exactly one test, because whether a
0.15s debounce is still armed depends on where the loop happened to stop. A gate
on that is a flake generator, and a per-test allowance would be a list of tests
permitted to be non-deterministic — worse than the report it replaced.

**Writing plugin config into each `TempProject` — tried and rejected.** It
perturbs the git fixtures' working-tree assertions and the tree and tab
fixtures. Tests install `tempest` as a *global* plugin via `PORTA_HOME`
instead.

**Widening the "skip an unregistered menu item" rule — tried and reverted.** It
couples menu *structure* to registration, so nine `MenuModelTest` fixtures that
assert the DEFS shape against an empty registry produce no menus at all. A
`groupVisible` predicate does the job instead.

**Two mutation survivors are redundancy, not gaps.** `FindQueryUpdated`'s
listener is one `requestRender()`, and three other mechanisms already schedule
that frame — vendor `Tui::handleInput` when the focused widget's render revision
changed, and both `open()` callers explicitly *and* via `setFocus`. Likewise the
blame plugin's `refreshIfEnabled` early return is an optimisation: with blame
off the callback drops the answer anyway, so deleting the check only costs a
subprocess per buffer switch. Neither is observable through any seam a test can
hold. Do not write a test that cannot fail.

## Leave it alone

**The 56/56 `mb_strwidth` vs `AnsiUtils::visibleWidth` split — measured and
rejected (2026-08-14).** The premise was that `visibleWidth`'s grapheme path
(via `UnicodeString::width`) collapses multi-codepoint clusters where
`mb_strwidth` sums components, so the sites feeding click/hover geometry
(BreadcrumbsWidget's hit zones, ResultsPanel's match offsets, MenuPanel's
dropdown sizing) were measuring wrong. Measured on the shipped symfony/string:
the two functions **agree on every cluster class** — ZWJ emoji (👩‍💻: both 5),
skin tones (both 4), decomposed accents (both 2), flags (both 2). The vendored
`graphemeWidth` docblock's "where `mb_strwidth()` overcounts" describes a
difference `UnicodeString::width` does not deliver. A 56-site swap would change
nothing observable, and no test could pin it — do not write a test that cannot
fail.

*Re-opens on:* symfony/string's `width()` actually collapsing ZWJ/modifier
clusters (re-run the four-probe comparison), at which point the geometry-feeding
sites are the ones to convert first.

**`PaneSegmenter` per-frame memoization — measured and rejected.** With RTL
active every frame re-segments the whole widget tree while the vendor
renderer's per-widget caching makes a warm `render()` almost free, so the
segmenter looks dominant (~13× the warm render). The absolute number is what
decides it: **0.178 ms per frame** at 120×40 on the standard workspace tree
(warm render 0.013 ms; whole mirrored frame 0.19 ms) — two orders of magnitude
under a frame budget. Memoizing would tie a cache to re-layout invalidation
(resize, pane swap, view mint, overlay open) to buy back a fifth of a
millisecond. The four per-frame `ReflectionProperty` constructions WERE
cached — constant setup, no invalidation to get wrong.

*Re-opens on:* a segmenter cost measured above ~2 ms/frame (a radically larger
tree), or frame pacing showing the mirrored path missing frames.

Dense by nature, measured, and not to be "simplified":

- **`Lsp/LspMapper`** — its branch count is `??`-coalescing over optional wire
  fields, which is what defensive protocol mapping looks like. Its one real
  duplication was folded.
- **`Lsp/NavigationController`'s scaffold** — extracting a collaborator would
  just re-thread the parent, since the scaffold consumes inherited members.
- **`Debug/DapSession`** — the 0/1-based conversions, the run-to-cursor one-shot
  and the termination idempotence guard are deliberate protocol structure.
- **`Overlay/State/FindInFilesModal`'s layout methods** — they read ~40 of its
  fields, so moving them needs a 40-field VO or a back-reference. `OverlayHost`'s
  layouts were extractable only because they already took their state as
  parameters. Measure before assuming.
- **A `PickerSurface` interface** — the three surfaces' open signatures, two
  input paths and three click semantics share no seam. The enum is a tag, not a
  role.
- **A shared trait for the viewer scheduling cluster** — 3 of ~8 dependencies
  exclusive, the rest each widget's most-touched state, plus three genuinely
  different policies. Only the mechanism (`Support/DebouncedRequest`) was
  extracted.
- **Single-consumer VOs** — `FindInFilesModal`'s `BoxPosition`, `BoxSize` and
  `ScopeChoices` were reversed back into plain fields. A single-consumer VO needs
  a second consumer to justify itself.
- **The mouse and hover routers' per-view rects, and the old `Input/ActiveView`**
  — the routers owned wide per-view slices by design, and narrowing them was the
  rejected `Clickable` idea. Overtaken since: `ViewPointer` narrowed them to five
  stages after all, because the LAYOUT seam changed what the pointer seam had to
  describe. The rejection was right about the shape proposed, not about the
  possibility.

**A 2026-08-15 sweep of every 8+-parameter method measured five candidates,
fixed two, rejected three.** The two fixed shared one shape: a caller had
already destructured an existing VO — `Widget/Render/EditorDecorations`,
whose own docblock says it exists "so the row-assembly helper does not take
five more positional arguments" — back into positional parameters one call
deeper.
`EditorRowAssembler::decoratedLine()` (9 params) and `LineSpans::forLine()`
(9 params) now both take `EditorDecorations` directly instead of its
`selection`/`carets`/`occurrences`/`brackets` fields spread out; each drops to
6 params, and `multiActive`'s "suppress the primary selection" rule lives in
one place (`forLine`) instead of being applied by the caller before the call.
No new type was invented — the VO already had a second and third consumer
once it was allowed to reach them.

Rejected, each for the reason the extraction audit already established:

- `EditorRowAssembler::assemble()`'s remaining 8 (of 10) params, after its two
  already-VO'd ones (`EditorGeometry`, `EditorDecorations`) — one call site,
  and `$sticky`/`$highlighted`/`$stripe`/the four scroll-and-cursor ints share
  no second consumer to justify a new VO.
- `EditorRenderer::completionPopup()` (9 params) — one call site
  (`CodeEditorWidget`), no existing VO groups any subset of `$columns`,
  `$rows`, `$cursorScreenRow`, `$anchorCol`, `$items`, `$selected`,
  `$hovered`.
- `Workbench\Menu\MenuModel::item()` (8 params) — a private helper called only
  by `items()`, which already owns every one of those params itself; wrapping
  them travels the same data through a new object with no consumer outside
  this one file.

## The global config scope (2026-08)

Every setting used to be written into the project's tracked `.porta.json`, so
changing your theme dirtied the repository and there was nowhere to put a
preference that is yours rather than the team's. `<home>/.porta/config.json` is
that place, merged UNDER the project file.

**The merge happens inside `IdeConfig::load()`.** Measured, not assumed: the
container's cache key is `VERSION + PHP_VERSION + path + cacheDir +
installedPackages`, and config reaches services through separate `$_ENV` JSON
channels. So a `load()` returning a merged config with unchanged property shapes
leaves `IdeContainerFactory`, every env channel, every `#[Autowire]` consumer
and `LiveKeybindings` untouched — and `LiveKeybindings::rebuild()`, which
re-reads the file, keeps the global layer for free. Merging any later pays for
all of that and buys nothing. **What would re-open it:** a config value that has
to differ per scope AFTER the merge (provenance does not — it re-reads both
files on demand).

`load()` splits into `readLayer()`, which parses one file into only what that
file actually said with NO defaults, and `merge()`, which folds the layers and
applies the defaults once. The split is not tidiness: with `DEFAULT_SETTINGS`
folded in per layer, "global set `lineNumbers` to absolute" and "global never
mentioned `lineNumbers`" are the same array, and the merge cannot tell them
apart.

Rejected, each with what would re-open it:

- **A union merge for `enabledPlugins`.** This is the one rule that cannot be a
  union. The invariant is that an explicit list REPLACES, even an empty one —
  that is why `persistBundled*` materialises the effective list before writing.
  Unioned, a project could never turn off what global turned on, because there
  would be no way to express the absence. The rule generalises by one word:
  materialise **the effective list of the layer below**, not the constant
  default. *Re-opens if* `enabledPlugins` ever stops being an explicit list —
  e.g. gains an `"inherit"` sentinel that can express "add to the layer below".

  **That last sentence was written here and not implemented, and shipped
  broken for eleven commits.** `persistBundled*` materialised the constant, so
  disabling one plugin in a project rewrote its list from the shipped default
  and silently re-enabled every bundled plugin the user had turned off
  globally. No gate reads prose. If a rule is worth recording here it is worth
  a test that fails without it — this one has three now, and the general
  lesson is the twin of "a docblock's reason outlives the fact behind it": a
  justification can also arrive *before* the code it justifies, and read as
  settled either way.
- **Provenance on the Keybindings page.** Its bindings come from three merged
  *widget* keymaps, not from config layers; answering "where did this binding
  come from" would mean re-deriving that merge outside the merge, for an
  approximate answer. *Re-opens if* `KeybindingsFactory` ever exposes the
  per-source contribution it already computes internally.
- **Deep-merging `debug.launch`.** Half a project's launch config and half the
  global one is a configuration nobody wrote and no adapter accepts. It
  replaces whole. *Re-opens if* an adapter ships a launch schema that says
  which keys are independently overridable.
- **Any new `PORTA_*` env channel.** Provenance is computed by re-reading both
  files, which costs one `is_file` plus a decode on a settings page that is
  already doing filesystem work — and keeps the container cache key untouched.
  *Re-opens if* provenance is ever needed on a hot path.
- **Auto-migrating anything.** A `.porta.json` in a repo is often *meant* to be
  shared; relocating it on upgrade breaks teammates silently and cannot be
  undone from inside the IDE. Nothing moves; a one-time notice is marked in the
  **global** file so it never touches the project's. *Re-opens if* Porta ever
  gains a reversible, previewable migration surface.
- **Widening `ConfigScope` to absorb `PluginInstaller`'s scope strings.** That
  vocabulary has a third value, `embedded`, for plugins shipped inside the
  package. There is no embedded CONFIG — nothing writes to the package — so the
  case would be one every consumer must handle and none can reach. *Re-opens if*
  the package ever ships a default config file.
- **`<home>/.porta.json` as the global file.** People run `porta ~`, and that
  spelling collapses the two layers into one file where a project write and a
  global write are silently the same write.
- **Refusing a write the other layer overrules.** Every path that can write
  into a shadowed key — a setting, a reset, a plugin toggle, a rebind — warns
  and proceeds instead. Refusing reads well until you want a personal default
  for a key some open project happens to pin, at which point the global value
  is unreachable from inside that project. The write is correct; only this
  project ignores it, and that is what the warning says. *Re-opens if* the
  settings screen gains a way to edit a scope other than the one in effect,
  which would make "change it where it is set" an instruction the user can
  actually follow.
- **The one-time "there is a global file now" notice**, which this plan called
  for and which the shipped default made redundant. The notice existed to
  explain a surprise: an existing user's next edit landing in a file they had
  not heard of. `SettingsScope` does not create that surprise — a project whose
  `.porta.json` already has a `settings` block keeps writing there, and a
  project that has none has no old values to be confused about. What remains is
  a feature announcement, and the "Save changes to" row at the top of every
  page announces it better than a flash that scrolls away. The notice also had
  a real cost: marking "already told" means WRITING the global file at boot,
  for every existing user, creating a file nobody asked for and giving the test
  suite a boot-time write into its shared fixture home. *Re-opens if* the
  default ever moves an existing user's writes without their asking — then the
  surprise is real again and has to be explained.

## The PhpStorm-shaped UI round (2026-09)

Seven features landed; two were measured and turned down.

**Code vision (`textDocument/codeLens`) — rejected on where it has to live.**
The assumption was that it is an inline decoration like an inlay hint. It is
not: code vision is a virtual row ABOVE a declaration, and the row assembler
maps screen row → document line with a walking cursor that sticky-header
offsets, the marker stripe's per-row index, `FoldModel::docLineAtRow` /
`screenRowFor`, click mapping and the scroll math all read. Every one of those
would have to learn about rows that are not document lines — the deepest change
available in the render path — to buy a "3 usages" line that costs a scarce
terminal row and one LSP request per visible declaration. The gutter's fold
triangles, the other half of the same idea, landed instead and cost one column.

*Re-opens on:* the row assembler gaining a virtual-row concept for some other
reason, at which point this is a small addition rather than the whole reason for
the surgery.

### That condition is now met (2026-09-03) — code vision is re-opened

Soft wrap gave the row assembler its virtual-row concept, which is exactly the
trigger this entry named. Both halves of the rejection have since been checked
rather than assumed:

- **A bundled server provides it.** phpantom advertises
  `codeLensProvider: {resolveProvider: false}`, so lenses arrive complete and
  there is no resolve round trip to build. phpactor does not — the same split
  as `foldingRange` and `inlayHint`, so the feature gates on the capability
  like the rest.
- **The row-count seam is two call sites.** `WrapLayout::rowsFor()` is consulted
  by the row walk and by `EditorMouse`'s hit-test walk, and nowhere else. A
  lens row folded into that count would be followed by BOTH automatically —
  the render and the click mapping stay correct for free, which is precisely
  what "the deepest change available in the render path" was pricing.

**What would remain:** a `codeLens` request and controller (the
`FoldingController` shape), a per-line store, a display toggle, the walk
emitting a lens row before segment 0, and caret motion SKIPPING lens rows —
that last one is the piece with no existing seam, since vertical motion is width
arithmetic rather than a row count.

**Built the same day** (`Alt+V`, off by default), and the prediction held: a
lens row is one more thing a line occupies, so it went through the seam soft
wrap had just built.

`DecorationStore` holds lenses keyed by the line they sit ABOVE — the only
decoration that ADDS a row rather than painting into one. The row walk emits
them before the line's content, unnumbered and unmarked; `EditorMouse` counts
them in the same walk, so a click below a lens still lands on the line it looks
like it is on. A click ON a lens row resolves to its line at column 0, carried
as a NEGATIVE segment from the walk, because a lens shows none of the line's
own columns.

**The caret needed nothing, and the earlier note claiming otherwise was wrong.**
Vertical motion is in DOCUMENT coordinates, so the caret can never land on a
lens row — there is nothing to skip. The render places it correctly because the
walk knows which row of a line is content.

**The toggle asks and clears.** Turning lenses on requests them immediately —
waiting for the next save reads as a dead key — and turning them off clears the
store, so the rows go with the setting. `CodeLensRefresher` checks the toggle
rather than the controller, so off means no REQUESTS, not just no painting: a
lens count is a real round trip per save.

**`clampToCaret()` counted document lines, and that was a BUG, not
imprecision.** Three lines each wrapping to four rows do not fit in six, but a
clamp counting lines thinks three lines always do — so the caret walked off the
bottom of the viewport and the viewport stayed at the top. Measured, not
suspected: `vScroll` was still 0 with the caret on the third line.

It counts rows now, through a `setRowsForLine()` closure the widget supplies
(null when neither wrap nor lenses are on, which keeps the old arithmetic
exactly). Both setters had to move BEFORE the clamp in the render: set
afterwards, the clamp used the previous frame's numbers and the caret sat off
screen for one paint after every Down.

**Clicking a lens runs it** (the last piece). The store carries the command
beside the title, and `EditorMouse` encodes a lens row as `-(index + 1)` so the
caller recovers WHICH lens without knowing how many are above the line. The
caret still moves to the line the lens describes — clicking a lens is also a way
of going there.

**It is a role on `EditorCallbacks`, not an event.** The widget holds no
dispatcher and its `too-many-properties` ceiling is the reason it should not
gain one; the callbacks object is the single property that carries every
outbound verb, which is exactly what it is for. `CodeLensController` implements
`EditorCodeLensHandler` and the container autowires it as the only implementor.

**A lens with no command is kept, not dropped.** "3 usages" reads the same
whether or not it can be clicked, so an informational lens still renders and
clicking it says it has nothing to run — silence there would read as a broken
click.

**Clickable `✗ N` / `▲ N` status-bar counts — rejected, and it is the same
ground `statusCommands()` was rejected on.** `StatusCell` / `StatusMenuSpan`
cover the LEFT prefix and RIGHT-click menus. The problem counts are in the right
segment, which `StatusBarProvider` emits as a string and `StatusBarWidget`
right-aligns at render — so their column spans cannot be computed where every
other span is, and a left-click action is a second interaction kind beside the
menu. A new span source plus a new semantic, for a third way to reach a panel
that already has `Alt+6` and a View-menu entry. What reopened the original
rejection was the branch cell, and that landed; nothing has asked for this.

*Re-opens on:* a second right-segment cell wanting a click, which would make the
span source pay for itself rather than existing for one consumer.

**Two things that were NOT judgement calls and should not be re-litigated as
if they were.** The fold gutter's first cut scanned the whole document on every
rendered row, costing ~4 ms/frame per keystroke at 12k lines; it is a per-LINE
question now (a line starts a region when it leaves a brace open), measured
13.20 → 9.55 ms against a 9.22 baseline with no fold gutter at all. And the
tree's marks are looked up in BOTH path spaces because core keys buffers by
`realpath()` while the tree builds nodes from the raw root — on a symlinked root
they disagree on every path, and a decoration looked up in the wrong space
silently never appears.

## The refactoring surface (2026-09)

**Dedicated `Ctrl+Alt+V` / `Ctrl+Alt+C` extract keys — rejected on what servers
actually publish.** PhpStorm gives extract-variable and extract-constant their
own chords, so the plan for this round did too. Both bundled PHP servers publish
variable, constant and method behind a SINGLE `refactor.extract` kind (phpantom
literally advertises `refactor.extract`; phpactor's list is narrower but not
along that axis), so a kind-filtered request cannot tell them apart — three keys
would open the same list. Extract is reached through Refactor This
(`Ctrl+Alt+Shift+T`) instead. `Ctrl+Alt+N` for inline stays, because
`refactor.inline` IS narrow enough that a single answer can be applied without
asking.

*Re-opens on:* a server publishing `refactor.extract.variable` /
`refactor.extract.constant` (the spec allows the hierarchy), at which point the
filter can distinguish them and a direct key stops being a lie.

**`Ctrl+Alt+M` and `Ctrl+F6` were not taken for extract-method and
change-signature**, though PhpStorm uses them: `Ctrl+Alt+M` is the phpantom
plugin's `phpantom:fix` and `Ctrl+F6` is `focus_previous`. Neither was worth
displacing for a refactoring reachable one keystroke away.

**Deliberately still open, and NOT caused by this round** — both predate it and
neither is a judgement call, just unfinished:

- ~~**Overlap detection between text edits.**~~ **CLOSED 2026-09-07** — see
  "Overlapping text edits are refused now" below. The entry read: LSP says
  overlapping edits make a `WorkspaceEdit` invalid, Porta applies them bottom-up
  and both land, the later one clamping into whatever the earlier produced:
  corruption rather than an error.
- **Cross-file undo.** A multi-file rename is one undo step *per buffer*. Files
  rewritten on disk (those with no open buffer) used to have no recovery AT ALL;
  since 2026-09-07 they keep a local-history snapshot of what the write replaced
  (see "A rewritten file keeps what it replaced" below), so the recovery is
  per-file and manual rather than absent. **A single undo for the whole
  refactoring landed 2026-09-07** — see "Cross-file undo" below. What remains
  genuinely open is only its integration with `Ctrl+Z`, which is per document by
  construction and is left alone deliberately.

## The polish sweep — bookmarks (2026-09-02)

**Both bookmark verbs are IDE-scope commands, not editor actions.**
`EditorActionRouter::simpleHandlers()` dispatches only through the
`EditorCallbacks` groups (`buffer`, `file`, `refactor`, `navigation`), and
`BookmarkActions` belongs to none of them: it reads the active buffer from
`BufferManager` itself and needs nothing the widget holds. Routing it as an
editor action would have meant a fifth callback group and a fifth widget-held
role for one verb; `ViewCommands` already holds the shape and cost nothing.
`toggle_bookmark`'s `F11` default therefore lives in `ActionCatalog::ACTIONS`
rather than `EditorKeymap`, which is also what `ActionCatalogTest`'s
"default-bound in exactly one place" rule requires.

**`DecorationStore::bookmarkedLines()` was written and then deleted.** It
mirrored `breakpointLines()`, which a renderer reads — nothing reads the
bookmark equivalent, and the coverage gate is what surfaced it. A symmetrical
accessor is not a used one.

**The `too-many-methods` threshold moved for `CodeEditorWidget` again.** Three
features in a row have now hit that ceiling. Unlike the two before it, this one
had no seam to route through: the widget *is* the `EditorDecorationSink`, so a
gutter decoration is a method on it by construction. The class is saturated —
the next feature that needs a decoration channel should split the sink, not the
threshold.

*Re-opens on:* a fourth decoration feature. At that point splitting
`EditorDecorationSink` into its own object, held by the widget rather than
implemented by it, is cheaper than the ratchet.

## Call and type hierarchy — what the bundled servers actually provide (2026-09-02)

Probed both PHP servers over raw stdio with the capabilities `LspClient`
declares (an empty client-capability block gives the same answers, so nothing
here is gated on what Porta asks for):

| capability | phpantom_lsp | phpactor |
| --- | --- | --- |
| `selectionRangeProvider` | ✅ | ✅ |
| `documentHighlightProvider` | ✅ | ✅ |
| `foldingRangeProvider` | ✅ | — |
| `inlayHintProvider` | ✅ | — |
| `callHierarchyProvider` | — | — |
| `typeHierarchyProvider` | — | — |

**Type hierarchy is not implementable and was dropped from the sweep.** There is
no server to ask. It re-opens the day one of them advertises the provider.

**Call hierarchy is now gated on the capability.** It was shipped ungated
(incoming before this session, outgoing in `56510144`), so `Ctrl+Alt+H` against
either bundled server sent the request and flashed *"No callers found"* — which
reads as "this symbol has none" when the truth is that nothing was asked. It
says *"This language server has no call hierarchy"* now. Three sibling
controllers (`RefactorController`, `HighlightController`,
`CompletionController`) already gated this way; navigation was the one that did
not.

The feature itself stays: it is correct, tested against the mock, and works the
moment a server provides it — which is also why the mock had to start
advertising `callHierarchyProvider`. That it had never advertised it is how the
gap survived: **the mock answered a request no real server would have.**

*Re-opens on:* either server advertising the provider, at which point the flash
becomes unreachable for that server rather than wrong.

## Soft wrap — rejected 2026-09-02, and the rejection RE-MEASURED 2026-09-03

Listed as a "classic" worth a polish sweep. It is not a polish item, and the
honest measurement is the reason: it is the *same* virtual-row change rejected
for code vision above, in its harder direction. Code vision INSERTS a row
between document lines; soft wrap EXPANDS one document line into N screen rows
and needs the inverse map as well.

What would have to change, counted rather than estimated:

- A wrap-layout object mapping document line → screen-row segments for the
  current content width, invalidated on every edit, width change, fold change,
  and on inlay hints and inline diagnostics — which add trailing content and so
  change where a line breaks.
- `EditorScroll` changes UNIT. `vScroll`, `scrollBy`, `centerOnLine`,
  `centerCursor`, `clampToCaret` and `followCursor` all count document lines
  today; every caller of `centerOnLine` (jump-to, bookmarks, problems, search
  results) passes one.
- `EditorRowAssembler`'s walking cursor advances by `FoldModel::nextVisibleLine`.
  Fold and wrap have to COMPOSE — skipped lines and expanded lines in one walk.
- Caret up/down must move by screen row, not document line, or wrapped text
  feels broken. That is document cursor motion, not a render concern.
- `EditorMouse` needs the inverse map to turn a click cell into (line, col);
  `EditorPopupGeometry` and the completion popup place at the caret's screen
  row; the error stripe's thumb is computed from `vScroll` in the old unit.
- `hScroll` must be disabled while wrapping, across its seven consumers.

That is a round, not an item, and it lands in the most load-bearing code in the
project. Nothing in the sweep pays for it.

*Re-opens on:* someone actually wanting it — this is a cost finding, not a
judgement that wrapped text is undesirable. If it is ever done, code vision
comes nearly free with it, and both should be planned together as one round.

### The re-measurement (2026-09-03) — the estimate above was too high

Asked to scope it properly rather than estimate again, and the count came out
smaller than the prose above claims. Correcting it, because a settled entry
nobody re-checks is exactly how a wrong number becomes doctrine:

- **The row↔line mapping already has a home.** `FoldModel` owns
  `docLineAtRow()`, `screenRowFor()`, `nextVisibleLine()`, `visibleLineAbove()`
  and `snapVisible()` — the virtual-row concept the code-vision entry says would
  have to be introduced is already here, because folding needed it. Across
  `src/` those methods have **nine call sites in four files**
  (`CodeEditorWidget` 5, `EditorScroll` 2, `EditorMouse` 1,
  `EditorRowAssembler` 1), not the diffuse spread the entry implies.
- **Rendering a wrapped segment is an EXISTING primitive.**
  `EditorRenderer::renderContentLine()` already slices a decorated line by
  display column — `AnsiUtils::sliceWithWidth($line, $hScroll, $contentWidth)`
  — to implement horizontal scroll. Segment *N* of a wrapped line is that same
  slice at `N * contentWidth`. Wrap and hScroll are not two features to
  reconcile; they are one knob read two ways, which is also why they are
  mutually exclusive.
- **`vScroll` need not change unit for a first version.** Keep it a document
  line and a wrapped line renders whole or not at all at the top edge. The cost
  is that a line taller than the viewport cannot be scrolled into; the saving is
  the entire (line, segment) scroll-position rewrite the entry above prices in.

**What genuinely remains new:** a `WrapLayout` (segments per line for a content
width, invalidated on edit/width/fold/inlay-hint change), the row walk emitting
`(line, segment)` instead of `line`, forcing `hScroll` to 0 while wrapping, and
caret up/down moving by SEGMENT rather than document line — `Document::moveUp()`
/ `moveDown()` are document-line moves and are the one piece with no existing
seam.

**Still not started, and still the user's call** — it is a feature, not an
afternoon. But it is not the round-sized surgery this entry claimed, and the
claim was made without reading `renderContentLine()` or counting the mapping's
callers.

## Recent Locations (2026-09-02)

Landed, and it was mostly exposure: `NavigationController` already kept a
50-deep `jumpStack` that navigate-back popped one entry at a time. It is
`Editor\JumpHistory` now — a service, because it gained a second reader and
BROWSING the list must not consume it.

`Ctrl+Shift+E` is PhpStorm's key for this and Porta had already spent it on
Search Everywhere. Not displaced, per the round-2 precedent: `Alt+5` instead,
beside the other dock tools.

**Three surfaces were reading a file's line the same way** — bookmarks, recent
locations, and `NavigationController::lineAt` (location rows and the peek
preview). They are one `Editor\LineTextReader` now, and the merge caught a real
bug: `lineAt` looked the buffer up in BOTH path spellings and the two new
readers did not, so a bookmark on the far side of a symlinked root would have
read from disk instead of the live buffer. The reader returns the line RAW,
because the callers disagree on purpose — location rows `trim()`, the peek
preview only `rtrim()`s, since indentation is what makes a preview readable.

The jump-stack E2e reflected into the private `jumpStack` field through `Probe`.
It resolves the service and uses its own API now; the reflection seam was
covering for a missing one.

## Structure-follows-caret (2026-09-02)

Deferred in round 1 as needing a caret-settle channel it did not have. It needed
neither: `documentSymbol` rows already carry each symbol's line and arrive in
document order, so the enclosing symbol is a walk over rows already in memory.
That is why this runs per frame where the semantic highlight needs a settle
timer — there is no request to debounce.

**It only follows the tab ON DISPLAY.** A parked tool's selection lives in its
frozen `ResultsViewState`, not in the shared widget, so a "follow" of a parked
Structure tab would have written into whichever tool was visible instead. The
first version of the test could not tell the two apart — its parked-tab case
used rows whose lines did not overlap, so a leaked select missed and looked
guarded. Mutation-checking the guard is what exposed that; the case uses
overlapping lines now.

**A caret above every symbol selects nothing** rather than the first one. The
file header is inside no symbol, and selecting `class Demo` there would claim
otherwise.

*Not done:* the reverse direction (moving the selection scrolls the editor).
PhpStorm has both as separate toggles, and the reverse one fights with the
existing Enter-to-jump — the row is already how you get to the code.

## Ctrl+W — `textDocument/selectionRange` measured, and NOT wired (2026-09-02)

Both bundled servers advertise `selectionRangeProvider` (see the capability
table above), so the sweep listed wiring it. Measured first, against a running
phpantom and the local `Editor\StructuralSelection` on the same caret — inside
`trim($name)` in `sprintf('Hello, %s!', trim($name))`:

| | phpantom | local, before | local, after |
| --- | --- | --- | --- |
| `trim` | ✅ | ✅ | ✅ |
| `trim($name)` | ✅ | ❌ **missing** | ✅ |
| `'Hello, %s!', trim($name)` | — | ✅ | ✅ |
| `sprintf(…)` | ✅ | ❌ **missing** | ✅ |
| whitespace-only rungs | none | **2 of 11** | none |

The local ladder was missing the two rungs a server gives and had two presses
that changed the selection by a newline and some indentation only. Those are
real defects and they are **fixed locally**: a comma-separated *segment*
candidate (the argument you are standing in, with commas inside nested brackets
and string literals not splitting), a *call span* candidate (a bracket's name
belongs with its parens), and the bracket interior offered **trimmed** — with
the untrimmed one kept as the fallback for a pair holding nothing but
whitespace, which is all such a pair has.

**The LSP request is not wired, and that is the decision.** Ctrl+W is a
rapid-fire key held down and pressed repeatedly; a round trip per press is
latency on a key that is instant today, and the alternative — local answer
first, server ladder for later presses — makes the first press use a different
algorithm from the rest, which can visibly jump. The local ladder now matches
phpantom's rung for rung on the sample above, without a server, which also
means it still works in a file no server serves.

*Re-opens on:* a measured case where the ladders diverge in a way indentation
and argument handling cannot explain — a language whose structure brackets do
not track, most likely, rather than PHP.

**A caution this round earned twice:** the first reading of both ladders
collapsed whitespace for display and so reported "duplicate rungs" that were
the legitimate trimmed-content → whole-line-with-indentation pair. Print the
offsets, or print whitespace visibly, before calling a rung useless.

## Review pass on the sweep (2026-09-02)

Three hypotheses about defects that would pass all six gates. Two were real.

**Opening the Structure tab did not select the caret's symbol.**
`StructureFollowsCaret` memoises on the caret, and opening a tab is precisely
the case where the caret does NOT move — so the tab showed its first row until
you typed. It listens to `DockToolBound` now and clears the memo, which keeps
the per-frame path free of a dock query. Proved through a real `Ide`, since
what was actually in doubt was whether the listener is registered at all.

**`ProjectPaths::absolute()` was not the inverse of `relative()`.** `relative()`
returns an ABSOLUTE path for a file outside the root — its own test says so —
and `absolute()` prefixed the root blindly, yielding `/root//elsewhere/File.php`,
whose `realpath()` is false. Activating such a row opened a phantom tab at a
path that does not exist. Pre-existing and shared by every core list tool and
nine `absolute()` callers; Bookmarks and Recent Locations just made it easy to
reach, because you can mark or jump from any open file. Fixed in `ProjectPaths`,
where the pairing is supposed to be unmismatchable, and `SearchUiWiring` /
`SearchActions` now hold `ProjectPaths` instead of a bare `rootDir` they kept
only to hand-roll that join.

**Bookmarks do not survive a rename — and that one was left alone.** Marks are
path-keyed, and neither the tree's rename nor `WorkspaceEditor`'s re-points
them. `Debug\BreakpointStore` has exactly the same gap, so this is a consistent
limitation rather than a regression. Closing it means a rename FACT event
dispatched from both sites (`BufferRepointRequested` is a command with one
listener and must stay that way) plus re-keying both stores — a piece of work,
not a review fix.

*Re-opens on:* anyone adding a third path-keyed per-file store, at which point
the rename fact is worth introducing once for all three.

## Renames carry their marks (2026-09-02)

The gap the review pass deferred, now closed. `Event\FileRenamed` is a FACT
dispatched by both renaming paths — the tree's context menu and
`WorkspaceEditor`'s `RenameFile` — and `Editor\Bookmarks` and
`Debug\BreakpointStore` each follow it.

**Both paths on the event are canonical, and the dispatcher resolves them.** A
listener cannot: by the time it runs the old path no longer exists, so
`realpath()` returns false. The tree already had the old key resolved (it needs
it to re-point the buffer); `WorkspaceEditor` resolves the DIRECTORY and appends
the basename, the same trick `BufferService::onBufferRepointRequested` uses for
the same reason.

**Two listeners, one per namespace**, rather than one that re-keys both stores:
`Debug > Editor` is an allowed edge and `Editor > Debug` is not, so a shared
listener would have been a namespace cycle. The stores stay pure maps with a
`rename()` that touches no filesystem.

**A test that built its own URI proved nothing.** The first version fed
`Uri::fromPath($old)`, which realpaths as it encodes — so the canonicalisation
was never exercised and its mutation survived. A real server spells paths from
its own view of the workspace root, and on macOS a temp root is a symlink, so
`/var/…` arrives where buffers are keyed `/private/var/…`. The test hand-builds
an unresolved `file://` URI now. **A fixture that pre-satisfies the invariant
cannot test it** — the same lesson as the mock advertising nothing while
answering everything.

~~*Still open:* a DELETE strands marks the same way…~~ **CLOSED in round 7**
(`f5b8f8cb`): `FileDeleted` exists and `BookmarkPathListener::onFileDeleted()`
calls `Bookmarks::forget()`. The entry stood as "still open" for five rounds
after it was done — which is the same drift the rest of this file warns about,
pointed at itself. An entry that says OPEN is read as a to-do, so a stale one
costs a round rather than merely misinforming.

## The decoration sink left the widget (2026-09-02)

The debt this round's bookmarks entry said to pay: *"the next feature that needs
a decoration channel should split the sink, not the threshold."* Paid.

`CodeEditorWidget` used to IMPLEMENT `EditorDecorationSink` and forward all
eight setters to the `DecorationStore` it owns, so every new decoration was a
method on the largest class in the codebase — three features running moved its
`too-many-methods` pin. The store implements the interface itself now, the
widget exposes one `decorations()` accessor, and a future decoration costs the
widget **nothing**.

**`setFoldRegions` was never a decoration.** It was on the interface, but its
only caller takes the concrete widget, and applying regions needs the fold model
and the document version rather than the store. Dropping it from the interface
is what made the remaining eight a clean set of store operations.

**The container binds a factory, not the class.** `DecorationStore` lives in
`src/`, so `->load()` registers it — but the widget builds its OWN with that
widget's `invalidate()` closure. Autowiring the class would have handed every
consumer a second store that takes every push while the editor renders the
first: green everywhere, nothing on screen. The binding is
`factory([service(CodeEditorWidget::class), 'decorations'])` for both
`EditorDecorationSink` and its plugin-facing slice `LineAnnotationSink`, and the
behavioural E2es (a bookmark that renders, blame that annotates) are what prove
the instance is the right one.

**The pin moved off the widget entirely.** Re-bisected: the widget is at 93,
down from 98, and the new floor of 95 is `CodeEditorWidgetTest` — a TEST class.
`mago.toml` records that, because a threshold pinned by a test file is a
different problem from one pinned by production code.

## The suppression audit (2026-09-02)

Both `@mago-expect` sets were tested by removing them and reading what actually
fired, rather than trusting the reason written beside each.

**Analyzer: 11 → 7.** Four were hiding something fixable — `OverlayHost`'s two
non-exhaustive matches (the null hoisted into an early return makes the subject
non-nullable, so the match is total on the enum and still breaks when a case is
added), `PerfCollector`'s cast of a `mixed` load average (narrowed with
`is_numeric()`, which also retired a `no-shorthand-ternary` LINT suppression
beside it), and `Probe`'s mixed assignment. The seven that remain are each
verified: mago's `proc_open` stub does not model the `['pty']` descriptor;
Symfony's `Container` is not final, so PhpDumper's `new $class()` cannot be
proven safe; and the git plugin's three are one design — a const wiring map
dispatched dynamically, pinned by `GitListenerContractTest` — where a forwarding
closure only trades one code for another.

**Linter: 82, and all 82 are load-bearing.** Nothing to remove.

Two traps worth keeping, both of which produced a wrong answer before the right
one:

- **Suppressing one code can unmask another at the same site.** The Tarjan
  comparison carries `redundant-condition` AND `redundant-comparison`; with both
  removed only the first fires, which reads as the second being stale. It is
  not. Removing them ONE AT A TIME is what distinguishes stale from masked.
- **Read every severity.** mago reports at `error`, `warning`, `help` and
  `note`. An audit that greps for the first three declares the one `note`-level
  suppression stale — which is exactly what happened here, and the individual
  re-check is what caught it.

## Next / Previous Problem (2026-09-02)

A fresh survey of the action catalogue against PhpStorm, after the three
parity rounds, turned up two classics still missing: **F2 / Shift+F2** to walk
the current file's problems, and **Ctrl+Alt+O** for organize-imports. The first
landed.

**No language-server round trip.** The diagnostics are already on the buffer,
whoever published them — the error stripe and the Problems tool both read the
same field. This is the keyboard verb between them: you stay on the code and
step through what is wrong with it.

**It wraps, and says so.** A silent no-op at the last problem reads as a broken
key, so the flash names the diagnostic and prefixes it when it came round the
end. Standing ON a problem moves to the next one, which is the case the key is
used in most.

**F2 was reclaimed from a second binding on `ide_toggle_focus`,** which keeps
its primary `ctrl+t`. Nothing lost a sole binding, and the alias contradicted
PhpStorm's own meaning for the key — which is the standard the whole parity
round is judged by. `ActionCatalogTest` pinned the old pair and had to be
updated deliberately, which is the gate working.

**Organize imports landed too** (`Ctrl+Alt+O`). `source.organizeImports` had
been declared in `LspClient`'s capabilities since round 2 with no action ever
asking for it — a promise to nobody. It applies straight away when the server
offers exactly one, like inline, because organising imports is a whole-file
operation rather than a choice between alternatives; several answers still get
a picker. The empty case says *"Nothing to organize"*, which is why
`requestActions()` gained an `$empty` message: *"No quick fixes here"* would
answer a question nobody asked.

**The mock server now filters by `context.only`.** It answered every
code-action request with the same three actions whatever was asked for, so a
client's `only` proved nothing — the same fixture-fidelity problem as the
capability it never advertised. Kinds are a dotted hierarchy, so `refactor`
still selects `refactor.extract`.

**`Ctrl+Alt+O` was a bigger reclaim than F2 and worth stating plainly.** It was
a second binding on `ide_goto_symbol` — but an *exercised* one, with an E2e
driving go-to-symbol through it. That test moves to `ctrl+alt+shift+n`, which is
the action's primary AND PhpStorm's own binding for it, so both actions match
PhpStorm now where before neither did.

*Worth knowing:* the E2e input path takes the legacy `ESC`+`^O` encoding for
this chord, not the CSI-u form. `KeyParser` reads both, so the first version of
the test failed silently against a correctly-wired feature.

## Soft wrap — shipped as Alt+Z, with one gap stated (2026-09-03)

Built after the re-measurement above, in the phases it described, and the
re-measurement held: the render half was small because the primitives were
already there.

`Editor\WrapLayout` is pure over a document and a content width, memoised on
both. The row walk keeps its walking cursor and gains a segment counter.
Rendering a segment reuses `renderContentLine()`'s existing display-column
slice, which is why the widget forces `hScroll` to 0 while wrapping — they are
one knob. A continuation row carries no line number and no gutter marks, and the
caret lights only the segment its column falls in.

**Wrapping is measured on the DOCUMENT line's width, not the decorated one.**
Inlay hints and inline diagnostics ride in the same row; measuring them would
make text reflow because a hint arrived, which is the opposite of what a reader
wants. They clip at the row edge as they already do.

**The caret gap below was closed the same day** — `EditorHost::wrapWidth()`
answers the one render-time value motion needs (null when wrap is off), the
width is retained on `EditorScroll` beside `contentRows` for the same reason,
and `EditorActions` steps by segment for `cursor_up` / `cursor_down` and their
select variants. Arriving from above lands in the new line's FIRST segment and
from below in its LAST, which is the part a naive `moveDown()` gets wrong. The
original text is kept below because it is what the phasing decision was made
on.

~~**The gap, and it is a real one: caret up/down still moves by DOCUMENT line.**~~
With wrap on, Down from a wrapped line jumps past its continuation rows rather
than stepping through them. PhpStorm moves by visual line. The fix needs the
content width outside render — `EditorScroll` is the right home, since it
already holds `contentRows` for the same reason — plus wrap-awareness in
`EditorActions`, which dispatches vertical motion generically through
`MultiCaret::MOVE_METHODS` and would have to special-case it. That is a third
deep change and was not made in the same sitting as the two above.

Soft wrap is **off by default and opt-in via `Alt+Z`**, so the gap is reachable
only by someone who asked for wrapping — which is the reason it was acceptable
to ship the rest without it, not a reason to leave it.

**Click mapping closed it** (same day). `EditorViewport` carries the wrap width
— it is the VO whose whole job is "render-produced geometry hit-testing needs" —
and `EditorMouse` walks segments the way the row assembler does, resolving both
the line AND the column within its segment. The walk is bounded by the line
count rather than trusting `nextVisibleLine()` to advance, because a walk that
cannot terminate structurally needs a guard branch nothing can reach to test.

**The v1 is therefore complete**: render, toggle, caret motion, hit-testing.

**And its one limitation is gone too (2026-09-03).** `EditorScroll` carries a
`vScrollSegment` — rows of the TOP line hidden above the viewport — so a caret
far along a line taller than the pane is reached by scrolling INTO that line.
Every plain `vScroll` write resets it, because moving to another line means
starting at that line's first row.

It was priced as the saving that made soft wrap affordable, and deferring it
was still right: it is a small change ONCE the row-counting clamp exists, and
that clamp only exists because shipping without it exposed a real bug. Found
the limit was reachable by writing an assertion that assumed it worked — the
test that pinned it as a boundary is now a test of the opposite.

Code vision, which this was supposed to make nearly free, is built too.


## Semantic highlighting is a LAYER, not a highlight backend (2026-09-04)

phpantom advertises `semanticTokensProvider` with a 15-type legend; phpactor
does not — the same split as `foldingRange`, `inlayHint` and `codeLens`. Porta
had never asked: `grep -rn semanticTokens src/ plugins/ tests/` returned
nothing.

The obvious shape — a `SyntaxHighlightProvider` that colours from the server —
is wrong, and two independent facts say so:

- `HighlightRegistry::providerFor()` returns exactly ONE provider, chosen by a
  user-facing id, and `available()` lists them as choices on the Settings page.
  A semantic *backend* would therefore either lose lexical colouring or appear
  as a bogus fourth option. This repo's own `.porta.json` selects **chroma**, so
  the machine it ships on is not even using the built-in Tempest backend.
- phpantom's legend has no `string` and no `number`. Semantic tokens cannot
  carry a whole file's colouring even in principle.

So the colours ride as render-time spans through `LineSpans::forLine`, in the
pass that already composes selection, search, diagnostics and occurrences.

**Why overlaying a FOREGROUND is safe**, which is the part that looked wrong at
first: a span close cannot know which lexical colour to restore. It does not
need to. Every colour theme's `after()` returns `Sgr::DEFAULT_FG`
(`DarkTerminalTheme.php:41` and its Light/HighContrast twins; `PlainTerminalTheme`
emits no colour at all), so lexical markup is ALREADY bracketed "open colour …
restore default fg" per token. A semantic span closing the same way is
structurally identical to what the highlighter itself emits.

Consequences worth keeping:

- `src/Highlight/` is untouched. The single-backend registry and the
  byte-preservation guard stay exactly as they are, and no ANSI is re-sliced —
  the recorded scroll-latency trap.
- No new `Porta\Ui\Role`. LSP's types map onto the `TokenTypeEnum` palette the
  four terminal themes already define, so the theme-lock contract does not move.
- Colours resolve at RENDER time against the live switchable theme, so a theme
  switch recolours immediately instead of at the next save.
- With no capability, no tokens, or no server, the rendering is byte-for-byte
  what it was — which is also the phpactor path, for free.

**Two fixture rules this round pinned**, both because positional data is
unforgiving: the mock server's legend order is deliberately SCRAMBLED, since
type and modifier indices address the server's own legend and a client that
hardcoded the conventional order would paint a property as a method and pass
anyway; and both the token stream and the legend are refused WHOLE when
malformed, because dropping one bad entry shifts everything after it and
produces confident wrong colours rather than none.

## The server was talking and nothing was listening (2026-09-04)

The whole tree registered TWO LSP notification handlers, both in `LspSession`:
`publishDiagnostics` and `window/logMessage`. `window/showMessage` — the channel
a server uses to address the USER — had no handler at all, while a complete
`Notifier` and a 100-entry `NotificationLog` sat unused. `$/progress` had no
handler either, and `window.workDoneProgress` was never declared, so a
spec-compliant server would not have sent progress even if something had been
listening; both halves were needed before anything could be reported.

`window/showMessageRequest` keeps its safe default answer. Sending a server a
choice the user never made is worse than declining to choose.

The progress store is bounded. Tokens are invented by the server and cleared by
the server, so work that begins and never ends would grow it for the life of the
process, on a single event loop. Past the cap the NEW task is refused rather
than evicting a running one, whose end would then clear nothing.

## The status bar answers a left click (2026-09-04)

`StatusBarWidget` had exactly one hit-test — `menuAt`, for right-click menus —
so the numbers people look at most were inert text. `decisions.md` already
recorded clickable status segments as reopened; this is that decision continued,
not a new one.

Two differences from the right-click spans, both forced. They are reported
relative to the RIGHT segment, because only the widget knows where that segment
starts — after the leading space, the left text, and a gap it computes from the
terminal width; a flash or a truncated right side therefore offers no hit region
at all. And they carry an ACTION ID rather than a closure: the menus come from
contributors who already hold what their menu needs, while these cells are
core's own, and a status bar rebuilt every frame should not have to reach the
editor and the command registry just to describe itself.

Dispatch is its own small router because the two kinds of verb are reached
differently and neither can stand in for the other: the tool windows are
registry commands, while `goto_line` is an editor action — the editor's action
router is built inside `CodeEditorWidget` rather than registered as a service.
Bundling the four collaborators behind one class is what kept `MouseRouter` at
+1 constructor argument instead of +4; that +1 still moved two ratchet pins, and
`mago.toml` records the bisection.

## A capability gate needs a THIRD settle point (2026-09-04)

Both `SemanticTokensRefresher` and `CodeLensRefresher` refreshed on
`ActiveBufferChanged` and `BufferSaved`. Neither covers the file you launch
with: it is opened BEFORE any session exists, so its `ActiveBufferChanged` finds
no capability, asks nothing, and nothing asks again. The one buffer you actually
start looking at was the one that never got coloured — until you happened to
save it. Both listen for `LspStatusChanged` now.

Found by an E2e that opens a file in the RUNNING IDE and waits for the tokens;
every unit test constructed the controller itself, which proves the class works
and never that anything builds it or reaches it in time. That is the same shape
as the round that shipped a dead LSP controller with all six gates green and
100% coverage — see `porta-self-registering-services`.

## ~~Breadcrumbs got the server's symbols; sticky scroll did not~~ — CLOSED (2026-09-04)

`LspMapper::symbolScopes()` turns a `documentSymbol` tree into the scope shape
`BreadcrumbScanner::scan()` produces, so `select()` reads it unchanged and every
language gets a breadcrumb trail.

`stickyHeaders()` reads that same shape and would work identically — but its
caller is `CodeEditorWidget::stickyHeadersAt()`, and the widget cannot reach
`DocumentScopes` without a 33rd property on the class that already pins
`too-many-properties`. The round that added parameter info met the same ceiling
and answered it by folding two fields into one VO; there is no equivalent fold
here, and hunting for one to buy a slot would be contrived.

The honest options, none taken yet:

- **Move the pin.** Rejected for now on the standing note that this class should
  not move that ratchet again — three features running have already pushed its
  method count.
- **Extract `stickyHeadersAt()`** into a collaborator holding both the widget and
  `DocumentScopes`. Architecturally right and continues the split-up of the
  widget, but it sits in the render sequence, which is where this project's
  worst bugs have come from — not worth doing as a by-the-way.

**Closed the same day, by a third option neither of those was.** The blocker was
one property, so one was paid for rather than borrowed: `$scopeTimer` and
`$scopeTimerKey` were always a single fact — a timer id with no idea what it is
waiting to rescan is nothing — and folding them into `Editor\ScopeRescan` freed
the slot that `Lsp\DocumentScopes` now occupies.

`CodeEditorWidget::scopes()` therefore chooses the source itself: the offline PHP
scan, or the server's symbols for everything else. Both readers — the trail and
the sticky band — go through it, so they can no longer disagree about what
encloses the caret, and `BreadcrumbTrailProvider` gave up its own copy of the
decision. It costs one new namespace edge, `Widget>Lsp`, allowlisted in the same
commit.

The lesson is the ratchet's, not this feature's: a pinned class can still take a
dependency, provided something it was already carrying is paid back. Hunting for
a fold to buy a slot would be contrived; taking one that was already two halves
of the same fact is not.

## Dock maximise: neither the clamp nor the units — the rect was stale (2026-09-04)

I had twice deferred a maximise toggle for the bottom panel on the belief that
`clampPanel()`'s `rows - 8` ceiling protected the layout and could not safely be
exceeded. Measured: it does not. Rendering an Ide with the panel set to
`rows - 8`, `- 6`, `- 4`, `- 3` and `- 2` degrades nothing — the ceiling is a UX
choice about keeping the editor usable, not a safety bound.

The real obstacle is that `nudgeBottomPanel()` sized the panel from
`$renderer->getWidgetRect($panel)->rows`, a value that only refreshes on a
FRAME.

**Correction (2026-09-04, same day).** This entry first called that a *units
mismatch* — the rect reporting rendered height with chrome, against the content
rows `setPanelRows()` takes, "a panel set to 9 measured 11". Measured properly
through a running Ide: they agree. The terminal panel holding 12 content rows
reports a rect of 12, and the results panel holding 10 reports 10. There is no
chrome in that number and nothing to convert. The claim was wrong, and it is
left here rather than deleted because it is the second time a plausible
mechanism went into this file without a measurement behind it.

What is actually wrong is **staleness**, and it is easy to reproduce: two
presses inside one input burst both read the pre-press rect, so the second
computes the height the first already set and the panel moves one row for two
presses. A held key is exactly that burst. Before the first render the rect is
absent altogether and the nudge is a silent no-op.

**Closed.** `DockPanelWidget` gained `visibleRows()` — the getter beside
`setVisibleRows()`, the same number in the same unit — and the controller reads
it instead of the rect. One number, owned by the panel, correct between frames.
That unblocked the maximise toggle (`Ctrl+Shift+'`, PhpStorm's chord): restore
needs to reproduce a height exactly, which is the thing a frame-behind rect
could not do.

Two details the implementation settled:

- Maximise deliberately exceeds `clampPanel()`'s `rows - 8`, which is a
  keep-the-editor-comfortable policy and the wrong one for a panel you have
  just asked to take over. It uses `rows - 6`: the menu bar, tab strip, dock
  strip and status bar, plus a couple of editor rows.
- A nudge off a maximised panel lands on the ordinary ceiling rather than one
  row down, because a nudge is an ordinary resize and keeps the ordinary
  clamp. Leaving maximised mode returns you to the normal range, which is what
  the press was asking for.

Maximised state is never persisted: it is a thing you are doing now, like a
full-screen window, not a layout you chose. A hand-chosen height (drag or
nudge) clears it, so a later toggle cannot snap back to a size you have moved
away from, and a terminal resize re-takes the new screen instead of falling
back to the stored proportion.

The first attempt was reverted rather than shipped half-right; this is the second.

## Review pass on the PhpStorm-parity increments (2026-09-05)

Nine increments shipped across two days, each through all six gates plus
diff-scoped mutation testing. This pass asked the question the gates cannot:
what would a defect look like if it passed them? Three hypotheses, two real.

**Confirmed — the method separator was drawn on every row of a soft-wrapped
member.** Spans are in the LINE's display columns; a rule anchored at column 0
is inherited by every row that line occupies. A member whose last line wraps got
three stacked underlines instead of one rule. Unreachable without soft wrap ON
(off by default) *and* a member long enough to wrap, which is why nothing
noticed.

The fix is per-ROW gating: the rule is emitted only for the segment the line
ends on. Two things fell out of it worth keeping:

- `decoratedLine()` was clamping its padding to `$hScroll + $contentWidth`, and
  wrapping forces `$hScroll` to 0 — so on a wrapped line the bound described the
  FIRST row's window whatever row was being built. It gets the row's own offset
  now. That was a latent inconsistency, not introduced by the separator.
- The first fix also anchored the span at the row's start column. Measured: that
  parameter changes nothing, because spans are already clipped to the row's
  window. It was removed rather than kept as decoration — mutation testing found
  it by surviving, which is the useful half of a survivor.

**Confirmed — Last Edit Location moved the caret after a REFUSED open.** Walking
back to a file that has since been deleted flashes "Cannot read …" from the
opener and returns; the navigator then set the caret to that line number in
whatever file was still on screen. A line number from another file. One guard,
and an E2e that deletes the file mid-scenario.

**Refuted — the deleted file itself.** `BufferService::openFile()` already
guards `is_file()`/`is_readable()` and reports it, so nothing opens an empty
buffer for a path that has gone. Only the caret half was wrong.

**Third, and fixed the same day:** neither `RecentFilesStore` nor `EditHistory`
followed a rename, where bookmarks and breakpoints do (see *Renames carry their
marks*). `FileRenamed`'s own docblock states the obligation — "everything keyed
by path has to follow, and each store knows only its own keys" — so each got the
listener the others have. The rename E2e that already existed for bookmarks
grew the two assertions rather than a second walk through the same menu.

`EditHistory::rename()` re-collapses afterwards: a rename can make two
neighbours the same place (edit `A:5`, edit `B:5`, rename B to A), and the class
promises one place is one entry. Without it a press would appear to do nothing.

**A weak assertion found on the way.** The store's "a re-show writes nothing"
test compared `filemtime` against a value read moments earlier — two writes
inside one second share an mtime, so a same-second rewrite slipped past. Both
that test and the new one backdate the file first. Mutation testing found it by
surviving the removal of an early return.

## The parity features against SERVER scopes, not just the scanner (2026-09-05)

`CodeEditorWidget::scopes()` answers from the offline PHP scanner for a `.php`
file and from `Lsp\DocumentScopes` — the language server's `documentSymbol`
answer — for everything else. `DocumentScopeController` returns early on
`.php`, so the two sources never mix. Every test of the round's scope-reading
features used PHP, which is to say none of them exercised the half that runs for
every other language.

Checked, and the shape holds: `LspMapper::SCOPE_KINDS` maps the LSP kinds onto
the same `namespace`/`type`/`function` vocabulary the scanner emits, so method
separators land correctly on a TypeScript buffer from server scopes. Pinned in
`StickyScrollLanguagesTest`, which already had the seam for hand-set scopes.

**One real difference: Copy Reference was spelling every language as PHP.** A
TypeScript method came out `Service::handle`, which is not what a TypeScript
project searches for. The join follows the language now — `\` and `::` for PHP,
`.` for everything else.

PHP is named and the rest share a default rather than each getting an entry: a
scope carries a label and a kind and no language semantics, so anything finer
would be a table of guesses about languages nothing here tests. `.` is what
JavaScript, TypeScript, Python, Java and Go write. A `::` language reads a
little wrong, which is the smaller cost.

**The fixture was answering with no usable ranges, and is fixed.**
`tests/Support/mock-lsp-server.php` answered `documentSymbol` with
`selectionRange` but no `range`. The two are read by different consumers and the
spec requires both: `flattenSymbols()` (the File Structure popup) prefers
`selectionRange`, because it wants where to JUMP; `symbolScopes()` needs
`range`, because a trail asks which scopes CONTAIN the caret and a name's extent
cannot answer that. A symbol with neither is skipped, so the reply produced zero
scopes and a test written against it would have read as a broken feature rather
than a thin fixture.

With `range` added, `IdeLspFeaturesTest::testANonPhpBufferGetsItsScopesFromTheSERVER`
drives the whole path through a running IDE and a real server process: a
JavaScript buffer gets a breadcrumb trail, and the member separator lands on the
`endLine` the SERVER reported. Removing either range from the mock times the
test out, which is the shape this had been missing — everything else about
non-PHP scopes is proved by setting `DocumentScopes` by hand.

## The crash in the error log had three sites (2026-09-05)

Round 7 began by reading `.porta/error.log` rather than surveying for features.
It held one distinct failure, twenty-one times: `RenderException: rendered line 0
with width 8, exceeding the available 1 columns`. That exception escapes the
render loop, so each entry is the IDE going down mid-session.

`computeGeometry()` floored the content at one column and put no ceiling on the
gutter, so below about nine columns the row was `gutter + 1` whatever the pane
had been given. **The gutter is all-or-nothing now**: it is a fixed run of
single-column cells and cannot be cut through a marker, so below the width that
seats it, the stripe and one column of text, it goes and the text keeps the
pane.

**Fixing it found two more sites**, which is the part worth recording:

- The past-EOF `~` is a gutter cell, and `str_pad('~', 0)` returns `'~'` — so
  the `max(0, …)` wrapped around it looked like a clamp and never was one.
- `renderStickyRow()` builds its own gutter and overflowed identically. Reaching
  it needs a scrolled buffer with scopes, which is why the first sweep walked
  past it; sticky scroll is ON by default.
- And a third, outside the editor: `TabBarWidget`'s side strip emitted a fixed
  24 columns whatever the pane was, so it overflowed at EVERY width below 24.

Three widgets — tree, status bar, horizontal tab bar — were already correct, so
the pattern existed three times over and the broken one simply had no test at a
hostile width. `tests/Support/RendersExactWidth` is the shared assertion now,
replacing two private copies that had never been pointed at one.

**Its sweep refuses a widget that renders nothing.** An empty pane satisfies
"every row fits" the way an empty pane satisfies "the content stopped changing",
so every widget in the sweep is given a provider or a row set first — four of
them needed opening before they drew anything at all.

**The invariant is "no row EXCEEDS its pane", not equality.** The first version
asserted equality and would have failed the side tab strip for behaving
correctly: it asks for a stable intrinsic width and the layout pads the rest.
Its stricter contract — exactly `min(24, pane)` — is asserted in its own test.

### The strip's position is owned by WorkspaceLayout, not the responsive rule

Clamping stopped the overflow; it did not stop a 24-column strip taking most of
a 30-column terminal. Below twice its own width it returns to the top now.

The question was who remembers the position to restore. `ResponsiveLayout`
holding it needs telling when the user changes the setting, and the obvious
route — the settings applier calling into it — added a constructor argument to
**eight** test construction sites. `WorkspaceLayout` already performs the
surgery and already receives the user's choice at boot and on every change, so
putting the memory there cost **one** site and fixes the staleness properly: a
position chosen while the strip is displaced replaces what gets put back, where
reading the boot config would restore an abandoned preference.

A configured top or bottom bar is never touched — there is nothing to rescue and
moving it would be inventing a preference.

### Two defects the UI survey found alongside it

- **Alt+X on the Problems panel exported the last GREP**, closed the panel you
  were reading and opened that file over it. The key is offered by every GROUPED
  panel, and Problems is grouped so it can be read by file; the handler was not
  routed by the bound tab, unlike its sibling rerun handler — which is why F5
  was correctly inert there while Alt+X was not.
- **A bookmark was invisible on any line with a diagnostic**, including a HINT.
  The bookmark outranks it now, because it is the only one of the two with
  nowhere else to show: a diagnostic also colours the line number, paints the
  error stripe, writes itself inline and lists itself in the Problems panel. The
  cost — severity no longer reads from the marker glyph without colour on a line
  that is both — is stated in the ordering comment rather than left to
  contradict the code.

### Mutation testing was right twice where my reasoning was not

It reported the tab strip's focus restore as deletable. I put that down to a
stale coverage map after many edits in one run and said I would re-check later.
A control proved it right: both responsive rules fire on the same `apply()`, the
sidebar's threshold is the wider one, and the sidebar's own focus restore was
doing my assertion's work. **The assertion passed for a reason unrelated to what
it claimed** — the same shape as the joins this round exists to fix.

Earlier in the same round it also showed that I had COPIED the sidebar's
focus-restore condition rather than sharing it: eight survivors on the duplicate,
all killed by extracting the one method both paths now call.

*Re-opens on:* nothing for the crash. The threshold at which a side strip yields
(`SIDE_TABS_BELOW`, twice the strip's width) is a UX choice and would re-open on
a measurement that a narrower editor is still usable.

## Carried forward from round 7's survey (2026-09-05)

Not built, and deliberately not: the round was scoped to the crash and the
defects behind it.

**`FileDeleted`'s re-open condition is met** — and it landed in `f5b8f8cb`. It
was deferred "the day anything else needs one". Four path-keyed stores follow
`FileRenamed` — bookmarks, breakpoints, `EditHistory` and `RecentFilesStore` —
and a DELETE stranded all four. `BookmarkStore::load()` self-heals at startup;
the other three did not.

Worth keeping from the build: nobody CHECKED the condition. It was met by the
rename round that created the stores, and it sat met until a survey read the
entry again — the same failure the 2026-09-03 note records for code vision.
A stated re-open condition is only as good as the habit of re-reading it.

The delete fact carries one clause the rename did not: a DIRECTORY takes its
whole tree, while the stores are keyed by the files inside it, so a listener
asks `ProjectPaths::isUnder()` rather than comparing paths.

**Writing that sentence found the same hole in `FileRenamed`**, and it was the
worse one: the tree offers Rename… on a folder, and an exact-match `rename()`
matched nothing inside it. The stores kept every mark on a path that no longer
existed, and the OPEN TAB kept pointing into the old directory — so the
reconciler flashed "deleted on disk" for a file that had merely moved, and
saving it wrote the old folder back into existence, because `dumpFile()`
creates the directories it needs. Fixed in the same round;
`ProjectPaths::moved()` is the subtree half of `isUnder()`, and `TreeOperations`
re-points every open buffer under a renamed folder.

**Two features, both the round-4 shape — data that arrives and is discarded:**

- ~~`CompletionItem::$detail` is fetched, mapped onto every item
  (`LspMapper.php:115`) and read by nobody.~~ **Drawn since `b4e9d7ea`** — the
  popup's type column, with the name served first and the type taking what is
  left. `documentation` followed in `4536c1c1` — a panel beside the popup,
  on the same resolve round-trip. This bullet is closed.
- LSP `source` / `code` / `codeDescription` are dropped at
  `diagnosticsByLine()`. Three bundled plugins work around it by
  string-prefixing the code into the message, and `RefactorController` copies
  `code`/`source`/`data` onto its `codeAction` context from fields that can
  never be present — so quick-fix matching by diagnostic code is silently
  degraded, with a comment explaining why those keys matter.

  **This bullet is closed.** `code` and `source` landed in `143b4c3f` (a defect,
  not a feature: the `codeAction` context was being built out of absent keys)
  and `tags` in `334ff0f1`.

  **`codeDescription` was measured and turned down (2026-09-06).** phpactor's
  whole diagnostic for an unused import is:

  ```json
  {"range": {...}, "severity": 2, "code": "worse.unused_import",
   "source": "phpactor", "message": "Name \"Other\" is imported but not used",
   "tags": [1, 1]}
  ```

  Six keys, and Porta now reads all six. There is no `codeDescription` — it is
  the server's link to its rule's documentation, and neither bundled server
  sends one (phpantom_lsp publishes no diagnostics at all). Building the
  "open this rule's docs" verb would be a command with nothing to open.
  **Re-open** when a server is observed sending it.

  **The plugins' string prefixes were a different gap than this bullet said,
  and it is closed.** `plugins/mago` built
  `"no-empty-catch-clause: Do not use empty catch"` by hand because
  `PluginApi::setDiagnostics()` took line, column, severity and message and
  nothing else — a PLUGIN API gap, not an LSP one.

  Built as the round it needed rather than a cleanup, because the middle layer
  changes what people SEE: the Problems panel shows the rule at the END of the
  row, `setDiagnostics()` takes a `code`, and mago and psalm stop prefixing. The
  sentence reads clean inline; the rule is what you search for, not what you
  read past. Two E2e tests pinned the prefixed form and failed — updated to
  assert the SPLIT (no prefix inline, the rule at the end of the panel row)
  rather than just the new string.

**`labelDetails` — measured and turned down (2026-09-06).** LSP 3.17's
`CompletionItemLabelDetails` arrives from phpantom_lsp on every item
(`{description: "App"}` for a class, the owning class for a member) and Porta
drops it. VS Code draws it as a dim suffix after the label, so it looks like the
obvious next thing.

It is redundant HERE. The case that would justify it is two completions with the
same name — and for `new Th` phpantom sends two items both labelled `Thread`,
with `detail` `Thread` and `Swoole\Thread`. The type column already tells them
apart, using the more informative of the two strings. A third column inside
forty-six would come out of the name's width to repeat what sits beside it.

**Re-open it** if the row ever loses its type column, or if a server turns up
sending `labelDetails.detail` — the parameter list, which phpantom folds into
the label instead.

## Round 8 — the machinery was there and did not reach (2026-09-07)

Started by reading the logs, as round 7 taught. This time they were CLEAN, which
is the finding: `error.log` holds only round 7's twenty-one crashes (fixed);
`freeze.log` has 213 events of which 166 predate the 2026-08-25 slice fix, and
since it there are ZERO hard blocks (was 41) with a median stall of 0.56s;
`perf.log`'s 43 slow paints are all on one day, none in the twelve since. Do not
re-survey those without new entries. (**Overtaken 2026-09-07** — there are new
entries; see the log reading below.)

So the round was four instances of one shape — the capability exists and one
call site, one gate or one catalogue row does not use it:

1. **Find Usages was flat** while Problems and Find-in-Files pass
   `searchResults()` for a reason that applies harder to usages. Structure is
   grouped too, and that is the opposite of the obvious answer: one file gets
   ONE header, where ungrouped means the same path printed on every row.
2. **The doc gate could not see the editor's binding channel** — 86 ids. Widened
   to the subset with no defence (a verb on a MENU), which is four. It also
   matched on a SUBSTRING (`ctrl+shift+u` inside `ctrl+shift+up`; `f1` inside
   `f10`) and could not read the doc's `Alt+Shift+↑/↓` shorthand — that
   shorthand is good writing, so the gate learned it rather than banning it.
3. **The Notification Log had no catalogue row**, so no key and no Settings →
   Keymap line. `Alt+9`.
4. **A key whose bundled plugin is unlisted now explains itself** instead of
   matching nothing in silence.

**The plan said warn at BOOT for 4, and that was wrong.** Built it; the
settings-page golden failed with the warning as a toast over the page, which
showed it would fire every launch forever — including for someone who dropped a
plugin deliberately, with no way to say so. Explaining at the key press is
silent otherwise and names both the verb and the setting.

**Two tests passed for the wrong reason and were caught by controls**, which is
the reusable part. The doc gate's first version reported 29 missing keys because
it could not read a shorthand; and the first E2e passed because
`phpunit.xml.dist` pins `PORTA_EMBEDDED_PLUGINS=/nonexistent` suite-wide, so the
plugin was absent for a reason unrelated to the config under test.

## The advertised-but-unasked survey, run (2026-09-06)

The inventory the entry above deferred, now measured rather than described.
Both servers were initialized and their whole capability set dumped, then
compared with every `textDocument/*` and `workspace/*` method Porta sends.

Porta sends 23 textDocument methods. Four things the servers advertise and it
never asks for:

- **`textDocument/documentLink`** (phpantom only). Measured on a file with URLs
  in a docblock, a `@see`, and a `require __DIR__.'/Other.php'`: it returns
  exactly ONE link, the require path. It does not link URLs or `@see` targets.
  So the feature is "include paths are clickable", which in autoloaded code is
  a handful of lines in `bin/`. **Turned down**; re-open if a server starts
  linking docblock references.
- **`textDocument/onTypeFormatting`** (phpantom only). Porta already indents,
  pairs and re-indents as you type. Server-driven formatting on `}` would
  arrive asynchronously into a buffer the user is still typing into, fighting
  the local behaviour for the same keystroke. **Turned down** on that conflict,
  not on cost.
- **`textDocument/linkedEditingRange`** (phpantom only). Its use is renaming
  the halves of an HTML tag pair together; PHP has no equivalent worth a
  round-trip. **Turned down.**
- **`textDocument/inlineValue`** (phpactor only). **This one is worth
  building** — see below. Built; and because "phpactor only" is the whole
  problem when you run phpantom, built twice — the second route reads phpantom's
  semantic tokens for the same ranges.

Also confirmed: `prepareCallHierarchy` and `rangeFormatting` are sent to servers
that advertise NEITHER, which matches the capability gates already in place —
they degrade to nothing rather than erroring.

## Inline debug values — built (`see below for the ownership it settled`)

**Built.** The entry below is kept because it is the reasoning the build
followed, and because the ownership question it refused to answer in code is
answered here:

- **The join lives on the DEBUG side**, in `Porta\Debug\InlineValues` — its own
  class, not a method on the controller that already carries eighteen
  dependencies. That side knows WHEN the join means anything: the answer stops
  being true the moment the debuggee steps.
- **`Debug>Lsp` is a new namespace edge**, in the allowlist with its reason. One
  of the two sides had to know the other; no cycle results.
- **The trailing cell is settled by PRECEDENCE, not by sharing.** Debug values
  get their own channel — `LineAnnotationSink` is one map that REPLACES, so two
  writers would clobber each other on every step. In the cell: debug value,
  then inline diagnostic, then blame. The value wins because it exists only
  while stopped, and the other two keep their other surfaces.
- **`Role::DebugValue`** rather than reusing `Inlay`'s grey, moving the enum
  ratchet to 60: an inlay hint is what the code MEANS and is always there, a
  debug value is what it currently HOLDS and only while stopped.

One real bug came out of writing the wire test: `showStop()` read
`$currentFrameId`, which `loadScopes()` sets AFTERWARDS — so the request would
have asked about the previous stop, one frame behind, every time. The frame id
is passed explicitly now.

## The doc-to-code references were audited, and no gate is possible (2026-09-07)

Prompted by finding a "*Still open*" entry that had been closed five rounds
earlier. If one claim had drifted, others might have, and this file is what
`CLAUDE.md` points at for "settled — do not re-propose without new evidence": a
stale entry costs a round rather than merely misinforming.

**Measured: 78 backticked `Class::method()` references across `docs/`, and all
78 are correct.** Three name symbols that no longer exist — `GitClient::diff()`,
`WorkspaceView::cases()`, `DecorationStore::bookmarkedLines()` — and every one
does so DELIBERATELY, in a sentence about what used to be there
("were byte-identical", "now ranges over", "was written and then deleted").

**So the gate that suggested itself must not be built.** A decision record's job
includes naming what is gone, and the thing that separates a historical
reference from a broken one is TENSE, which no matcher reads. A gate here would
be satisfied only by an exceptions list, and an exceptions list is where the
wrong entries would hide. This is the counter-case to the round's other habit:
not every prose-to-code claim is gateable, and the tell is whether the prose is
about the present.

What WAS wrong was a claim with no symbol in it at all: the bookmarks entry's
"*Still open:* a DELETE strands marks the same way … worth a `FileDeleted` fact
the day anything else needs one", implemented in round 7 and left saying open.
Corrected above. The lesson is narrower and more useful than "audit the docs":
**an entry that says OPEN is read as a to-do, so it is the one kind of staleness
that costs work rather than accuracy.** Those are worth re-reading when a round
closes something nearby.

## The combination nobody asked about: paste history × multiple carets (2026-09-07)

Round 6's lesson applied to this session's OWN work rather than to someone
else's: after a feature round, ask which COMBINATIONS are untested. Four verbs
were added to `EditorActions::CLIPBOARD_LINE_ACTIONS` this session, and that is
the list consulted when extra carets exist — so every one of them met the
multi-caret path for the first time.

Three behave exactly like the pre-existing `toggle_comment` does there, which is
the answer that needed checking rather than fixing. **`Ctrl+Shift+V` did not.**
With two carets, `Ctrl+V` pasted at BOTH and paste-from-history — picking the
same entry — pasted at ONE.

The cause is a timing asymmetry that no amount of reading the two verbs
side by side would show: `Ctrl+V` is intercepted by
{@see \Porta\Editor\MultiCaret::handleInput()} BEFORE the action table sees
it, while the history pick arrives later, from an overlay callback, long after
that interception could happen. Two keys that differ only in WHICH text they
paste were differing in where.

`pasteBlock()` is public now, and both keys go through it. That is the whole
fix, and the reason it is worth an entry is the method: the defect was found by
listing what the round had touched and asking what it now met, not by a gate, a
mutant or a crash. None of those could have found it — every one of the verbs
was correct alone.

## Cross-file undo (built 2026-09-07)

The last of `decisions.md`'s own open entries, and the half that needed a
decision rather than a snapshot: `Ctrl+Z` is per DOCUMENT, so a rename across
twenty files was twenty undos in the buffers that happened to be open and
nothing at all for the rest.

**It is a separate verb (`Ctrl+Alt+Z`), not a smarter `Ctrl+Z`.** An undo stack
belongs to a document; making one reach into other files would be a bigger lie
than a second key, and it would have to answer what happens when a refactoring's
entry interleaves with per-buffer undos — a question with no good answer and no
user behind it. For the same reason ONE refactoring is remembered, not a stack.

**It refuses rather than guesses, and it checks everything before touching
anything.** If any participant has moved on — a buffer typed into, a file
changed on disk — nothing is undone and the message names the file. Undoing a
buffer edited since would take back the typing, because the document's stack has
no idea which step was the refactoring; restoring a file changed on disk would
discard whatever changed it. A partial undo of a refactoring is the state nobody
can reason about.

Two smaller decisions worth the words. A buffer CLOSED since is not a blocker —
there is nothing left to undo for it, and refusing the whole thing because a tab
was shut would make the verb useless in the workflow it exists for. And undoing
a disk file announces `FileRewritten` carrying the REFACTORED text, because that
version is about to exist nowhere: without it, undoing loses the refactoring
irrecoverably, which is the hole the forward direction was just fixed for.

**Mutation found a test of mine passing vacuously**, again by the familiar
route: `testUndoingClearsWhatItTookBack` opened the buffer AFTER applying the
edit, so with no buffer and no file on disk nothing was ever recorded,
`hasPending()` was already false, and the assertion passed against a class that
cleared nothing. The `begin()` mutant is what surfaced it. Two more survivors
were real gaps rather than weak fixtures — nothing checked that a SECOND
refactoring forgets the first, in either payload shape.

## A rewritten file keeps what it replaced (built 2026-09-07)

The other half of the same open entry, and the half that was a hole rather than
a limitation. `local-history` snapshots on `BufferSaved`, which needs an open
buffer; the editor's undo stack is per document. So a twenty-file rename wrote
eighteen files with **no recovery path at all** — measured, not assumed: the
plugin's only snapshot hook is `onFileSaved`, and a `dumpFile()` from
`WorkspaceEditor` fires no save.

`Event\FileRewritten` is a new `PluginObservable` fact, and it carries **the
text the write replaced** rather than the text it produced. That is the whole
design: the new content is on disk and needs no announcing, while the old exists
nowhere else the moment `dumpFile()` returns. `HistoryTool::snapshotReplaced()`
therefore does NOT read the file the way `snapshot()` does — reading here would
store the refactored version and call it history, which is the one version that
is no help, since it is what is on disk.

**It is deliberately not a save.** A save is something the user did to a buffer
that has its own undo stack; this is something a server asked for, to a file the
user may never have opened. An open buffer is not announced, because it has both
of those and announcing would put two versions in history for one change.

Adding to the observable set is a review point with a procedure, and the
procedure worked: the contract test refused the event until `docs/plugins.md`
described it, and `EventListenerWiringTest` refused it until the "observed only
at runtime" list said why core compiles no listener. Both failures arrived
before any thought about them did.

**Mutation says little here** — one mutant over the whole diff, because the
`src/` half is a data class, one dispatch line and one hook, and plugin code
generates none (`porta-mutation-scope-src-only`). The weight is on the two
tests: the payload is the OLD text, and a failed write announces nothing.

## Overlapping text edits are refused now (built 2026-09-07)

Taken off `decisions.md`'s OWN open list rather than the round backlog, and the
lesson is that the list was worth reading: this was filed as "not a judgement
call, just unfinished" and it corrupts files. Measured before fixing — two
overlapping edits over `alpha beta` produced **`X`**, and the outcome reported
success.

The mechanism is that the fix for one problem hid another: edits are sorted
bottom-up so earlier positions stay valid, which is correct and is exactly what
makes two edits over the same characters both land, the second clamping into
whatever the first left behind. Nothing in the pipeline had an opinion.

**The spec's precision matters, because the naive check forbids something
legal.** Ranges must not overlap, "however, it is possible that multiple edits
have the same start position: multiple inserts, or any number of inserts
followed by a single remove or replace edit" — which is how a server adds two
`use` lines at one point. So a shared start is fine, an adjacent range
(`next.start == this.end`) is fine, and only a range reaching INTO another is an
overlap.

**Refusing the FILE, not the whole edit, is a deliberate deviation** from the
letter of the spec, which calls such a WorkspaceEdit invalid outright. The
reason is this class's existing and argued rule: a resource operation that
half-applies is unrecoverable, which is why those abort; a text file left alone
is merely not yet renamed, and it is named in the outcome. Refusing 19 good
files because the 20th is malformed is what that rule exists to prevent.

**The path that matters most is format-on-save**, which this user has on with
mago's auto-format besides. A refusal there leaves the buffer exactly as they
wrote it and the save still happens — the save is the point, the formatting is
the courtesy.

**A test that was already there passed for the wrong reason.** `FormatOnSaveTest`
asserted "the invalidated editor repaints the new text" by rendering, and the
mutant that deletes the `invalidate()` call survived: the widget composes its
rows from the document either way. That is round 7's lesson verbatim — a render
cannot observe an invalidation — and it is COUNTED now.

Four mutants are judged equivalent, with the reasoning in the code: the `?? 0`
defaults for a missing coordinate are unreachable from a conforming server, and
which constant they degenerate to cannot change the verdict, because a range
missing a coordinate is empty or inverted and neither can overlap anything under
the `next.start >= this.end` test.

## The find surfaces, read off the user's own state (2026-09-07)

Picked by evidence rather than from the backlog order. `.porta/` holds no
`bookmarks.json` at all and only four local-history snapshots, so bookmark
mnemonics and a project-wide history view are features for someone else; what
it does hold is **five saved search scopes** and a `search-history.json`. This
user searches.

Two findings followed, one of them a gate's blind spot again.

**A modal's own keymap is a THIRD binding channel.** Round 8 widened the
keybindings-doc gate from `ActionCatalog` to `EditorKeymap`; `FindInFilesModal`
and `FindBarWidget` each carry a private map merged with nothing. The case for
documenting those is stronger than the menu argument, not weaker — a menu item
at least advertises that a verb exists, while the modal draws its toggles as
bare glyphs (`.*`, `Aa`, `\b`, `Pc`) that are clickable and silent about their
chords. Measured: the two surfaces bind 28 keys, 18 of them their own rather
than a modal convention, and the doc's Search section named 9.

**And the gate had to learn SCOPE, not just boundaries.** Matching against the
whole file reported three missing keys and called the rest documented —
including the modal's `Alt+↑`/`Alt+↓` query recall, which the doc mentions only
as the editor's previous/next declaration. A gate satisfied by a row that says
something ELSE about the same key is worse than no gate, because it reads as a
guarantee. Reading the `## Search` section alone took the count from 3 to 9,
which are now written down. That is round 8's substring lesson one level up:
the wrong scope rather than the wrong boundary.

**The in-file find bar had no query history at all** while find-in-files
persisted one and recalled it with `Alt+↑`/`Alt+↓`. It shares that store now
rather than getting a second: one list of what you looked for in this project,
reachable from either surface, so what you just found in a file is one
keystroke away when you next want it project-wide. `Widget>History` is a new
allowlisted edge with that reason. Recorded on `Enter`, never as you type —
otherwise every prefix of every query lands in the list and the one you meant
is twenty entries down.

**One doc row was wrong and I nearly shipped it.** I wrote that `Alt+T` matches
the query's words in any order; it cycles a `TokenScope` — anywhere, only
inside comments and strings, or everywhere except them. Reading `TokenScope`
instead of inferring from the glyph is what caught it. `claims-are-not-guards`
applies to documentation as much as to code comments: no gate reads prose, so
the only check on a doc row is whether its author looked.

**Then the class, not the instance.** Round 7's habit — after fixing one, grep
for the STORES rather than for the bug — applied here means every `src/` class
with a `getDefaultKeybindings()`, and the gate discovers them by walking `src/`
rather than listing them. A hard-coded list is a checklist a new widget walks
silently past; the walk catches the next one the day it is written, and FQCN is
determined by path here so it can name the class from the file. Six more
undocumented keys, on surfaces this user reaches constantly: the results
panel's `Alt+G` (cycle grouping), `Alt+E` (collapse/expand all) and `F5`
(re-run), the terminal's `Ctrl+PageUp`/`Ctrl+PageDown` session switching, and
settings' bare `2`.

`CodeEditorWidget` is excluded from that walk WITH ITS REASON: its map is
`EditorKeymap::defaults()`, whose twenty-two micro-bindings are undocumented on
purpose, and sweeping it in would silently re-open a decision that was made and
argued. An exclusion without a reason is how a gate quietly stops meaning
anything.

**And the prose had drifted where nothing reads it.** Three docblocks in
`ResultsPanelWidget` say the context toggle is `Alt+.`; the binding is `alt+m`.
[[stale-justifications-survive-moves]] again — the binding moved and the
sentences did not, and no gate reads a docblock. Writing the doc row is what
surfaced it.

Mutation named three more, all real: dropping a recall handler's `return` falls
through to the plain-typing path that RESETS the history cursor, so the walk
silently restarts on the next press (every single-step assertion still passes);
mixing `||` for `&&` in the guard let a recall land in the REPLACEMENT field,
which is the one that rewrites files on the next `Alt+A`; and returning `false`
from the field edit skips the re-run, leaving the bar reporting the previous
query's hit count under the new text.

## Complete Current Statement, once it had a table (built 2026-09-07)

Filed one round earlier as blocked, with the blocker named: it has to know that
a statement ends in `;` and a block opens with `{`, and that neither is true in
Python or YAML. `Editor\StatementSyntax` is that table, gated over every
grammar the same way `CommentSyntax` is.

**The difference from the comment table changes what the gate is FOR.** Every
language has some comment form, so a `null` there was always an omission. Most
languages have no statement to complete — JSON, Markdown, TOML, `.env` — so
`null` here is a real answer, and the gate exists to make it a written-down one
rather than a gap that looks the same. `Ctrl+Shift+Enter` doing nothing in JSON
is correct; the test asserting it uses a language the table says `null` for
rather than one merely absent from it.

**Three rows are worth defending:** Go has no terminator (the compiler inserts
semicolons and gofmt removes them) but still braces, which is the case that
proves the two halves are independent. Python's block has no CLOSER — it is the
indent — so the closer is separately nullable. CSS has `;` and no keywords: its
`{` follows a selector, which cannot be recognised without parsing, so brace
completion is left out rather than half-supported.

**The opener is stored exactly as it is appended** — `' {'` and `':'`. The
first draft spaced it in the code and produced `if x > 1 :`; whether a brace is
spaced off the condition and a colon binds to it is a fact about each language
and belongs in each language's row, not in a separator rule.

**What mutation testing was worth here.** Six survivors, and one named a real
case: dropping the `^` from the first-word match made `} else {` — a line that
CLOSES one block and opens the next, already carrying its brace — read as a
block opener and gain a second one. Another was better answered by changing the
code than by testing it: `mb_strtolower` on a head the pattern has already
restricted to ASCII is a guard against an input the function cannot receive, so
it is `strtolower` now.

**The limit is written down as a test, not only as a docblock.** The bracket
count does not read strings, so `echo "a(b"` counts an unclosed paren and gets
one. A parser would not, and there is none here; the mitigations are that this
is an explicit keystroke and one undo step. `testAParenInsideAStringIsCountedAndThatIsKnown`
exists so the behaviour is a decision rather than a surprise — and so that
anyone who fixes it properly has a test to change.

Also caught in passing, by the test failing rather than by review: `foo(` is NOT
a line that continues. Its bracket closes to `foo()`, which is a whole statement
and does want its semicolon. The continuation rule is about what the line ends
with AFTER the closers land.

## The logs, read again — and the instrument was the defect (2026-09-07)

Round 7's habit, run once more, and worth recording because the ANSWER was
different from the one the numbers first suggested.

| log | what it holds now |
| --- | --- |
| `error.log` | untouched since **2026-08-27**. Round 7's three-site `RenderException` fix holds; the 21 entries are all pre-fix |
| `freeze.log` | **2** new entries, both `loop stalled … (recovered)` (0.67s, 0.54s), both inside one 24-second window |
| `perf.log` | **15** `slow-paint` lines, `render=100–408ms`, `write=1ms` — all inside that same 24-second window, then nothing across two later sessions |

**The window is 09:09:14–09:09:38 at `load=11–12`**, on a machine whose load at
that moment was very plausibly this repository's own gate runs. `write=1ms`
with `pendingBytes` at 70k–260k says the terminal was not draining either,
which is what a saturated machine looks like from inside a process. Two later
sessions the same morning are clean: `paintMs p50=0.7`, no slow paints, no
stalls. **No Porta defect is claimed from this**, and the next reader should
check the `load=` column before treating a burst as a regression.

**What WAS a defect is the field I nearly diagnosed it with.** Every beat line
carries `edMs p50=… p95=…`, the editor widget's share of the frame, and every
line in that window read `edMs p50=0.0 p95=0.0` while `renderMs p50` sat at
11–90ms. The obvious reading — "the editor is free, so the frame's time is
elsewhere in the widget tree" — is one the log cannot support:
`percentile([])` returned `0.0`, so an EMPTY sample set printed exactly like a
set of fast ones.

Every other timing field has a counter beside it that gives the difference
away — `paints=0` explains an empty `paintMs`. `edMs` has none: nothing in the
line says whether the editor rendered at all. So the field read the same for
"twenty renders, each free" and "no renders", and the conclusion it invited may
be exactly backwards.

An absent measurement prints `-` now, across all five timing groups. The test
asserts BOTH halves — `-` with no samples, `0.0` with a genuinely zero one —
because a change that printed `-` for everything would satisfy the first alone.

**How to apply:** this is [[claims-are-not-guards]] pointed at an instrument
rather than at prose. Before reasoning from a diagnostic field, ask what it
prints when it has nothing to say; if that is indistinguishable from a real
measurement, the field cannot carry the argument. It is the same shape as the
comment table's `default => null`, one layer further out — and the tell is the
same, a value that means two things.

Removing the ambiguity also removed a line: `percentile()`'s own empty guard
returned the `0.0` that caused all of this, and with the check moved up into
its only caller it became unreachable — the coverage gate said so before any
argument did. Deleted rather than tested, which is the third time in this
repository's rounds that a mutation-or-coverage signal has been better answered
by removing a guard than by exercising it.

While fixing it, mutation testing named a second one: the `max=` field's
milliseconds conversion was pinned by a 30ms fixture, and at 30ms a 0.1% scale
error still prints `30.0`. The fixture is hundreds of milliseconds now. **A unit
conversion needs a magnitude big enough to expose getting it wrong.**

## The missing editor verbs, two of three (built 2026-09-07)

Round 8's backlog listed six verbs PhpStorm has and Porta lacked. Two landed
(`dfa8ae85`, and the clipboard history below); one is deliberately still open.

**Start New Line above / below (`Shift+Enter`, `Ctrl+Alt+Enter`).** The COLUMN
is the whole reason these are separate verbs from Enter — Enter splits the line
at the caret, so starting a statement from the middle of one costs an End first
and starting one above costs Home, Enter, Up. `shift+enter` had been an ALIAS
for `new_line`, a second way to do what Enter already does; it has its PhpStorm
meaning now, and plain Enter still splits.

The indent rule is asymmetric on purpose: going DOWN from a line that ends in an
opener takes one more level (the rule `insertNewLine()` already follows, so the
two keys never disagree about the same position), and going UP takes none. A
line above a `}` is inside the block and a line above an `if` is outside it, and
nothing on the line says which — copying the indent is neutral where guessing is
wrong half the time.

**Clipboard history (`Ctrl+Shift+V`).** The clipboard was a `string` field on
`EditorActions`, handed to the next instance on every buffer switch. It is a
`ClipboardRing` passed by HANDLE now, which is what makes the feature mean
anything: one history across every buffer in the session, because the copy you
want back is usually from the file you just left. A string could only ever have
been handed over by value.

- **Re-copying PROMOTES rather than duplicates.** Otherwise the list fills with
  the same snippet from the four times you pasted it around, and what you were
  looking for is pushed off the end by its own duplicates.
- **Newlines show as `⏎` rather than the entry being cut to its first line.**
  What tells two copies apart is usually their shape — three short lines against
  one long one — and a list of first lines throws exactly that away. It is also
  what the picker's filter matches on, which is why this landed after the
  overlay filter and not before.
- **Picking promotes too**, so the plain `Ctrl+V` after it repeats what you just
  pasted rather than the entry you reached past. Without that the two keys
  disagree about what "the clipboard" is, one keystroke apart.

`EditorHost` gained `openListOverlay()` — the third of the prompt/jump
primitives its docblock already claimed, and the alternative was a ninth
constructor closure on `EditorActions`.

**Two mutation survivors, both judged and both VERIFIED rather than argued:**
`array_values()` in the ring and the `(int)` cast on the picked index are
type-preserving, not behavioural — the runtime survives without either
(`array_unshift` renumbers; PHP coerces a numeric string offset). Removing them
was tried, and `composer analyze` refuses both. That is the distinction worth
keeping: a line mutation cannot kill is not automatically a line to delete, and
the way to tell is to delete it and run the gate.

**Still open:** bookmark mnemonics, and Go to Super method (no LSP request
answers it; see the capability table). *Complete Current Statement* was filed
here as needing a per-language table before it could ship — it has one now, see
below.

## Type-to-filter in the list overlays (built 2026-09-07)

Round 8's step 1 gave the results PANEL grouping and a filter, and its "not in
this round" list said the OVERLAYS were left out: `Ctrl+F12` on a forty-method
class opened a list you could only walk. The overlay handled four keys — up,
down, Enter, Esc — and every printable keystroke fell on the floor.

It is universal rather than opt-in, because that is PhpStorm's speed search and
because a per-caller flag is a decision ten call sites can get wrong. The two
verbs each prefer the other's fallback, so no key is dead: the filter row
appears only once something is typed, so a list that was fine without one still
opens looking exactly as it did.

**Three decisions the tests exist to hold:**

- **Esc clears the filter before it closes the surface.** Typing is how you get
  around a long list; a typo must not also cost you the list.
- **Any filter edit puts the selection back on the first survivor and drops the
  hover**, because the ROWS MOVED. The old index names a different item, and
  picking the one you never looked at is worse than losing your place.
- **The empty-list guard returns before the movement keys and still lets a
  character through.** Every movement steps the selection modulo the row count,
  so a filter matching nothing is a division by zero one arrow-press away; and
  without the second half the filter is a trap you can only Esc out of.

**The architecture gate wrote the design.** Filtering started in the state tier,
which handed the render tier the narrowed rows — and `arch` refused the reverse
call outright (`OverlayHost <-> PickerLayout`). Handing down the RESULT works
for the rows and loses the WIDTH: the box is centered and sized from its
content, so a box measured on what survives the filter shrinks and re-centres on
every keystroke, crawling out from under the eye reading it. Layout needs both
lists, so it needs the RULE — `Widget\Support\ListFilter`, a pure function both
tiers call. The gate turned "where does this go" into a question with one
answer.

**What mutation testing was worth here: 23 survivors, and the shapes repeat.**
Eight were concat arithmetic in one line building the filter row, all alive
under `assertStringContainsString` — the same lesson round 7's completion type
column taught, so the row is pinned WHOLE now. Three were `mb_strtolower` →
`strtolower`, which an ASCII fixture cannot tell apart; and the first fixture
written for them did not either, because `Größe` has only an ASCII uppercase
letter, so both functions produce the same string. The letter being folded has
to be the multibyte one.

**And `pad: true` turned out to be load-bearing in a way no stripped-text
assertion can see.** The padding happens BEFORE the style wraps the row, so
dropping it leaves a highlight bar that stops under the text instead of crossing
the box — identical text, visibly broken row. That one is asserted against the
raw ANSI.

Three survivors are judged equivalent, all `ReturnRemoval`, all verified rather
than argued: two guard a fall-through into `typeIntoFilter`, which is reached
only by non-printable chords that `TextField::insert` rejects
(`src/Ui/TextField.php:65`); the third is the empty-needle fast path, and
`str_contains($x, '')` is `true`, so falling through returns the same list more
slowly.

## Comments work in every language now (built 2026-09-07)

Round 8's backlog filed this as "block comment is missing, and Twig has no
comment verb at all". Measuring it first turned a feature request into a defect
report: `Ctrl+/` was a **dead key in thirty-one of the forty-five grammars**
`LanguageMap::grammars()` can produce — Twig, HTML, Markdown, XML, Blade, Vue,
Svelte, OCaml, Go, Rust, Java, Kotlin, Swift, Lua, Ruby, Haskell and fourteen
more. And in CSS it was worse than dead: it inserted `//`, which is not a
comment in CSS at all, so the key silently broke the rule that followed it.

**The shape of the old answer was the bug.** `commentPrefix(): ?string` — a
fourteen-arm match with `default => null` — could not express "this language
comments with a block", so Twig's entry was literally `'twig' => null, // block
comments only`. In that shape "no comment form" and "nobody got round to it" are
the same value, which is why every gate passed for the whole life of the
feature.

So the answer is a VO with up to two forms (`CommentSyntax`), and the table
lists **every grammar explicitly, including the two that legitimately have
none** — `json` forbids comments (that is what `jsonc` is for) and `diff` is
generated. `CommentSyntaxTest` gates the table against `LanguageMap::grammars()`
in BOTH directions: a new language fails until someone says how it comments, and
a removed one fails until its row goes. That is the part that stops this
reopening.

**The two verbs are defined in terms of each other**, which is why they are one
change and not two: `Ctrl+/` prefers the line form and falls back to wrapping
each line in the block pair; `Ctrl+Shift+/` prefers the block pair and falls
back to the line form. Neither key is ever dead in a language that can be
commented at all. The block verb is character-precise — `foo(bar, baz)` with
`bar` selected becomes `foo(/* bar */, baz)` — and its un-toggle needs the
target range to BE the comment; scanning outwards for an enclosing one is a
parser's job, not a range operation's.

**Two structural decisions worth keeping:**

- **The block pair is one nested column, not two flat ones.** `['<!--', null]`
  is a plausible typo, and as two columns it downgraded a language to "no block
  form" in silence. Nested, half a pair is not a shape the table can hold. This
  came out of a mutation survivor: the `&&` pairing the two columns had nothing
  that could distinguish it from `||`, and the fix was to delete the condition
  rather than to test it.
- **`css` is the one entry that changes an answer** rather than adding one, so
  it has its own test naming why: `//` is a SCSS extension.

**A real bug came out of the mutation run**, at the usual distance from what the
mutant was about. An `Assignment` mutant on the caret arithmetic was EQUIVALENT
in every test — `$cursorCol -= 3` and `$cursorCol = 3` agree when the line
starts at column 0. Writing the case where they disagree meant an INDENTED blank
line, and there the content range ran BACKWARDS: `rtrim()` takes a wholly blank
line to length zero while the span starts at its full width. A backwards range
does not throw — `substr` reads a negative length as counting back from the end
— so it read as empty text and then deleted the indent on its way to inserting
the comment. Clamped with `max()`.

**How to apply:** an equivalent mutant is a question about which inputs the tests
never reach, not a verdict that the code is fine. Both times this round, asking
"what input would tell these two apart?" produced a case worth having, and once
it produced a bug.

One survivor was judged rather than killed: `break` → `continue` in the
already-commented scan is a pure early exit, and the pre-existing
`commentToggled` directly above has the same shape.

## Inline debug values reach phpantom too (built 2026-09-07, `3a5c726f`)

Round 8 recorded this as a whole round of its own and it was half a day, because
the measurement answered it: the feature above is gated on `inlineValueProvider`
and **phpantom does not advertise it**, so on the setup it was built for it
never fired once.

The values were never the server's. They come from the DAP frame; the server
supplies only the WHERE — which range on which line names a variable. phpantom
answers that question under a different name: its `semanticTokensProvider` emits
`variable` and `parameter` tokens whose ranges **include the `$`** (`"$count"`,
not `"count"`), which is exactly how the debug adapter names the variables in a
frame. So the fallback is a lookup against decorations already on screen, not a
heuristic that guesses at identifiers — measure the token text before assuming
a fallback needs one.

- `InlineValueRequest::forRange()` returns whether it ASKED. `false` is not an
  error and not an empty answer; it is "this server has no opinion", and only
  then does the fallback run. A server that answers with an empty list is not
  second-guessed — it said there are no variables here, and that is an answer.
- **`property` tokens are skipped.** `$this->count` is not a frame local, and
  its token covers only `count` anyway, so including them would look up a name
  the frame does not have.
- No paint after the fallback fills `$this->lookups`: `clear()` ran first, so a
  paint there could only draw the empty map it had just written.

## The reasoning it followed

`textDocument/inlineValue` is PhpStorm's "variable values shown at the end of
the line while stopped at a breakpoint". phpactor advertises it and answers it
properly. Measured, stopped inside a method with three locals, it returns one
`InlineValueVariableLookup` per variable:

```json
{"range": {...}, "variableName": "$count", "caseSensitiveLookup": true}
```

The server says WHERE and WHICH NAME; the VALUE comes from the debugger. Porta
has both halves already: `DebugController::loadScopes()` fetches every scope's
variables the moment the session stops, and `LineAnnotationSink` draws dim text
at the end of a line.

What it needs, and why it is a round rather than an afternoon: the two halves
are owned by different subsystems that have never spoken. It is a JOIN — LSP
ranges married to DAP values, keyed by variable name, invalidated on every step
— and every defect this session came from a join, not from a piece. It also
needs a channel decision: `LineAnnotationSink` is one slot per line and a
plugin already writes to it, so debug values and git blame would fight over the
same cell unless one of them yields.

**Do not start it without deciding that ownership first.**

## Local History follows a rename now (built 2026-09-05, `1d31206e`)

The entry below is kept because its reasoning is what the build followed. What
changed: `FileRenamed` IS `PluginObservable` now — the promise was made
deliberately, with the contract test's list and `docs/plugins.md` updated in the
same commit. A FILE rename is followed; a directory's still is not, for the md5
reason below, and `FileDeleted` is still not observable because local history
keeping a deleted file's snapshots is the whole point of it.

## The finding as it stood

Six path-keyed stores follow `FileRenamed`/`FileDeleted` now. A SEVENTH does
not, and it holds the most valuable data of the lot: `local-history`'s
`SnapshotStore` keys its snapshots by `md5(realpath)`, so renaming a file
orphans its entire history — at exactly the moment you are most likely to want
it. Every store above is core; this one is a plugin.

The fix is small and the obstacle is not technical. `FileRenamed` is not
`PluginObservable`, and that marker is deliberately a promise: the class is
pinned in a literal list in `PluginObservableContractTest`, named in
`docs/plugins.md`, and frozen from then on. It qualifies on every rule (scalar
payload, human frequency, documented), so the question is only whether the
public plugin surface should grow — which the marker's own docblock says is a
diff someone reviews rather than a thing you do while passing.

Two details for whoever builds it:

- **A file rename is enough; a FOLDER rename is not reachable.** `dirFor()`
  hashes the path, and md5 does not run backwards, so a directory rename cannot
  enumerate the snapshot dirs under it without a marker file recording each
  dir's source path. That is a schema change to what the store's own docblock
  calls a cache.
- **A DELETE must keep the snapshots.** They are the last copy of the content;
  local history exists precisely so a deletion is survivable. So only the
  rename half is wanted, and `FileDeleted` needs no marker.

