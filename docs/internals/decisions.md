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
  project ignores it, and that is what the warning says. ~~*Re-opens if* the
  settings screen gains a way to edit a scope other than the one in effect~~ —
  **that condition is met and the decision survives it** (2026-09-10):
  {@see SettingsScopeRow} is exactly such a chooser. It does not help, because
  switching to Project scope to store a PERSONAL default writes it into a file
  that travels with the repository, which is a different thing from what the
  user asked for. The global write is still the right write. Do not re-open on
  the chooser.
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

## The verify loop, measured — and one hypothesis killed (2026-09-08, `883ef5a9`)

A session's 25 verification cycles cost **233 minutes of test execution**, and
the suite ran three times in each: `composer test`, then `coverage` (the same
suite under pcov), then the serial `coverage:plugins`. Measured on the 16-core
box at four processes, one command at a time:

| | seconds |
| --- | --- |
| the five static gates together | **4.1** |
| `composer test` | 170 |
| `composer coverage` | 241 |
| `composer coverage:plugins` (serial) | 146 |
| `coverage:check` end to end | **387** |

**`composer lint` was not a gate.** mago's `--minimum-fail-level` defaults to
`error`, so lint exited 0 while reporting warnings — 11 of 11 runs with findings
in one session — while analyze exited non-zero every time. CI's Lint step was
green on warnings. Both scripts pass `--minimum-fail-level=note` now, and the
tree is clean at the lowest level there is. **CLAUDE.md's "Both exit 0 while
reporting issues" was half wrong, and the wrong half was the one that worked** —
which is worse than being wrong about both, because it teaches you to distrust a
signal that is fine.

**`composer verify` is two script arrays, not a runner.** Composer aborts a
chain at the first non-zero step. A `src/Console/VerifyCommand` was designed and
rejected on cost: it would have needed a subprocess seam to be testable, its own
100%-covered test, an arch-allowlist edge, and a gate list that could disagree
with the scripts it wrapped. **A script array is the scripts**, so it cannot
drift from them — only from CI, and that is what the ratchet is for.

### The hypothesis that was wrong, and why it is worth writing down

The plan proposed keeping the container cache ON under coverage and instead
autoloading every `src` file once per worker in `tests/bootstrap.php` — same
completeness, four sweeps instead of 458 container compiles, ~57s of CPU back.

**Measured against pcov directly, it contributes nothing.** A sweep before
`pcov\start()` leaves `pcov\waiting()` at 0; the same sweep after it reports all
707 files. `PcovDriver::stop()` collects `waiting()`, and pcov only enters a file
there if it was compiled while collection was started — and the bootstrap runs
long before PHPUnit's first `CodeCoverage::start()`. The totals would have
collapsed on the first run.

**And `PORTA_CONTAINER_CACHE=off` turns out to do a second job nobody wrote
down.** Because every worker compiles all of `src`, every worker's PathReducer
base collapses to `<repo>/src`, so the Merger — which keeps only the FIRST
worker's base path — resolves everyone's paths correctly. Remove the per-boot
compile and a worker whose tests touch only `src/Editor/**` reduces to that
directory instead; on merge its paths resolve wrong and **its entire data is
dropped silently**. That is the 86–95% flapping documented for the plugins pass,
arriving in the `src` pass where it has never been seen.

So the line is load-bearing twice over, and only one of the two reasons was
recorded. **A comment that gives one true reason for a line is not the same as a
comment that gives all of them** — the missing one is what a later reader
removes the line for.

A design that survives both facts exists (a PHPUnit extension sweeping once per
worker on the first `Test\PreparationStarted`, gated on an env flag so
`composer mutate`'s per-test map is not poisoned into running the whole suite per
mutant). It is not built. What is recorded here is the measurement that says the
obvious version cannot work.

### What the numbers point at next, unbuilt

- **Process count, and it is free.** 170s at 4 processes, ~90s at 8 (which is
  already the script's default — a whole session was spent at 4 for no reason),
  62s at 12, 52s at 12 without `--functional`, and 52s at `auto` (16) WITH
  it — and **34s at 32**, because the suite waits rather than computes. The `--functional` split is deliberate and worth its 10s; four more
  cores buy that 10s back, so on an idle box the safe split is free. The
  throttle is what costs, unless the box is shared.
- ~~**The serial plugins pass costs 146s for 36s of work.**~~ Built: sixteen
  shards, 151s → 34s, same totals. See below.
- **E2e has no slow tests, it has a floor.** Median case 0.53s, the 15 slowest
  are 11% of the total; ~0.4s per test is `RunsIde`/`TempProject` setup, so
  ~144s of serial CPU sits in the boot path rather than in any test.
- **Unit is the mirror image**: 157 cases (2.9%) are 67% of its time, clustered
  in ~10 classes that really sleep — 70 `sleep`/`usleep` call sites.
- ~~**`opcache.enable_cli` is Off**, and contributing.md measures 4.7× on
  compilation.~~ Measured and closed — see below. It is 1.5×, it does not reach
  the suite, and under pcov it inflates the percentage rather than deflating it.

### The wall clock, swept (2026-09-08)

`.porta/freeze.log` had a dozen entries reading `loop stalled for 1078.06s
(recovered)`. None was a freeze: the drift detector measured the gap between
heartbeats on the WALL clock, which jumps while a suspended process is not
running at all. It is monotonic now, and the injected `ClockInterface` only
stamps the line.

**Every defect has a second site, so the class was swept.** In the running IDE
there is none: every budget goes through `EventLoop::delay`, and Revolt's timers
are monotonic — 16 call sites, no manual deadline among them. The two that did
exist were maintainer tools measuring their own runs (`flake:scan`'s wait loop
and the shard pool's per-shard seconds), and both moved to `hrtime()` for the
same reason: a laptop that sleeps mid-run would report the run as wedged, or as
having taken twenty minutes. `FileTreeWidget`'s poll grace stays on the wall
clock deliberately — it compares against file mtimes, which are wall clock too.

### Two levers measured, neither taken (2026-09-08)

**opcache is not a test-loop lever, and the 4.7× this repo quoted is stale.**
Re-measured with `opcache.enable_cli=1` + `opcache.file_cache` on PHP 8.5-zts,
five runs each: compiling all 707 files of `src/` in a fresh process is **55ms
without it and 37ms with** — 1.5× and 18ms, not 122ms → 26ms. `composer test`
at eight processes ran 92.0 / 89.4s without and 90.4 / 87.8 / 89.2s with, which
is the noise, and the arithmetic said so before the stopwatch did: a paratest
worker compiles `src/` ONCE and then runs thousands of tests, so eight workers
save eight times 18ms. It stays worth setting for `bin/porta` (`bin/porta-dev
container` 0.29 → 0.24s, five runs each, no overlap) and it is documented as an
environment note, not as a gate.

**The pcov caveat is real — and points the other way from how it was written.**
The worry recorded here was a silent coverage LOSS. Measured, one file's
coverage run reports **1,792 executable statements with opcache on where it
reports 1,813 with it off** — the same lines covered, `FileTreeWidget` counted
as 185 statements instead of 189. The denominator moves, so the percentage goes
UP. A loss would have been caught by the 100% gate on the next run; an
inflation is precisely what nothing notices.

**Narrowing `pcov.directory` from `.` + an exclude regex to `src` buys 5%, and
5% is under the bar set for touching the coverage run.** It landed nowhere.
The first pair of measurements said it was a 35% REGRESSION — 185s against
137s — and that was the box, not the setting: re-running the unchanged baseline
read 160s while the load average climbed past 25. Alternating the two settings
over a fixed workload (Unit, under pcov, 8 processes, A/B/A/B) gives A 64.8 /
58.0s against B 60.4 / 56.3s, with byte-identical maps — 20,873 statements,
19,258 covered, both variants. So: a real but small win, in exchange for
changing what pcov records, in the one part of this build that has twice
produced a number that moved for a reason nobody could see. Not worth it.
**Two runs taken minutes apart on a shared box are not a comparison** —
alternate the variants or do not report the difference.

With this, the coverage-run experiment is closed in both halves: the
bootstrap-sweep hypothesis was falsified earlier (the sweep contributes 0 files,
and `PORTA_CONTAINER_CACHE=off` is what makes the merge correct), and the
`pcov.directory` half is measured and declined.

## Go to Super Method, and what the servers actually advertise (2026-09-08, `b8cf05bb`)

The last feature on the open list, and it was open because "no LSP request
answers it" — still true: neither phpantom nor phpactor advertises a call or
type hierarchy. `Search/SuperMethodNavigator` answers it from the project's own
files instead, climbing `extends` / `implements` / `use <trait>` by SHORT name
through the shared `FileIndex`, because a class's short name is its file name in
any PSR-4 tree. That is `TestNavigator`'s trick, reused rather than reinvented.

Three things the tests forced, each of which had been wrong first:

- **Interface and abstract methods have no body**, so `BreadcrumbScanner` opens
  no scope for them — and those are precisely the declarations an override
  points at. The lookup is a token pass; the first version found traits and
  grandparents and silently missed every interface.
- **Vendored parents are the search index's scope, not this feature's.** The
  filter written here killed no mutation (the index already excludes vendor by
  default) and was the wrong answer once "Search vendor/ files" is on — the
  parent you want is often the framework's. Both directions of that setting
  have a test now.
- **The parents that matter are the enclosing type's.** Setting "collect from
  any type in the file" passed every test until a two-classes-in-one-file case
  was written; fixtures are exactly where that shape lives.

**And the probe that closed the sibling idea.** Go to Implementation looked like
the same story — until both servers were re-probed: `definitionProvider`,
`implementationProvider`, `typeDefinitionProvider`, `referencesProvider`,
`documentSymbolProvider` and `workspaceSymbolProvider` are all advertised by
BOTH. The existing `goto_implementation` action is the whole feature, and there
is nothing of ours to add. The capability table now records the navigation half
it had never covered.

*The rule this keeps proving:* probe before building. The one gap that was real
took a day; the one that looked identical took ten minutes to disprove.

## Round 8: render the surface, then read it (2026-09-08, `0e68d5d4`…`9f83f35b`)

Six defects, every one found by booting the IDE into a `VirtualTerminal` and
looking at the screen — not by reading code, and not by surveying features. The
method is cheap: a throwaway E2e that dumps `screenGrid()` at a few timestamps,
then read the dump like a screenshot. Every one of these had passed every gate
for months.

- **The indent guides doubled every block's depth.** A four-space PHP file drew
  a guide every TWO columns, and Tab inserted half an indent, because `auto`
  meant "the `.editorconfig`, else unix-style two spaces" whatever the file did.
  `IndentModel::detect()` reads the file now — tabs vs spaces from the first
  line that starts with either, size from the most common STEP between
  consecutive indents, ties to the smaller step. Two test fixtures moved rather
  than the heuristic: a ladder of 8/2/0 and a lone 8-space jump are genuinely
  ambiguous, and both tests were pinning a constant default rather than a read.
- **Two keystrokes did nothing at all.** `Ctrl+E` with one file open and
  `Ctrl+Shift+V` with an empty clipboard ring both returned in silence — on the
  first file of a session, which is exactly when someone is finding out whether
  the shortcut works. `RunController` already flashed "Nothing to run…"; these
  two were the outliers. `EditorActions` takes the Support-owned `FlashSink`,
  so `Editor` still holds no Ui service.
- **Find Action never showed a key.** The palette is where a shortcut is
  learned, and `ShortcutFormatter`'s docblock has named "the menu bar and the
  command palette" since before the palette printed one — a claim in prose that
  nothing enforced. Resolved through the same two maps the menu bar consults,
  asked by ID and never by label.
- **187 keybindings with no search.** The E2e that rebinds `save_all` walks up
  to 200 rows to reach it, which is the measure of the problem. `Ctrl+F` filters
  in place now — that key was DEAD on this pane, which the settings nav's own
  keymap comment warns about in as many words — and the query matches the label
  AND the key, because "what is Ctrl+Shift+N bound to" is the other half of what
  the page is opened for.
- **The empty state spelled out a chord.** `goto_file` is bound to
  `ctrl+shift+n` and `ctrl+p`; the one hint a new user reads named the second
  while the menu and the keymap page named the first, and a rebind left it
  pointing at nothing. It resolves the live binding now.
- **A Welcome step contradicted its own checkbox**: "Colours your code; off
  until enabled" beside `[x]`. A description that claims its own state goes
  stale the moment the step is done. Its three neighbours describe the feature
  and let the box say the rest.

**What the method costs and what it catches.** Five of the six are one-line
defects that no unit test would ever have failed on, because each test asserted
what the code computes rather than what a person sees. The sixth (the missing
filter) is not a defect at all in that sense — nothing was wrong, something was
missing, and only looking at the page shows that.

*Worth re-reading before the next round:* the tree still has no file-type
icons and the editor pads its empty area with vim-style `~`. Both were seen and
left: they are deliberate style, not defects, and changing either is the
owner's call rather than a fix.

## Comparing two files, and the selection it needed (2026-09-08, `92892561`…`d150ef8b`)

The last of the three long-open UI items. It needed two halves, and the first
one is the reusable part.

**The tree selects a set now.** `Ctrl+Click` adds, `Shift+Click` takes a range,
`Shift+↑/↓` does it from the keyboard, and a modified click only SELECTS —
opening a file on the way would replace the editor you are about to compare
against. Three decisions carry it:

- **Keyed by PATH, not index.** A tree rebuilds constantly — a directory
  expands, a file is created — and every index below the change moves. An
  index-keyed selection would silently point at different files after a
  refresh, which is the worst failure available to a command that acts on "the
  selected files". A path that leaves the tree leaves the selection with it.
- **`MultiSelection` is its own object**, not a widening of `ListSelection`:
  every other list in the IDE selects exactly one row, and putting a set on
  `ListSelection` would put it on every picker in the app.
- **The widget is told a `ClickIntent`**, never which modifier was held. A tree
  does not care that Ctrl exists; `MouseRouter` reads the SGR bits and decides
  whether this click opens, adds or ranges.

Right-clicking inside the selection keeps it; outside, it collapses to the row
under the pointer — a menu that silently acts on two files you are not pointing
at is worse than one that acts on one.

**The comparison is `git diff --no-index`, in a list panel, not the diff view.**
The engine was never in question: nothing in Porta computes a diff, it parses
one the binary produced, and `--no-index` is the two-path form the untracked
diff already uses. The display was. The rich `DiffViewWidget` is bound to the
repository's state machine — its poll reloads the pane, its keys stage lines
into the index, and its mode is a binary `Changes | History` branched on at **23
sites across 5 files**. A pair of arbitrary files is neither a change nor a
commit, so a third mode would have to answer every one of those branches. A
list panel is what `local-history` already uses to show exactly this kind of
comparison, and the title carries what the rows cannot.

*Re-opens on:* the diff view growing a state-free mode for its own reasons.

**Two limits are pinned rather than worked around.** A comparison needs a
repository, because this plugin's process runner answers every call with a
failed run when there is no working tree — deliberately, as the one place that
knows the availability rule. And the rows start at the first hunk: git's header
names both files, so does the panel's title, and five lines of header is
exactly enough to push the first real change out of a short panel.

### What the screen said that the assertions did not

Four traps, all caught by looking rather than reasoning:

- **The first E2e compared the wrong pair.** Dot files sort first, so the tree
  read `.porta.json`, `a.php`, `b.php` and the row arithmetic was off by one —
  the panel was comparing `.porta.json` with `a.php`, and every assertion in
  the test would have passed on the right pair too. Dumping the screen is what
  said so.
- **`$api->trans()` during `activate()` throws** — the runtime is wired later,
  and `PluginManager::runtime()` says so in as many words. The plugin's fault
  barrier reports it and moves on, so the symptom is EVERY git feature timing
  out at once with no error in the test output. A git suite where marks,
  annotations and the view all time out is one failed activation, not fifteen
  bugs.
- **The duplication gate caught a copied rig** the moment the second copy
  appeared: a twenty-argument `PluginRuntime` helper, now
  `plugins/git/tests/Support/GitPluginHarness`.
- **A coverage line that flickered.** `WorkingTreeStatus`'s coalesced-rerun
  branch was covered only by whichever E2e happened to overlap two refreshes,
  and it dropped out of the union when the shard set changed. It has a
  deterministic test now — two `refresh()` calls before the loop turns — and
  dropping the rerun fails it.

## Parallelism: the box was 80% idle (2026-09-08, `bbc05bf8`…`88e82869`)

The number nobody had looked at: at 16 processes the full suite burns **182s of
CPU over 51.7s of wall clock — 3.5 of 16 cores**. Under coverage at 8 it is 3.3.
The suite is **wait-bound**, not CPU-bound: the Revolt loop runs in real time,
~70 `sleep`/`usleep` sites sit in ~10 Unit classes, and E2e pays a ~0.4s boot
floor per test. Everything below follows from that one measurement.

### The process count does not stop at the core count

52.5s at 16, **39.5s at 24, 34s at 32**, alternated, all green; utilisation at
32 reaches 6.7 cores. The coverage pass goes 137s at the default 8 to 76s at 16
and **64–72s at 24**.

Both ceilings are measured too. **48 wedged** at `6588 / 6610 (99%)` with no
worker process left alive and three dangling pipes on the parent. **32 under
`composer coverage` failed two E2e tests** — a flash asserted as `Opened
https://…` that never arrived — while passing without coverage: pcov makes each
worker slower, so the same count buys more contention. 24 is green under both.

And a diagnostic lesson that cost twenty minutes: **killing a wedged paratest
parent leaves its workers alive, and the orphans wedge the NEXT run at exactly
the same point.** It looked like a reproducible capacity limit at 32; it was my
own debris. `pkill -9 -f vendor/bin/paratest`, check `ps`, then re-run — three
clean runs followed immediately.

### The plugins pass was paying for a merge it never does

`tests/bootstrap.php` turned the container cache off whenever pcov was enabled.
Both reasons that line exists — every worker compiling every `src` file so none
is missed, and that compile collapsing each worker's `PathReducer` base so the
merge resolves — are about the PARALLEL `src` run. The plugins pass is one
serial process filtered to `plugins/`. It was paying ~348 container recompiles
for a property it does not use: **151.0 / 151.4s off against 131.2 / 138.3s on**,
alternated, and 64s → 38s of CPU. The wall-clock half is smaller than the CPU
half because `ConfigCache::isFresh()` re-hashes ~480 tracked classes per warm
boot in a checkout — but the CPU half is the one that mattered next.

### Sixteen shards, because the dodge is per PROCESS

The serial pass existed because a serial run never merges. That property belongs
to a process, not to a pass — so N independent serial runs are N merge-free
reports, and the only merge left is the gate's own, over whole documents.
`bin/porta-dev coverage:plugins` derives the shards, runs them several at a
time, and writes one report each. **151s → 20.0 / 20.3s**, with
`100.00% (4284/4284)` and 127 plugin files every run. `composer verify` end to
end: **391s → ~100s**.

The second iteration is the interesting one, and the tool asked for it: the run
prints its slowest shard because that is the floor, and the first version's
floor was the whole `git` loose-file shard at 33s — the pass could not beat it
however many lanes it had. Splitting per FILE puts the floor on one file
(`git/tests/View/GitViewControllerTest.php`, ~16s).

**But the split alone is a regression, and that is the lesson.** Interleaved,
same box, same run:

| lanes | per directory (16 shards) | per file (68 shards) |
| --- | --- | --- |
| 8 | 33.2 / 33.5s | 35.9 / 36.2s |
| 12 | 46.7 / 35.4s | **23.6 / 22.2s** |

At the old half-the-cores default the finer split LOSES by 8%: 68 phpunit boots
(~1.5s each) need lanes to hide in. At 12 it wins by a third, while the coarse
split gets *worse* with more lanes — bounded by one 33s chunk, so the extra
processes are pure contention. **Granularity and concurrency are one decision,
not two**, and measuring either alone would have picked the wrong answer; the
first standalone numbers (25s per file, 34s per directory) flattered the split
because the two configs ran at different times on a differently-loaded box.
Hence the default moves to three quarters of the cores for this pass — a shard
is one wait-bound process, not a paratest fleet.

Names come from the path inside `tests/`, not the basename: two `FooTest.php` in
different subdirectories would otherwise share a report, and the second would
overwrite the first while its tests quietly stopped counting.

The split puts plugin E2e tests in a load profile they had never seen — dozens
of processes spawning real `git` at once — so it was graded the way
`testing.md` asks: **five consecutive passes, zero failures**, with the load
average climbing from 9 to 31 across the burst, plus ~15 more green runs during
the measurements above.

Three decisions inside it are load-bearing:

- **The shard list is derived, never written down.** (One shard per test file.) A list in a file is a
  second place to add a plugin, and forgetting it stops running those tests
  while the percentage stays green — the coverage gate catches a dropped shard
  only when nothing else covers the same lines. (A completeness ratchet was
  written and then deleted: with the list derived as loose-files-plus-every-
  subdirectory it could not fail, and a guard that cannot fire is the thing
  `claims-are-not-guards` is about.)
- **Every shard keeps `--coverage-filter plugins`, the whole tree.** A
  per-plugin filter reads tidier and breaks the gate: `plugins/tempest/plugin.php`
  has no tests of its own, is loaded by every booted IDE, and is not in
  `MAY_BE_ABSENT` — under per-plugin filters no report would carry it and
  `--require` would fail a file that is fully covered.
- **The pool is graded, not mocked.** The injected seam is a process FACTORY,
  not a run-to-completion closure, so the tests drive the real pool with `php -r`
  stand-ins — including an interleaving assertion that fails if the concurrency
  limit is ignored. Symfony enforces a process timeout from `wait()`, and a pool
  polls, so `checkTimeout()` has to be called by hand; the wedged branch is a
  test, not a hope.

### The gate grades a directory of reports, merged per LINE

`coverage:check` takes a directory as well as a file. **A variadic argument was
not available**: `InputDefinition` throws on ANY argument after an array one
(the guard at `InputDefinition.php:103` says "required" and does not check), so
`coverage:check <clover> <threshold>` could not survive it. The directory form
leaves the argument definition — and the composer-script contract a test pins —
untouched.

The merge is per line because booting an IDE loads every default-enabled plugin:
one shard's report holds files another shard also covered. Summing rows would
count a file's statements once per shard; keeping the best row would fail a file
that IS covered, by two runs between them. **A file only one report carries
keeps that report's own metrics**, which is why all 19 existing tests passed
unchanged.

And a guard, because **Clover is lossy**: `Report/Clover.php` writes a method's
declaration line as `type="method"` with a count that is the MAX over the
method's whole range, and omits the `stmt` entry for it. Where that line is
itself executable, neither the statement nor its true count survives. Measured
on the real report: 8 files disagree with their own metrics, **all 8 under
`src/`, none under `plugins/`**. So the union is exact today; the day a plugin
grows a single-line method, the gate says INVALID and names the file instead of
grading a shrunken denominator — the one direction a 100% gate cannot notice.

### Sharding the `src` pass too — measured, and closed by Clover itself

The plugins pass got 7.5× faster by becoming serial shards, and the same shape
applies to `coverage:src` on paper: 78 shards (one per `tests/Unit`
subdirectory, one per E2e file, plus Integration), run 12 at a time. Measured on
the same box, same lanes:

| | wall |
| --- | --- |
| 78 serial shards, container cache ON | **45s** |
| the same shards, cache OFF | 77s |
| `composer coverage` (paratest) at 12 | 99s |

Two thirds of that is the container cache, which only a merge-free run may keep
— so the prize was real and roughly 30s off `composer verify`.

**It cannot be graded.** `coverage:check --require=src` over those 78 reports
fails INVALID on **8 files**, and the guard is right: `Report/Clover.php` writes
a method's declaration line as a `type="method"` entry whose count is the MAX
over the method's whole range, and drops the `stmt` entry for it. Where that
line is itself executable — a default-value expression, evaluated on call — both
the statement and its true count are gone from the report. A union over those
files grades a denominator that is 1–7 statements short of the truth.

Every escape was tried and measured, not argued:

- **Treat every method entry as the dropped statement.** Inflates the total to
  115.73% (24341/21033): most method entries are ordinary declaration lines that
  were never statements at all.
- **Fall back to the best single report for a lossy file.** Under-counts rather
  than over-counts, so it is safe in the direction that matters — and **5 of the
  8 are not fully covered by ANY single shard** (`TerminalPanelWidget` 68/72,
  `SettingsMouseRouter` 29/32). It would fail the gate on files that are
  genuinely 100%, which is a false red nobody can fix from the code.

So the `src` pass stays one paratest run, and `PORTA_CONTAINER_CACHE=off` stays
with it. The 8 files also make the point the guard was built for: without it the
sharded pass would have run green on a denominator quietly short of the truth,
which on a 100% gate is invisible.

*Re-opens on:* a per-shard report format that carries exact per-line data.
`--coverage-php` does — its `Serializer` reduces paths and its `Merger` keeps
only the FIRST file's `basePath`, which is the bug that closed parallel
paratest, but a reader that applies **each file's own** basePath before merging
sidesteps it. That means depending on php-code-coverage's serialization
internals from our own command, which is why it is written down rather than
built.

### Measured while the data was there: plugin coverage ownership

`CLAUDE.md` and `plugin-system.md` both say plugin code must be covered by that
plugin's OWN tests, and the union gate has never checked it. One report per
shard finally makes it answerable. Of the 127 plugin files, 18 have no
executable statements; **of the 109 graded, every one is fully covered by its
own plugin's tests except `plugins/tempest/plugin.php`** (11 statements),
which has no tests of its own and is reached by every other plugin's Ide boots.

So the rule is true in practice and unenforceable as written: making it a gate
would fail on tempest immediately. Left as the union, recorded here.
*Re-opens on:* a second file joining tempest, which would mean the convention
is drifting rather than holding.

### Not done, deliberately

Two suite runners at once — in git worktrees, which would give each its own
`.phpunit.cache`, `coverage/`, `vendor/` and fixture home — was designed and not
tested. The recorded rule stands untested rather than re-measured, on the
owner's call. The utilisation table says it would probably work; that is not the
same as knowing, and this entry should not be read as saying it does.

## A duplication gate, and what it found (built 2026-09-08, `f2d1d519`)

`composer duplication` is the seventh gate: `systemsdk/phpcpd` over `src`,
`plugins`, `tests`, `bin` and `config`, at the tool's defaults, zero clones, in
CI's static job. The fork is the maintained successor to the abandoned
`sebastian/phpcpd`; its CI matrix is PHP 8.4 and 8.5, and its four dependencies
were already in the lock at exactly the required majors — `1 install, 0
updates, 0 removals`. jscpd would have put npm in CI for the first time.

**The headline is where the clones were not.** `src/` and `plugins/` — 911
files — report none, and every structure this repo expected to trip a detector
is invisible to it: the three 56-line theme tables, the two per-grammar `match`
tables, the four analyzer plugin files. They are data with differing literals,
so their token streams diverge. **The extraction audit's conclusion holds up
under a tool that never read it**, which is a stronger result than the audit
itself and cost an afternoon rather than six rounds.

**All fifteen were in tests, and three of them were rigs `tests/Support`
already provided.** `IdeFindInProjectTest` had hand-rolled five methods from
`FindModalReads`, docblocks included, in a file that did not use the trait. The
chunked and garbage LSP stubs hand-rolled the read loop that `lsp-stub.php`
provides — and its docblock already named exactly which part of each stub is
entitled to be hand-rolled (the split write, the corrupt header), which is not
the read loop. Eleven sites repeated a `getById`-and-assert pair that
`NavigatesSettings` and `ReadsSettingsForm` should have owned. **"Never
hand-roll a rig `tests/Support` already provides" turns out to need something
that looks.**

**Two tests pinned a table by restating it**, which is the weakest kind of pin:
it asserts that the table equals a transcription of itself, so a wrong edit made
twice passes. Both moved to `tests/Support/golden/`, and the keymap one got
STRONGER on the way — the copy spelled its values as `Key::UP`, the same
constants the source uses, so a change to what `Key::UP` is could never fail it.
The LSP capabilities copy carried a justification the source did not, so
deleting it blind would have deleted the reason.

**No allowlist, and that is the difference from the arch gate rather than an
omission.** A namespace edge can be correct and still needs recording; a clone
cannot be correct and unrecorded — either it is worth sharing, or the copy is
the honest shape and the code should say why, which rewords one side and ends
the match. Every clone found was worth fixing, so the question never arose in
practice.

**What it does not find is worth knowing before reaching for it.** It compares
token streams: it finds copied CODE, not parallel structure. And the threshold
was left at the defaults deliberately — a threshold tuned until the findings
disappear is not a gate, and the first run's fifteen were the work, not noise.

**Three method notes.** The `--exclude` match is path-segment aware rather than
a substring: measured, `--exclude vendor` drops the six files under `vendor/`
directories and keeps the six in `acme-vendored/` that are not, so the literal-
paths-only caution `mago.toml` records does not apply here. And four times the
tool flagged two files while the real duplication spanned four or five — the
clone is a symptom with a threshold, so after fixing one, grep for the shape.

**Proven red three ways, not just green.** Against the tree as it stood before
any of this (`git checkout <pre-dedup> -- tests/`, exit 1, largest clone 100
lines), against a clone planted in `tests/`, and against one planted in `src/`
— the last because every finding was in tests, and a gate whose only evidence
comes from one directory has not been shown to watch the others.

`composer mutate` reports no mutants over this work, which is correct rather
than a gap: the diff is tests, docs and `composer.json`, and Infection's
`source.directories` is `src` only. The two changes that DO guard `src`
behaviour — the keymap and capabilities goldens — were killed by hand instead,
with a dropped alternate binding and a flipped capability.

## Placeholder parity: six mismatches, no defects, one trap (built 2026-09-08, `85efe632`)

Checked every `%name%` in every source string against its target, across all
seven catalogs — 731 units each. **Six mismatches, and not one is a bug.** Five
locales translate the ZERO-count tool sentence with `%noun%` where the English
says `%nouns%` ("aucun problème" is singular), and Japanese uses the same noun
in both branches of the plural because it does not mark one.

**They work only because `flashCount()` hands BOTH nouns to BOTH sentences.**
That line reads like a shrug — why supply a placeholder the string does not
name? — and it is load-bearing: trimming the array to the placeholders the
ENGLISH source happens to use would put a literal `%noun%` on screen in five
locales. Nothing would catch it. The catalogs would still be complete, the
strings still translated, and `en` still perfect.

So the reason is a comment at the call site and the behaviour is a test — in
FRENCH, because `en` cannot tell the two worlds apart. Same lesson as the modal
titles: **when a fix or an invariant is about translation, the assertion has to
be in a locale that differs.**

**And the sweep's own result is worth recording as a method note.** Parity is
not a gate-able invariant: a target may legitimately use a placeholder the
source does not, so long as something supplies it. Every one of the six needed
the CALL SITE to resolve. What IS gate-able is the other direction — a target
using a placeholder that no call site supplies — and that needs the params, not
the catalogs, so it is not a catalog check at all.

## Swept for the rest of them; there are none (2026-09-08)

The check the entry below ends on — "for any field in a mapped row shape, ask
which line READS it" — run over the whole tree rather than left as advice.

Extracted every field name declared in an `array{…}` docblock shape (204 in
`src/`, 51 in `plugins/`) and looked for a reader anywhere in `src/`,
`plugins/` or `tests/`, counting all three spellings: `['field']`,
`'field' => $var` destructuring, and `->field` property access. **Eight
candidates in `src/`, and every one is a false positive** — three are the type
names in positional shapes (`array{int, int}`), and the other five
(`anchorLine`, `depth`, `isDir`, `dumpsAutoload`, `platform`) describe object
properties and are read 1–17 times each as `->field`. `plugins/` is clean
outright.

So the `label` removed above was the only one, and the codebase does not have a
systemic version of this problem. **Recorded because a negative result is worth
exactly as much as the search that produced it and costs nothing to reuse:**
the next person to wonder can read this instead of writing the script.

Two notes for whoever does rerun it. A naive version keyed on `'field' =>`
writes rather than on declared shapes drowns in `ActionCatalog::ACTIONS` —
286 "unread" keys, all of them action ids looked up dynamically. And property
access is the reader spelling that matters most here: four of the five real
candidates would have looked dead without it.

## The field that caused the wrong conclusion (built 2026-09-08, `9bc6acdc`)

Chased the previous entry's mistake to its cause instead of leaving it as a
lesson. `WorkspaceSymbolClient::map()` built a `label` — `"[class] Greeter"` —
and **nothing reads it**: its only consumer,
`Search\Everywhere\WorkspaceSymbolSource`, renders `name` and puts the path in
the hint. `26f34e38` says why it exists — the extraction that created the client
kept the row shape `NavigationController`'s own list displayed, and the Search
Everywhere sources replaced that list without the label following.

**It survived because it was covered and asserted**, which is exactly what dead
data that looks like display data does: 100% coverage, a unit test pinning its
formatting, and no effect on anything a user sees. That is why the round's
mutation habit mistook it for the live field and blamed a sound test.

**Deleting beats wiring it up, and the reason is not laziness.** The kind is not
missing from the modal by oversight: File Structure carries the kind in the
HINT, and Go to Symbol's hint is already the path, so a kind there would have to
prefix the name and push every symbol right — a taste call with no evidence
behind it. Recomputing it is two lines if it is ever wanted; a row shape that
promises a field nobody renders is a trap that has now sprung once.

**The generalisable check:** for any field in a mapped row shape, ask which line
of code READS it. Coverage answers "did this run", never "did this matter", and
a test asserting a field's format is not evidence that anything consumes it.
Infection: 9 mutants over the diff, 9 killed.

## The sweep, and a wrong conclusion inside it (built 2026-09-08, `5816e431`)

Swept every picker in the suite with a content assertion, by replacing its
rows' labels with `ZZZ0`, `ZZZ1`… and re-running. **Five surfaces, three
weak, two sound.**

| surface | asserted against | verdict |
| --- | --- | --- |
| clipboard history | whole screen | weak — the copies are the buffer |
| File Structure popup | whole screen | weak — the symbols are the buffer |
| recent-files switcher | whole screen | weak — `b.php` is in the TREE |
| git branch picker | whole screen | **sound** — dies on a relabel |
| Go to Symbol | whole screen | **sound** — see below |

**All three weak ones shared a second property**: each had a real assertion
beside the decorative one — the caret moved, the file opened — so each proved
the picker WORKED while proving nothing about what it SAID. That is the shape
that survives review, because the test reads as covered.

**And Go to Symbol cost me a wrong conclusion, which is the entry's point.** It
survived the first mutation, so it went on the weak list — but I had mutated
`WorkspaceSymbolClient`'s `label`, which that modal does not display: it renders
`name`. Mutating the field the row actually shows kills the test. It was sound
all along, and I nearly recorded it as a defect.

**A surviving mutant is only evidence when the mutation reaches what the
assertion reads.** The check is the same one this whole round keeps arriving
at, turned on the tool: before believing a survivor, render the surface and
confirm the thing you changed is the thing on screen. Doing that here would
have shown `Greeter` in the row and `src/Target.php:4` beside it, and the
question "which field is that?" answers itself.

Its assertion is sliced to the card anyway — inside that frame `Greeter` can
only come from the row, since the query line reads `> greet` and the hint is a
path — but for precision, not for a defect.

## The same hole in a test I did not write (built 2026-09-08, `91bbf157`)

Having found that my own picker test passed against the whole screen, the
obvious next question was who else does that — and the answer was the File
Structure popup. `testFileStructurePopupJumpsToSymbol` asserts the popup lists
`Demo` and `greet`; both are the symbols in the buffer it is drawn OVER.
**Measured, not reasoned: replacing every label with `ZZZ0`, `ZZZ1`… left it
green.** The popup could list garbage and the test would pass.

It was not vacuous — the following step asserts the caret moved to line 4 after
Down+Enter, which needs two real rows — so the test proved the popup WORKED
while proving nothing about what it SAID. That is the more common shape of this
defect and the harder one to see: a test with a real assertion beside a decorative
one reads as covered.

`ScreenReads::card()` is the fix, beside `screenGrid()` and `rowOf()` in the rig
that already owns screen reading. It reads a FORCED frame rather than the
accumulated output, which also removes the "which frame is this" question my
private version had answered by taking the last match — a subtlety a shared
helper should not make every caller rediscover.

**Two things this cost that are worth knowing.** `screenGrid()` DRAINS the
terminal, so a later assertion against `getOutput()` sees nothing — one
trailing assertion had to go, and it was redundant anyway once `card()` fails
loudly on a missing card. And a `tests/Support` trait cannot call PHPUnit
assertions unless it declares them: `abstract public static function fail()`,
the pattern `ReadsSettingsForm` already uses.

**The generalisable move is the one that found this at all.** After fixing a
defect in your own work, run the same mutation against every test that could
have the same shape. Both of these were found by cutting the thing the
assertion is about and watching the assertion stay green — never by reading it.

## The picker, looked at (built 2026-09-08, `ce808022`)

The debt the entry below names, paid. Every existing test of the clipboard
picker reads STATE — `pickerOpen`, then Down and Enter — and would pass on a
picker that draws nothing at all, because it is composited by the app-level
overlay host rather than by the editor widget. Neither the widget rig nor a
probe driving keys reaches it; the running app does.

It draws, and the row shape survives to the terminal:

```
╭ Paste from History ──── Esc ×╮
│  $beta = 2;⏎$gamma = 3;      │
│  $alpha = 1;                 │
╰──────────────────────────────╯
```

**And the first version of the test was wrong in the round's signature way.**
Asserting against the whole screen, it passed when the picker offered only ONE
entry — because every string in this picker is also in the buffer behind it.
That was measured rather than reasoned: the mutation that cuts the older entry
left the test green. It reads the CARD now, sliced out of the frame from its
title row to the foot, and three mutations fail it.

**Fourth instance this round of a gate satisfied by something that merely looks
like the thing** — after the graph keys passing on the image viewer's rows, the
`Alt+↑` recall matching a different doc section, and the overlay title being
"in the catalogs" as an action label. The tell is the same every time: the
assertion's SCOPE is wider than the thing it is about. A screen contains the
buffer, a file contains other sections, a catalog contains other consumers.
**Cut the scope down to the surface you mean before asserting on it.**

## Three modal titles were English in every locale (built 2026-09-08, `130bd4db`)

Went to RENDER the clipboard picker, could not get the overlay to composite in
either the widget rig or a booted probe, and read the code that builds it
instead. The defect was there: `pasteFromHistory()` handed the overlay a bare
`'Paste from History'`, and `openListOverlay()` does not translate — it passes
the title straight to the overlay host. Every other overlay in `src/`
translates at its call site.

**Nothing could catch it, and the reason is this round's recurring shape.** The
catalogs DO carry "Paste from History" — as the ACTION's palette label,
translated somewhere else entirely. `CatalogCoverageTest` gates `trans()`
literals, so a string present in the catalogs FOR A DIFFERENT CONSUMER satisfies
every gate while this call site bypasses translation completely. Third instance
this round of a gate satisfied by something that only looks like the thing.

**Enumerated rather than fixed the one**, which is the habit that keeps paying:
`grep openListOverlay | grep -v trans` found two more — `BufferNavigator`'s
'Recent Files', and the peek overlay's `'Peek: '.$file.':'.$line`, whose entire
title is assembled from a literal. `RefactorController`'s four and
`BufferService`'s were already right, so the rule was known and the exceptions
were drift.

**The test asserts the FRENCH title.** With the default `en` translator every
one of these renders identically whether it is translated or not — an
assertion on the English string passes in both worlds and is the reason this
survived. When a fix is "route it through the translator", the only honest
assertion is in another locale.

The picker still has not been seen on screen. It is composited by the app-level
overlay host, not by the editor widget, so neither the widget rig nor a probe
that drives keys reaches it; the E2e suite is where that assertion belongs and
this is a note that it is still owed.

## The snapshot list did not say whose (built 2026-09-08, `d14e3735`)

Rendered the panel the deleted-file path opens, and read it:

```
 2026-09-08 09:21:42  (latest)
```

One row, and **nothing anywhere saying which file**. Enter on that row offers to
write a file back to disk.

The rows carry no LOCATION deliberately — a snapshot index is not a line number,
and rendering one as though it were is the defect that shaped them — so the file
was only ever implied by the tab you happened to be looking at. **That
implication was already false before this session**: `show()` reads
`contextFile()`, which is the right-clicked path from the tree when there is
one, so right-clicking another file has always opened an anonymous list.
Reaching the same panel for a file that has been DELETED is what made it plain,
because then there is no tab, no buffer and no other cue at all.

**The first draft conditioned the name on "is this the active file", and that is
wrong for exactly the reason the old implication was wrong.** The answer changes
the moment the user switches tabs; the rows do not. A label that is only true
at the instant it was rendered is the same bug one layer up, so the name is on
every row, always.

**This is the third defect in a row found by printing a surface and reading it**
— after "1 versions" and the chopped key legend — and all three were invisible
to tests that assert on the same surface. The reason is consistent: an
assertion checks the thing you thought of, and what these three had in common
is that nobody had thought of the sentence at all. The cheap habit is to render
each new surface once and look, before writing the assertions.

## Rendering the new hint found an old defect (built 2026-09-08, `622aa179`)

Adding `/ find` made the graph pane's key hint seven columns longer, so the
question was what that costs at a narrow width — the check a new hint deserves
and one I had not made. Rendering it at 160, 100, 80, 70, 60 and 50 columns
answered more than was asked.

**At 80 the file name drops out of the status bar, and that is the design
working.** Only the left side yields, the tab strip still carries the name, and
a key nobody can otherwise discover is worth more than a duplicated filename.
Controlled by rendering with the OLD hint text: at 80 the name survives, so the
cost is real and it is the right trade.

**At 70 and below the legend itself was chopped mid-word** — `n/p select  /
find  C` — with no ellipsis. The mechanism: the right side is only truncated
when a flash is present, so in the steady state an over-wide legend reaches the
end of `render()` still too long and is cut there by the whole-row truncate,
which passes an EMPTY ellipsis on purpose, because by then the row is supposed
to be exactly the width already. The assumption held for every case anyone had
looked at and not for a viewer's legend, which is 78 columns on its own.

Cutting the right side before the row is composed fixes both halves at once:
the cut carries an ellipsis, and the clipped click spans fall out of the map
without a second guard, because that filter reads the width computed from the
truncated string. Priority is unchanged.

**Two things worth keeping.**

1. **A deliberate "never mark this" is a claim about the input.** `ellipsis: ''`
   was correct given "the row is already exactly the width", and that premise
   was never true for an over-wide right side. When a call site suppresses a
   safety marker, the question is which inputs make its reason false.
2. **The defect predates the change that found it, and the change is what made
   it findable.** Seven columns moved the cut into a width worth rendering at.
   Neither the hint nor the status bar was wrong on its own; asking what the
   new one COSTS is what crossed them.

Infection ran for once — the diff touches `src/` — and killed all five mutants.

## Two things that were fine, and one that was not (2026-09-08)

Three checks against the real setup, reported together because the negative
ones cost as much to establish as the positive one and are worth the same to
whoever asks next.

**`Alt+8` is dead for this user, and correctly explained.** Their `.porta.json`
lists `enabledPlugins` explicitly and does not include `todo`, so `ide_todo` is
registered by nobody. Booted a throwaway project with a copy of their config:
the command is absent, the config raises NO warning — and pressing `Alt+8`
flashes "Show TODOs comes from a bundled plugin that "enabledPlugins" does not
list". Round 8's step 4 landed, at the key press rather than at boot, which is
what stops it nagging every launch. **Nothing to fix.**

**And I nearly reported a truncation that does not exist.** The first screen
scrape showed only `Show TODOs comes from a bundled plugin` and I took the
sentence for clipped — the filter was matching rows containing "TODO", and the
wrapped continuation contains neither that word nor "bundled". Dumping the
region raw shows the card wrapping correctly across two lines, which is what
`ToastCard` says it does. **An observation is a claim too**; the discipline that
applies to a docblock applies to a grep.

**A reachability audit that did not pay, recorded so nobody rebuilds it.** The
idea was to list every command with no key, no menu entry and no palette row —
the shape that found `ide_notifications` in round 8. It reports `save`,
`save_all` and `goto_file` as keyless, because `CommandRegistry`'s `keyBound`
flag means "the GLOBAL dispatcher may run this" and the editor's verbs are
dispatched by the widget keymap instead. A correct version needs all four
binding channels, and `KeybindingsDocTest` already covers the half that matters.

**The one that was not fine: "1 versions".** Found by RENDERING the Recent
Changes panel and reading it. Every test around those rows asserted which file
is on top and whether the location is real; none of them read the sentence. The
catalogs have carried pipe plurals since the Symfony pass, and this string was
written without one. **A row that six assertions pass over can still be wrong in
the only way a user would notice** — and the fix for that is to print the thing
and look at it, not to add a seventh assertion of the same kind.

## "A hash does not run backwards" was only half true (built 2026-09-08, `b3daad2c`)

Checked what the new project-wide view would actually SHOW on this machine's
real store before assuming it worked. **Two directories, four snapshots, zero
sidecars** — so Recent Changes was empty on arrival and would stay empty until
each of those files was saved again. For a file nobody is editing any more that
never happens, and that is the file whose history is worth having. A feature
that is correct and empty is not shipped.

**The claim in my own docblock was the obstacle, and it was wrong.** "The
directory is named by a hash of the path and a hash does not run backwards" is
true, and beside the point: a hash cannot be INVERTED but it can be
RECOGNISED, and the set of paths worth trying is the files in this project.
Hashing 1,580 of them matched both directories (`.porta.json`, `src/Ide.php`).

**This is [[claims-are-not-guards]] in a docblock, one level up from the usual
form.** The sentence was not merely unverified — it was load-bearing, because
it is what stopped me writing the backfill in the first place. An impossibility
argument in a comment deserves the same suspicion as an invariant in a comment,
and the test is the same: state the domain. "A hash cannot be inverted" and
"a hash over an enumerable domain cannot be recognised" are different claims,
and only the first one is true.

**Three decisions in the scan, each with its cost measured:**

- **Skip list** (`.git`, `vendor`, `node_modules`, `.idea`, `.phpunit.cache`):
  7,000 files and ~200ms on this repo instead of tens of thousands.
- **A bound**, now a constructor knob for the same reason `$maxPerFile` is one:
  a project can be arbitrarily large and a one-off backfill must not become a
  hang on somebody's monorepo.
- **An EMPTY sidecar for whatever it cannot name**, which `recent()` already
  reads as "skip". That marker is the whole reason the scan happens once. Its
  absence would be a 200ms walk on EVERY invocation for the rest of the store's
  life, because a deleted file's directory is exactly the kind that never
  matches — and deleted files became a supported case one commit earlier. A
  save writes the real name over any marker, so nothing is written off forever.

**Six mutants, five killed; the sixth is the one worth writing down.** Emptying
the skip list changes no behaviour any test could see — it is a performance
constant. What made it testable was asking what it COSTS rather than what it
saves: a file edited inside `vendor/` is not backfilled. That is a real,
user-visible consequence of a performance decision, and pinning it turns an
equivalent mutant into a killed one without inventing a timing assertion.

## A deleted file can be brought back (built 2026-09-07, `412d64f7`)

The limitation the entry below names, closed one commit later — and the reason
it looked like a core round was wrong. It needed **no new core verb**, because
core already does this for a scratch file: mint it on disk, then open it.
Reading `ScratchFiles` was the whole design step.

**Two halves, and either alone is useless.** Enter on a Recent Changes row whose
file is gone shows that file's snapshots rather than flashing "cannot read" —
the per-file view asks the ACTIVE file what it is about, so a file with no
buffer could not be asked about at all. And restoring one recreates the file.

**The rule it appears to break is the reason it is allowed.**
`replaceDocument()` never touches disk because a file that EXISTS is the user's
to keep and save is their verb. Here there is nothing to keep, no buffer to
replace, and no disk state to lose — recreating is a creation, not an
overwrite. Stating which half of the rule is doing the work is what makes the
exception safe to write down.

**And underneath both, a defect nothing else would have found.**
`SnapshotStore::dirFor()` keyed the history directory on `realpath($path)` —
which returns **false the moment the file is deleted**, so the key CHANGED on
deletion. On any tree reached through a symlink (`/var` on macOS, a symlinked
checkout) that orphans a file's history at exactly the moment it becomes the
only copy of it. The directory is still there, so it is what gets canonicalised
now, with the file's own name put back; an existing file's key is bit-for-bit
what it was, so nothing already stored is orphaned by the fix.

**The shape worth keeping: a key built from something that only resolves while
the thing exists cannot survive the thing.** It read as canonicalisation and it
was a liveness check. The feature that exposed it is the one whose entire
purpose is outliving deletion — which is why it took this long to see, and why
"what does this expression return when the input is gone" is the question to ask
of any identity derived from the filesystem.

**Three changes, three tests that fail without them**, and the store's is the
one that needed a fixture built rather than found: an explicit `link -> real`
symlink inside the temp project, because on a machine whose temp directory is
already symlinked the bug is invisible in the other direction — and on one
whose temp directory is real, it would not reproduce at all.

## A restore could land in the wrong file (built 2026-09-07, `8f8ab755`)

Swept the new Recent Changes view against its obvious degenerate case — the
file a row names has been DELETED — and the defect that surfaced predates the
view entirely.

`HistoryTool::restore()` ends in `replaceDocument()`, which replaces whatever is
ACTIVE, so it first asks core to open the file the listed snapshots belong to.
It checked that the file was not already active. **It never checked that the
open worked.** `openFile()` declines a file that has been deleted or made
unreadable — it flashes and returns — and the snapshot then lands in whichever
buffer the user is looking at. Reproduced before fixing: list `a.php`'s history,
delete `a.php`, switch to `other.php`, press Enter, and `other.php` now holds
`a.php`'s old contents.

The fix is one line of shape: **check the postcondition, not the attempt.** The
guard that was there asked whether the open was NEEDED; the one that matters
asks whether it HAPPENED. `PluginApi::replaceDocument()`'s own docblock warns
that "the buffer the user is looking at may not be the one your subprocess
read" — the warning was heeded and then undone by trusting a call that can
decline.

**Where the sweep pointed and what it found are different places**, and that is
the entry's point: the question was about a view added an hour earlier, and the
answer was in a verb that had been shipping for weeks. A degenerate case is a
probe into everything downstream of it, not a test of the thing you just wrote.

**Left deliberately, with what it would take:** a file that is gone cannot be
restored at all, although its snapshots are on disk and Recent Changes still
lists them — which is precisely the case local history exists for. Recovering
one means opening a buffer for a path with no file behind it, and the plugin API
has no such verb; `replaceDocument()` never touches disk by design, because save
stays the user's verb. That is a core seam and its own round, not a rider on
this fix.

### And an operational note, because it cost twenty minutes

`composer test` stalled at 6592/6594 and had to be killed. All four suites then
passed alone, and a clean `composer test` passed in 2:42. The one thing that was
different: **a test file was edited WHILE the run was in flight** — paratest
loads test files in workers as it goes, so rewriting one under a running suite
is editing the thing being read. Causation is not proven, but the correlation is
exact and the discipline costs nothing: no edits while a suite runs. Distinct
from [[porta-paratest-background-hang]], which is the coverage MERGE stalling.

## The sweep found the shape the staging got wrong (built 2026-09-07, `8fcefa33`)

The staged resampling above shipped with one quality test at one shape. A sweep
over **54 shape/scale pairs**, each measured against a single-stage copy, found
where that was not enough: an elongated image (97×5011) drifts **7.7/255** from
a direct resample, against ~1 everywhere else. The docblock said the difference
was invisible. At that shape it was not, and one fixture could never have said
so.

**The defect was the stopping rule, not the staging.** Halving was bounded only
by "stay at least 2× the target", which for a narrow axis keeps halving long
after it has stopped paying — and each integer halving of a small dimension
truncates and quantises. The chain exists to bound the pixels READ, so the rule
now bounds them directly: stop once a level is at or under **600k pixels**,
which already resamples in ~2.5ms. Worst of the same 54 pairs: **1.7/255**.

It is a knee rather than a guess. 400k leaves the worst at 3.9, 600k reaches
1.7, and 1M and 1.6M pick the identical level — the curve is flat above it. The
graph viewer's own zoom is unchanged end to end (8ms a frame, against 48 before
the staging landed), because for its scales the chosen level is the same either
way. **The extra precision was free in the case that motivated the work.**

**Two rules now, guarding different failures**, and separating them is what made
both testable. The budget stops a chain going too deep; the oversample floor
stops it sampling a level that would have to be MAGNIFIED back up. A single
combined dial could not be pinned by either test — raising the floor to 4 also
fixed the elongated shape, which is how a dial that is really two things hides.

**Five mutants, five kills, and three of them needed the test moved:**

1. Budget removed — caught only after the quality fixture moved to the tall
   shape. On the OLD fixture the budget changed nothing.
2. Floor lowered to 1 — needed a fixture where the budget does not bind first:
   900×900 at a third, where the only halving the budget would allow is 450, a
   mere 1.5× the target.
3. **The climb's `break` survived twice before it died.** With the budget the
   first retarget made both branches pick the same level; the discriminating
   target is one no level oversamples at all, where failing to climb is an
   outright blow-up — 0.0/255 against 6.3.
4. Levels not dropped with the image.
5. `>` flipped to `>=` — an equivalent mutant everywhere except exactly at the
   boundary, so the fixture is exactly 600,000 pixels (1000×600). Contriving
   the boundary is cheap and it makes the constant's DIRECTION mean something.

**The transferable part is (3) and the shape it repeats:** a test whose inputs
were chosen before a rule changed usually still passes, and usually stops
discriminating. Re-running the controls after every retune is what caught it —
the second time this session that a control was the thing that was wrong.

## Find a node in the graph (built 2026-09-07, `68e95823`)

Picked from evidence rather than a backlog: the user's one logged session that
morning was spent in the graph viewer, so the question was what that pane
lacks. Selection existed (`n`/`p`, click) and there was **no way to reach a
node you can NAME** — which is the only way anyone looks for one in a graph of
two hundred namespaces drawn as identical boxes.

`/` and `Graph: Find Node` open the filterable list over every node, in the
`n`/`p` order; picking selects and pans. The row hint is the node's **in/out
degree**, which is the argument for a list existing at all: the hub you are
hunting is drawn exactly like every other box, and in a list it is the row with
fifteen edges. It reuses the type-to-filter overlay built earlier this session,
so the feature is a command, a key and two accessors.

**Two seams, both existing patterns rather than new ones.**

- `focusNode()` is EXTRACTED from the `n`/`p` cycling, not written beside it.
  "Which node is selected" and "where the viewport is" are one fact, and a
  picker that selected a node three screens off without moving would be a
  search that finds nothing you can see. This is the round's recurring defect
  shape — two sources of truth about one fact — refused before it existed.
- The widget knows only that the key was pressed. Opening an overlay needs the
  api, which only `plugin.php` has, so the pane takes an **optional trailing
  closure**, the way a widget takes a dispatcher. The widget's own suite hands
  it a counter double; the app-level test is what proves the wire.

**A coverage gap that was really a dead guard.** `nodeChoices()` skipped a
`selectionOrder` id with no node behind it, and that line can never run — the
order is BUILT from those nodes, and `cycleSelection()` has always indexed them
directly. Deleted rather than tested: an unreachable guard that a test invents
a way to reach is worse than none, because it documents a case that does not
exist. The neighbouring guard in `focusNode()` was KEPT, and the difference is
that one takes an id from OUTSIDE (the picker's row value) where a stale tab
really can hand it a number that has gone.

**And one vacuous await, caught by reading it rather than by it failing.**
After `clearOutput()`, "A is no longer on screen" is satisfied by the blank
screen before anything repaints. The condition has to carry a positive half —
"C is there AND A is not" — which is [[settle-loops-need-content]] in its
smallest form: a negative assertion needs something to have happened first.

## The key gate could not see the plugins (built 2026-09-07, `6f7ae435`)

`KeybindingsDocTest` walked `src/` for `getDefaultKeybindings()`, discovered
rather than listed — and a BUNDLED PLUGIN binds through the same trait, so six
widgets were invisible to it: the git view's four and both viewers. Its own
argument (a hard-coded list is a checklist the next surface walks past) applied
to itself one directory out, so the roots are discovered as well, read from each
plugin's manifest — the same source the autoloader uses, so a plugin cannot be
missed by being spelled differently.

Widened first, and it named **twenty keys**. What they turned out to be is the
interesting part, because only one was a plain omission:

1. **Eight were the doc being read wrong.** `Shift+arrows` is one row for four
   chords, and better writing than four rows; the gate now expands it the way
   it already expands `Alt+Shift+↑/↓`. Recorded before and worth repeating: a
   text gate that forbids a shorthand drives the doc towards a worse table.
2. **One was a real hole.** The graph viewer's `v` — bitmap versus cell
   rendering — was documented in neither the key reference nor the editor
   guide's graph section, which describes every other key that pane binds.
3. **The rest were documented in the wrong FILE.** The git view's keys are
   fully and correctly written up in `git.md`, and the graph viewer's in
   `editor.md`; `keybindings.md`, the index that claims to list every key, had
   no section for either surface. Both have one now, linked to the guide.

**And the cross-surface version of the Search-section problem is still open,
deliberately.** The graph viewer's `=`, `-`, `0`, `f` and `1` pass the
whole-file match because the IMAGE viewer's section documents those chords —
a reader of that section learns nothing about the graph pane. Scoping per
surface needs a widget→section mapping that would itself need maintaining, and
that trade was already argued in this class. The rows were written out per
surface because that is correct, not because the gate asked.

**A control passed for the wrong reason for the third time this session**, in a
new way: to check the widened gate catches a NEW plugin key I added
`ctrl+alt+shift+j` to a viewer's map and the gate stayed green — because that
chord is already a documented Porta binding. The control needs a chord the doc
CANNOT contain, and `ctrl+alt+shift+f12` failed it correctly. When inventing a
value to prove a gate fires, check that the value is actually absent first.

## The zoom was the freeze, and perf.log said so (built 2026-09-07, `45a7de8e`)

Round 8's plan recorded the runtime logs as CLEAN — 43 slow paints, all on one
day twelve days earlier. **That reading was of a log that has since been
replaced.** `perf.log` now holds one 2026-09-07 session and nothing else, and it
is not clean: fifteen slow paints inside a 24-second window, two of them past
the watchdog's threshold and recorded in `freeze.log` as a stalled loop, and the
session relaunched twice in the minute after. Check a log's DATES before quoting
a previous round's summary of it — the file is a ring, not an archive.

**What the numbers said before any guessing:**

| field | reading | what it rules out |
| --- | --- | --- |
| `writeMs` p95 ≈ 1ms vs `renderMs` p95 = 408ms | the widget tree, not the terminal | the `NonBlockingWriter`, the PTY |
| `edMs` p50 = p95 = **0.0** every second | the code editor did not render at all | four rounds of editor perf work |
| `hlFull` = `hlSplice` = 0 | nothing was parsed | the highlighter |
| frames of 100–230 KB on an 11.9k-cell screen | ~17 bytes per cell — every cell its own colour | anything text-shaped |

That is a per-cell-coloured full-screen surface with no editor in it, which on
this user's enabled plugins is the graph viewer. Reproduced headlessly against
the real repo at their terminal size: a 130 KB frame, 0.0ms static, 7ms per pan,
**48ms per zoom step**.

**The defect is a pricing mistake.** `imagecopyresampled` costs what its SOURCE
costs to read, not what its target costs to write — shrinking the 2173×3147
bitmap to 77×112 and to 616×896 both take ~31ms, because both read 6.8M pixels.
`ScaledSource`'s memo is keyed on the scale, and a zoom is a NEW scale on every
keystroke, so it misses every frame and pays the full read every frame. Held
down, that is the stalled loop.

The fix is staged resampling: take the copy from the coarsest cached HALVING of
the image that still holds ≥2× the target in both axes, and build the halvings
once per image instead of once per scale. **31ms → 0.9ms per resample; 48ms →
8-9ms per zoom frame**, which is what a pan costs. The remaining spikes are one
chain build per new bitmap `dot` delivers, not per frame. Memory grows by a
third of one image (¼ + ¹⁄₁₆ + …) and is dropped with it, so the viewer's "no
cache per tab" rule is untouched. Both viewers share the class.

**Three guards, and two of them only became guards after a control run:**

1. The oversample floor. A first quality test compared staged against direct on
   a RULED GRID and read 1.000/255 at floors of 1, 2 and 3 alike — a fixture
   that cannot tell the values apart, and an `assertLessThan(8.0)` where the
   answer was 1.0. Line art at a size that halves untidily separates them:
   2.3/255 at a floor of 2, 3.8 at a floor of 1.
2. **The climb back up survived its first test entirely.** Zooming back IN must
   step back to a finer level; deleting the `break` that stops it makes the copy
   a 3× MAGNIFICATION of a coarse one. Every size assertion passes anyway — the
   dimensions are computed off the ORIGINAL image, so they are the same number
   whichever level the pixels came from. Only the picture knows: 3.5/255
   climbing, 21.6 magnified.
3. Dropping the levels with the image, killed by asserting the level widths.

**The transferable lesson is (2), and it is the round's:** an assertion on a
value that is *derived from the input rather than from the work* is not testing
the work. Both readings agreed on 128 because both were told to produce 128.
Mutation is what surfaced it, by hand — `source.directories` is `src` only, so
Infection sees nothing in `plugins/`.

### The sweep that says it was the only one (2026-09-07)

Having a headless render bench made it cheap to ask whether the graph viewer was
one of a family, so every other surface was measured the same way: opened, then
twelve renders with a navigation key between them.

| surface | p50 with input | frame |
| --- | --- | --- |
| Git: Changes, Git: File History | 0.7 / 1.6ms | 14–17 KB |
| Problems, Structure, Local History, Recent Changes | 0.2–1.9ms | 15–18 KB |
| Notification Log, LSP log, Bookmarks, Recent Locations | 0.2–0.4ms | 15–16 KB |
| Find in Project, Go to Symbol | 0.0ms | 20–22 KB |
| Settings, Documentation, Welcome, Maximize Panel | 0.0–2.0ms | 13–24 KB |
| **the graph viewer, zooming** | **48ms** (now 8) | **130 KB** |

**Sixteen surfaces, one outlier.** Nothing else is within an order of magnitude
on either axis, and the round's whole diagnosis rests on that: the log recorded
frames of 100–230 KB, and the identification was made from the byte count before
anything was opened.

That reasoning was then controlled, because a big enough terminal could in
principle make a text surface that large. It cannot: the Problems panel goes
15 KB at 213×56, 34 KB at 320×90 and **60 KB at 425×120** — 51,000 cells, far
past any real terminal — and stays under 1ms throughout. Only a rasterised
surface reaches six figures, because only a rasterised surface gives every cell
its own 24-bit foreground AND background. **A frame's SIZE identifies the
surface that drew it**, which makes `pendingBytes` in a `slow-paint` line more
diagnostic than it looks.

## Local history learned which file it was holding (built 2026-09-07, `3d860665`)

Local History could answer "what happened to THIS file" and nothing else. The
reason was structural rather than an omission: a file's snapshots live in
`.porta/history/<hash-of-path>/`, and **hashes do not run backwards**, so the
store physically could not name the file a directory belonged to. Every
project-wide question — what did I change today, which files did I touch before
lunch — was unanswerable, and no amount of walking the store would have helped.

A one-line `path` sidecar beside the snapshots is the whole fix, and `recent()`
then walks the store and returns files newest-first. Two decisions in it are
worth keeping:

- **Written on every snapshot, not only on directory creation.** A directory
  made before this existed gains its name the next time that file is saved, so
  the format heals itself rather than needing a migration step nobody would run.
  A directory that never heals is skipped, not guessed at.
- **Written AFTER the snapshot, inside the same `try`.** This started out first,
  sharing the `mkdir` try — and the coverage gate caught it: with the sidecar
  ahead of the snapshot, the existing throwing-`Filesystem` test returned `-1`
  from the first block and **the snapshot write's own catch became
  unreachable**, at 99.95%. The gate was right about more than coverage. Naming
  a directory that holds no snapshot is the wrong order, and a store that cannot
  write the snapshot must not report success for having written its label.

`list()` matches `/^(\d+)\.snap$/`, so the sidecar is not mistakable for a
snapshot and pruning cannot reach it.

**Reached two ways, both plugin-local** — the `local-history-recent` list tool
and a `Recent Changes` command. No core change was needed for any of it, which
is the plugin API doing its job. Rows carry a real path and line 0 so Enter
opens the file; a list tool registered AFTER `$manager->commit($api)` renders
nothing, which cost a test.

**Mutation says nothing here** (`source.directories` is `src` only), so the
sidecar write was killed by hand instead: delete the line, two tests fail.

## The command layer holds too, and the sweep is now exhausted (2026-09-07)

The last layer the technique had not reached: every command in
`ActionCatalog::ACTIONS` that the registry answers to, run against three
degenerate IDE states — **no file open**, a file open, and **a file DELETED from
disk under its own buffer**, which is where round 7 found one of its defects.

**171 command/state pairs, nothing thrown.** Six spawning or terminating
commands were skipped by name (`ide_quit`, the terminals, run, debug) and that
exclusion is the only gap.

**The sweep is now exhausted**, and saying so is the point of this entry. Three
consecutive passes came back clean — the overlay filter's rendering, the
ninety-one pre-existing editor verbs, and now the command layer — after six
passes that each found something. The technique finds defects in code written in
the last few hours and nothing in code that has been exercised for months, which
is what one would hope and is now measured rather than assumed.

**What it does NOT cover, for whoever picks it up next:** it proves nothing
throws and no invariant breaks. Both of the semantic defects it found in this
session's verbs were caught by READING twenty-five lines of output, and that does
not scale past a few dozen cases. For a larger surface the technique needs an
oracle — a round trip, an invariant, a second implementation to disagree with —
and the round-trip pass above is the worked example of finding one.

## The sweep turned on the OLD verbs, and they hold (2026-09-07)

Having found six defects sweeping this session's own work, the same technique
went to the ninety-one verbs `EditorKeymap` already bound. **Nothing broke**, and
that is worth writing down so nobody repeats it:

- **546 verb × degenerate-document pairs threw nothing** (empty buffer, blank
  line, whitespace-only line, no trailing newline, tabs, one character).
- **637 pairs broke no invariant**: never zero lines, the caret always inside the
  document, the text always valid UTF-8. So the class of defect found in the new
  verbs is about SEMANTICS, not robustness — the verb layer does not corrupt a
  document, whatever it is asked.

Robustness is not correctness, so two verbs with a real oracle were round-tripped:

- **`toggle_case` is deliberately not an involution.** `aBc` → `ABC` → `abc`:
  mixed case uppercases first. That is PhpStorm's behaviour and it is right;
  recorded because it LOOKS like a bug in a round-trip table.
- **The comment verbs normalise**, and every difference found is normalisation:
  `//x` re-commented is `// x` (the space is added deliberately), and `//` alone
  uncomments to nothing, because that is what uncommenting a bare `//` means.

**One real inconsistency, measured and accepted.** The BLOCK form of `Ctrl+/`
strips trailing whitespace (`x  ` → `x`) where the LINE form keeps it. It is the
same two-sources-of-truth shape as the round's other findings — one verb, two
implementations, disagreeing on one input class. It is left alone because
preserving trailing whitespace *inside* a comment is not something anyone wants,
because `blockUnwrapped()` would have to stop trimming to bring it back, and
because no content is lost either way. *Re-opens if* a language whose comments
are meaningful whitespace ever appears, or if the asymmetry surfaces in a diff
somebody minds.

## Sweeping the rest, and two clean results worth recording (2026-09-07)

The sweep pointed at everything else this session touched. Two areas came back
CLEAN, and saying so is the point — a negative result stops the next round
re-checking them:

- **The overlay filter renders correctly for anything typed into it.** Empty
  labels, whitespace-only labels, a single item, a 300-character label, and
  filters of `""`, a space, `..`, `%s`, 200 characters, CJK and emoji: no throw,
  and every row's visible width equals the box width in all of them. That is the
  invariant behind [[tui-garble-overwide-line]], and the filter row does not
  break it.
- **`RefactoringUndo` survives every degenerate state**: undo with nothing
  recorded, `decline()` before any `begin()`, an empty group, a double undo, a
  file that vanished (blocked, correctly).

One thing did come out of it. `hasPending()` counted a buffer that had since been
CLOSED, and the undo skips those by design — so refactoring one file, closing its
tab and pressing the key answered "Refactoring undone" over a workspace nothing
had touched. **What is pending is what could actually be restored**, so the
question is asked of the live buffers now, which is the same set the undo walks.

That is the general shape of the last three fixes and worth naming: two places
answered questions about the same thing and disagreed — the statement vs the
indent, the recorded set vs the restorable set, the keyword test vs the
already-done test. **A verb with two sources of truth about one fact will
eventually be asked an input that separates them.**

## Every new verb against a degenerate document (2026-09-07)

Fifth pass, and the cheapest sweep of the lot: run all five verbs this session
added over an empty buffer, a blank line, a whitespace-only line, a file with no
trailing newline, and a tab-indented blank. Twenty-five results, printed and
read.

Nothing threw — which was the first thing worth knowing — and one row was
wrong. `Ctrl+Shift+Enter` on a whitespace-only line ATE THE INDENT, dropping the
caret to column 0, where `Enter` and `Shift+Enter` both stay put.

The cause is a single expression doing two jobs: `rtrim()` finds the statement,
and the indent was taken from its result. On every ordinary line those agree; on
a whitespace-only line the trimmed text is empty and has no indent to find. The
indent comes from the ORIGINAL line now and the statement from the trimmed one,
which is the same split the block-comment verb needed for the same input — a
blank line inside an indented block is the shape that separates them.

**The sweep is worth keeping as a habit.** Five verbs × five degenerate
documents is one `php -r` and thirty seconds, it needs no test written first,
and the output is short enough to read every line. Coverage and mutation had
passed over this; it is not the kind of thing they look for.

## The clipboard history was a memory regression (2026-09-07)

Fourth pass of the combination question, this time about a RESOURCE rather than
a behaviour: what changed about the process when the clipboard stopped being one
string? Twenty of them is twenty times whatever you last copied. `Ctrl+A`,
`Ctrl+C` on a large file, twenty times, retained **38 MB** in a session that
runs all day — where before it retained one copy.

Bounded by BYTES now as well as by count, with the newest exempt: it is what
`Ctrl+V` pastes, and that worked for any size before there was a history at
all. A budget that could drop it would be a regression dressed as a bound.

**Two things about the verification are worth more than the fix.**

The first assertion was `assertLessThan(10, count(...))` for a fixture that
should leave exactly one — and it passes at NINE, which is precisely what the
surviving mutant produced. **A bound is not an assertion when the correct answer
is a single number.**

And two mutants then survived a fixture I had reasoned about rather than run: I
analysed the budget as applied once at the end, when `push()` applies it on
every call. With equal-sized entries each push costs exactly one drop, so an
arithmetic error inside the loop reaches the same answer. Killing them needed a
push that costs SEVERAL drops — ten small copies, then a big one — which came
from running the thing and looking, not from thinking harder.

**Also recorded: three E2e failures in this round were machine load, and half of
it was mine.** I had a `paratest` run and a mutation run going at once, on a box
already carrying OrbStack at 113% CPU. Load average 38. Run alone at
`PORTA_TEST_PROCESSES=4 nice -n 10`, the suite passed 359/359. The rule the
memory already carried — check `uptime` before believing an E2e failure — now
has a companion: do not run two `paratest` instances at once, they share the
cache directory and thrash.

## Press it twice: complete-statement doubled the brace (2026-09-07)

Third pass of the combination method, and this one needed no combination at
all — only the question "what does this verb do to a line it has already
finished". `Ctrl+Shift+Enter` on `if ($x) {` produced `if ($x) { {`, and on
Python's `if x:` produced `if x::`.

The keyword test answered "this line OPENS a block" and nothing asked "and has
it already". The terminator branch had that rule from the first day —
{@see \Porta\Editor\LineOperations::wantsTerminator()} refuses a line already
ending in one — and the block branch was written without its mirror. **When a
verb has two branches that do the same KIND of thing, the guards belong in both
or the asymmetry is the bug.**

The one-line form counts as finished too: `if ($x) { run(); }` ends in the
CLOSER, and a brace after it is nonsense.

Two mutation survivors then named the two ways a brace can be there without
looking like it — trailing whitespace after it, and one written tight against
the condition (`if($x){`), which the untrimmed comparison missed because the
table stores the opener as ` {`, space included.

**Worth noting how cheap the finding was.** Every one of this session's
combination defects came from running the verb over a handful of hand-written
inputs and READING the output, before any test existed. Coverage, mutation and
three code readings had passed over all of them.

## Two more from the same list, both in the undo I had just written (2026-09-07)

The combination method again, this time pointed at the newest thing in the tree
rather than the oldest — and it found two defects in code that was a commit old,
100% covered and mutation-clean.

**An edit that changed nothing threw away the undo before it.** The group was
cleared eagerly at the start of every application, so a second refactoring whose
every file was refused for overlapping edits left the user with nothing to undo,
for a keystroke that had done nothing at all. I had already seen this shape once
and guarded it by moving the eager clear AFTER the resource-refusal check — which
merely moved the hole to the next kind of refusal, one that happens deeper and
per file. **A placement rule is not a fix.** The group opens on the first thing
RECORDED now, so "nothing recorded" and "nothing forgotten" are one condition by
construction, whatever the reason and however deep it is decided.

**And the verb overclaimed.** Its docblock said it takes back the last
refactoring "whole"; resource operations record nothing, so undoing an "extract
to new class" would have restored the words and left the new file standing. Such
an edit is now `decline()`d — remembered not at all, so `Ctrl+Alt+Z` says there
is nothing to undo rather than offering half. The previous refactoring is not
the answer either: undo means the thing you just did.

The `decline()` state has to last for the REST of the group, which the first
attempt got wrong: an edit that creates a file edits it immediately afterwards,
and clearing-then-allowing recorded exactly the half that makes no sense alone.

**What this says about the method.** Both defects were invisible to every gate,
and the second was invisible to review because the docblock and the code agreed
— the docblock was simply wrong about what the code covered. Listing what a
round touched and asking what each thing now MEETS found in one sitting what
coverage, mutation and three readings had not.

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


## Undated evidence, and the one row nothing measured (2026-09-09)

Two findings, both reached by reading rather than surveying, and the first one
cost most of the round.

### `error.log` was the only channel with no timestamps

`.porta/error.log` held twenty-one byte-identical `RenderException` traces and
nothing that placed any of them in time. The file's mtime said 2026-09-08 —
three days AFTER the fix that closed them — so the reasonable reading was a
live regression, and that is the reading I published before checking it. It was
wrong. The entries are the same twenty-one round 7 already fixed; what finally
dated them was `NarrowPaneRenderTest`'s docblock, which happens to quote their
COUNT, and the table entry above. An mtime dates only the newest write, and a
repeating fault writes identical bytes, so neither the content nor the
filesystem could tell a live crash from a closed one.

Roughly an hour went into reproducing a crash that no longer exists: a widget
sweep at every width 1–60, a full IDE boot at 1×1, a progressive shrink, and a
replay of the crashed session's own `layout.json` and open file. All green,
which was the answer — it just took a long time to trust.

`freeze.log` and `perf.log` had stamped every line all along, from five
call sites repeating `$this->clock->now()->format('Y-m-d H:i:s')`. The stamp
now lives in `DiagnosticLog::append()`, so **every** channel gets one —
`error.log` and `dap.log` included — and the five call sites lost their copy.
First line only: a trace is one entry, and stamping its frames would break the
shape a reader counts entries by.

The prose note in the table above ("the 21 entries are all pre-fix") is exactly
the kind of standing-in-for-data that goes stale — the data says it now.

### The modal never measured its own toolbar row

`FindInFilesModal::boxWidth()` was a max over the title, the query and the
visible result rows: everything that VARIES. The options row does not vary —
five fixed tags, a translated caption, the scope label, and ` (Alt+S)`. Being
absent from that max it was the one row guaranteed to overflow, by one column
at the default width, and the row-wide truncate spends an overflow on the tail.
So the modal rendered `Scope: Whole Project (Al…`, eating the only thing on
screen that says how to change the scope — for a user whose own
`search-prefs.json` records `scope: module`, i.e. someone who changes it.

Fixed in both directions, because they answer different questions: `boxWidth()`
counts the row now (so it fits, in French too, where the caption is longer),
and the label — not the key — is what elides when a terminal is genuinely too
narrow to widen into. The label is a value the results already show; the key is
an affordance with nowhere else to appear. Same ordering the gutter's bookmark
glyph got, and for the same reason.

### For the next round

A screen read through `modalText()` is NOT the screen. It renders the editor
widget alone at a fixed `RenderContext(160, 40)`, so modal rows composited
outside the editor pane silently vanish from it — which had me convinced for a
while that the modal's title bar and its result count clipped away at 24 rows.
`screenGrid()` showed the title present and intact. Read the join, not the
piece.

## A sharing rule applied where nothing was shared (2026-09-09)

`SearchCategory::cap()` returns 5 for every category, and its reason is written
down: "so no one source floods the list". Six groups on one list, none of which
may crowd the others off it. Correct — for Search Everywhere.

`createGoToFile()` and `createScoped()` build the same controller with ONE
source, and paid the same cap. Rendered, that is Ctrl+P over forty matching
files showing five of them and a `35 more results` row inside a box with room
for twelve: seven empty rows and a keystroke standing between the user and
results already fetched.

The discriminator is passed, not inferred. Deriving it from the source count
read well and was wrong twice over: it makes a construction detail into
policy, and it changed the cap for the single-source rig five controller tests
use to exercise the grow machinery — tests whose subject is the shared rule,
which would then have needed a second source added purely to keep the number
they assert. `createGoToFile()` and `createScoped()` know what they are; they
say so with `categoryCap: SOLO_CAP`.

`SOLO_CAP` is `MORE_STEP`, the step the grow row already reveals, so the window
fills and the arrows scroll it. Past that the same row appears: this widens the
first page, it does not unbound the list — pinned by its own test, because a
cap that only ever grows is the failure mode here.

**How it was found, and the shape to reuse.** Not by reading `PickerLayout`,
which is correct: its window is `min(count, rows - 6, 12)` and it fills
whatever it is given. The disagreement was between three layers that each
looked right alone — the box seats twelve, the window fills what it is handed,
the source hands up five — and nothing anywhere errors. It is visible only in
the composed frame, which is why the guard for it is an E2e that counts rendered
rows (`IdePickerTest`) rather than a unit test of any one layer.

## The footer names its key, and a test that proved nothing (2026-09-09)

`Alt+Enter` — "open in Find window" — appeared **nowhere** in the find modal.
Both its footer siblings say their key: `⏎ open` names Enter, and `Scope`
carries `(Alt+S)` on the options row above. It was the one action in that modal
you could only learn from the source, so the footer names it now.

The footer is counted by `boxWidth()` for the same reason the options row is,
and establishing that it MATTERS took three tries — recorded because the first
two would have shipped.

**Attempt one** asserted the French screen still contained `(Alt+Enter)` and
`Portée`. Green, and worthless: with the default scope the options row is the
wider of the two fixed rows in every locale we ship (en 51/41, de 56/45, es
55/51, ja 53/45, he 47/40), so it alone sets the floor and dropping
`footerRowWidth()` changed nothing. Both mutations survived.

**Attempt two** narrowed the options row by selecting a SHORT scope label —
`src`, which is what a real project has; this repo's own `search-scopes.json`
holds `src`, `docs`, `config`. That inverts it: fr with `src` is options 43,
footer 53, so the footer sets the width. The mutations still survived, because
the assertion looked at the whole screen and `Portée` is ALSO the options
row's scope caption. The footer was being truncated behind a passing test that
matched the wrong row.

**Attempt three** reads the footer ROW — the one line carrying `(Alt+Enter)` —
and asserts the action after the key is still on it. Four mutations, all dead.

The lesson is the old one with a new face: a screen-wide `assertStringContains`
is a search over every row, so it passes on whichever row happens to carry the
needle. When the defect is that ONE row is too narrow, the assertion has to
name that row. See also `claims-are-not-guards` and the phantom title-bar clip
in the entry above — both times the reading was wide where the defect was
narrow.

## "1 results" (2026-09-09)

The find modal's title, the results panel's status, and the export flash all
counted with `%count% results` — no plural form, so one match read `1 results`
in all seven locales.

The catalog knows better in fifteen other places (`%count% version|%count%
versions`, `Copied %count% line|%count% lines`, `%count% setting|%count%
settings`), and the find modal's OWN grow row two rows below the title says
`%count% more result|%count% more results`. The two most-read counters in the
app were the ones not doing it. `Exported %count% results` was the third.

Nine test assertions pinned the ungrammatical form, which is how it survived —
`'1 results'` had been written down often enough to look intended.

**The guard needed care.** `assertStringContainsString('1 result', $screen)` is
satisfied by "1 results" and pins nothing; the test denies the plural
explicitly. That is the second time in one round a containment assertion passed
for the wrong reason — see the footer entry above — and both times the fix was
to make the assertion narrower than the screen.

Not changed: `%count% shown` ("1 shown" is correct), `Files (%count%)` and the
other parenthesised counts, and `Keybindings — %count% actions`, where a keymap
with one action is not a state the app has.

## The doc gate ran one way (2026-09-09)

`docs/keybindings.md` promised `Ctrl+G`, `Ctrl+Shift+T` and `Ctrl+U` under
**Inside Find in Files**. The modal binds none of them — they are editor verbs
that had sat at the end of the Search table since the doc was written, and
inserting the `### Inside Find in Files` heading above them (`f056e73b`)
swallowed all three into the new table. Moved back.

`KeybindingsDocTest` could not see it, and the reason is worth writing down: it
asked only whether every bound key is written down. That direction cannot
notice a ROW for a key nothing binds. `composer arch` fails on drift in either
direction and says so in `CLAUDE.md`; this gate had the same job and only half
the rule, so it now also grades the table against the code.

**Two mutations, and the third is the interesting one.** Re-planting the exact
regression fails, and renaming a documented chord to one nothing binds fails.
But breaking the row PARSER passed: no rows extracted means no strays, and the
gate goes green and stays green. Fixed by asserting the count first — the
`settle-loops-need-content` rule, in a new place: "nothing is wrong" is
satisfied by having looked at nothing.

Also checked, and NOT a defect: the five option tags (`.* Aa \b Pc ·"`) show no
keys on screen, which is the same gap the footer's `Alt+Enter` had. Every one of
them is documented in the table above, which is what `F1` renders — so the keys
are discoverable, the row has no room for five more hints, and adding chrome
there would be design rather than repair.

## Ninety-eight dead translations (2026-09-09)

Applying the previous entry's lesson — a doc gate that ran one way — to its
sibling. `CatalogCoverageTest` asked whether every used literal is catalogued.
`CatalogCompletenessTest` asked whether every English source has six targets.
Neither asked whether a catalogued string is used at all.

Fourteen were not, and each is carried in seven files: **98 dead trans-units**,
most of them translated into six languages by hand. Every one is a REWORD whose
old unit stayed behind — the editor's empty-state hint from before it read the
keymap, both find-bar hints from before they listed the regex and whole-word
chords, the settings footer from before it gained its find chord, the Go to File
title from when it carried a colon. A translator opening `messages.fr.xlf` today
would still be maintaining them.

**The gate is deliberately loose, and that is the whole design.** It substring-
searches the entire tree, tests and docs included, rather than reusing the
precise `->trans()` scanner beside it. Precision is the wrong instinct in this
direction: a false negative leaves one stale row, while a false positive DELETES
a live translation from seven files. Measured on the same data, the loose read
found 14 with no false positives; the strict scanner reports 143, because it
cannot see menu labels, action-catalog entries or plugin verbs.

**Two traps, both hit before the gate was trusted.**

The first run called the plugin-loader's vendored-package warning dead. It is
live — the PHP source escapes an apostrophe inside the literal and the catalog
stores it decoded, so the texts differ by a backslash. Had the list been acted
on unread, a live string would have been deleted from seven files. The corpus
decodes PHP escapes now, and dropping that decode fails the gate.

The second is stranger and worth remembering: the first version of the test
PASSED with its own target string planted back in the catalog. The docblock
quoted the dead strings verbatim as examples, and the test file is itself in the
corpus — so documenting a dead string is enough to make it used. The examples
are paraphrased now, and the same restraint applies to this entry: none of the
fourteen is quoted here either.

## The one-way-gate sweep, and where it ended (2026-09-09)

Two defects today came from a gate that graded only one direction, so the rest
of the gates were swept for the same shape. The result is worth recording
mostly for what it does NOT contain.

| gate | reverse direction | found |
| --- | --- | --- |
| `KeybindingsDocTest` | doc row → a key something binds | **3 stray rows** (fixed) |
| `CatalogCoverageTest` | catalogue entry → used anywhere | **14 dead strings, 98 units** (fixed) |
| `DocsIndexTest` | index link → a file that exists | 60 links, all live |
| `PluginObservableContractTest` | `docs/events.md` → a class | 13 named, all real |
| `VerifyGatesMatchCiTest` | — | already both ways |

The last three are clean, so only `DocsIndexTest` gained a gate — its direction
guards the F1 browser, which would otherwise offer a page it cannot open, and
the check is one `file_exists` per link. The other two were left alone: a gate
with nothing to catch and no way to be sure of its false-positive rate is a
liability, and `escalating [loop-leak] to a failure` is already on the settled
list for that reason.

**A whole-doc version of the keybindings reverse check was attempted and
abandoned.** Extending it past the one table means enumerating every binding
channel — the action catalog, the editor keymap, both find surfaces, and every
widget with its own `getDefaultKeybindings()`. A first pass over four channels
reported 31 undocumented-but-bound chords; every one was a blind spot, not a
defect: `Key::F5` rather than `'f5'`, `ctrl+page_down` in `TerminalPanelWidget`,
mouse gestures, and backticked `` Ctrl+` `` read as a backslash. The subsection
gate works because that modal keeps its keys in one explicit const; the general
case does not have one place to read, and a gate whose false-positive rate you
cannot state is worse than none.

That is the same conclusion the catalog gate reached from the other side: in
the reverse direction, prefer the check you can be sure of over the precise one.

## Infection over the session's diff (2026-09-09)

100 mutants over today's `src/` changes, 11 escaped, MSI 87%. Ten were real and
they cluster in exactly one place, which is the finding.

**`optionsRowWidth()`/`footerRowWidth()` sum each row's geometry a SECOND time**
— once to size the box, once to build the string — and nothing checked the two
sums agree. Every arithmetic mutant escaped: each `+ 2` off by one, the leading
column dropped, the separators dropped. Every test in that file asks whether
some text survived, and a box one column too WIDE still contains the text.

The guard is the invariant rather than the numbers: a row's declared width must
equal the width it draws, over five locale/scope pairs (the two rows trade
places for the widest depending on both). It kills all nine width escapes at
once and would have caught the original `(Al…` before it shipped. Pinning the
constants instead would have been nine assertions that go stale the moment a
glyph changes.

**The tenth**: `exportResults()` is Unit-covered, but every test reads the
FILE, so deleting its flash outright was invisible — and the flash is the only
thing telling the user an export happened, since it writes to
`.porta/search-results.txt` and opens nothing.

**The one survivor is left deliberately.** `max(1, …)` → `max(2, …)` on the
scope label's elision floor: both values keep `truncate()` off zero, and it only
bites in a box already too narrow to render the row. An equivalent mutant by the
doctrine at the top of `infection.json5` — recorded so the next reader does not
re-derive it.

**Cost of the fix.** Adding those tests pushed `FindInFilesModalTest` past
mago's `too-many-methods` threshold, which `mago.toml` says is bisected to the
real maximum and must not be raised to fit new code. Split by subject instead —
`FindInFilesModalGeometryTest` is the arithmetic, `FindInFilesModalTest` is what
the modal says — with the four-helper rig extracted to
`tests/Support/ComposesFindModal`, because the duplication gate is tree-wide and
copying `compose()` into the new class is exactly the clone it exists to refuse.

## The filler row nothing measured (2026-09-09)

Third instance of the same shape in one day, found by applying it as a lens
rather than by reading a screen: **a fixed row in a box sized from something
else**.

Both picker layouts size themselves from what VARIES — the title, the query,
the item labels — and both then draw a filler row when there is nothing to
list, which varies with none of it. `PickerLayout::layoutList` has no minimum
width at all (`layoutSearch`'s 24 columns is what has been hiding this on the
other side), so a Run or Branches overlay — three-letter title, short rows —
sizes to about fifteen columns and the filler is cut to fit:

| locale | message | drawn |
| --- | --- | --- |
| en | `no matches` | `no matches` |
| de | `keine Treffer` | `keine Tref` |
| fr | `aucune correspondance` | `aucune cor` |

Worse than the `(Al…` case that started the day, because these are truncated
with `ellipsis: ''`: a German reader sees `keine Tref` with **nothing** saying
it was cut. Both layouts count the filler now.

**Corrected the same day — do not take the reasoning below on its own.** This
originally counted the filler ONLY when it was about to be drawn, arguing that
measuring it always would widen every populated list for a row it never shows.
That trades a static width for a resize under the reader's eye, which
`layoutList` is explicitly built to avoid; see *Reviewing the day's own diff*
further down. It is measured unconditionally now.

`layoutSearch`'s floor seats every translation of `no results` today at 24
columns. That is luck, not a rule, and the test uses a longer message on
purpose so the guard does not depend on it staying lucky.

**Left as equivalent:** the `+ 2` in each addition mutated to `+ 1` and
survived. The row is `' '.$message`, so one column is the leading space and the
second is the trailing one that matches it — cosmetic symmetry with the item
rows above, not correctness. Asserting it would pin padding, which
`infection.json5` warns against in as many words.

## An input that hid its own tail (2026-09-09)

The previous entry flagged the list overlay's filter row as "probably the same
shape, but I cannot produce a case where it visibly breaks" and left it. That
was the wrong call twice over — it IS reachable, and the defect is not the one
the shape predicted.

The filter row was truncated whole, which puts the ellipsis at the END. A
26-character filter in a box sized from two short items drew `≡ exceptio…`:
past the width the row **stops changing as you type**, showing the opening
characters of a filter you can no longer read. The box measures the UNFILTERED
labels and never grows, so this is reachable by typing a branch name into a list
of short ones — which is precisely when a long filter gets typed.

The sibling settles it. `layoutSearch`'s query row has always scrolled
(`AnsiText::scrollLineToCaret`), because it carries a cursor marker to scroll
to. This row deliberately carries none — the frame allows one caret and the
search modal owns it — so the window is taken at the END of the text via the
same `caretWindow()` underneath, with an empty marker. The glyph stays put; only
the typed text scrolls.

**Why the earlier dismissal was wrong.** Both times the reasoning was "the user
watches the text appear, so a cut is visible". It is the opposite: an
end-truncated input goes STILL, and stillness is the one thing a typist reads as
"nothing happened". The rule to carry forward is that a row holding live input
scrolls to where the input is; only fixed text may elide.

## The family, swept (2026-09-09)

Four fixes today were the same shape, so the rest of the family was checked
rather than waited for. Two results, and the second one closes the thread.

**The remaining surfaces are correct**, and two of them are the reference for
how it should be done:

| surface | verdict |
| --- | --- |
| `MenuPanel` | counts label AND shortcut, elides the label, keeps the shortcut |
| `StatusBarWidget` | right side cut to `columns - 3` first, then the left; `128:37` survives to ten columns, every cut marked |
| completion popup | no fixed rows — every row is an item, all measured |
| the input sweep | `TextField::render()` self-scrolls via `caretWindow`; the picker's filter was the only site drawing `value()` instead, and it is fixed |

**The rule now lives in `subsystems.md`**, not only here. `decisions.md` is
where a settled question is argued; `CLAUDE.md` routes someone about to touch
overlays to `subsystems.md`, and that is the page that has to say "measure every
row you draw" BEFORE the fifth instance, not after it. Three rules in priority
order — count fixed rows, give up the value rather than the affordance, scroll
live input — with the four cases as the table that earned them.

**On the gate for it.** Pinning constants goes stale the first time a glyph
changes. The invariant — declared width equals drawn width, across locales
because the widest row changes with the translation — both fails when it should
and survives ordinary edits.

**Read the next two entries before quoting this one.** It originally said
"pinning content does not work" flatly, on the strength of nine mutants escaping
`FindInFilesModalTest`. That is true of a PRESENCE assertion and not of an exact
one — `MarkdownTableTest` pins whole rendered rows and kills the same class
without an invariant. The distinction is presence versus exactness.

## Table cells wrap now (2026-09-09)

`MarkdownRenderer` had always said tables and code fences truncate rather than
wrap. This measures what that cost and replaces it for tables.

**Before**, `docs/*.md` rendered at a range of pane widths, counting rows that
came out elided:

| pane | rows elided | of | share |
| --- | --- | --- | --- |
| 70 | 296 | 3407 | 8.7% |
| 91 (120-column terminal, tree open) | 192 | 3019 | 6.4% |
| 120 | 136 | 2745 | 5.0% |
| 160 | 99 | 2534 | 3.9% |
| 200 | 77 | 2413 | 3.2% |

Worst pages at 91: `plugins.md` (86 rows), `keybindings.md` (54) — the two most
reference-shaped documents, where a reader can least guess what was cut. It did
not go away with a bigger terminal: one cell in `plugins.md` runs to about 600
characters, so it was structural rather than a narrow-pane edge.

**Two things compounded it.** `HelpViewerWidget` binds no horizontal movement
at all, so an elided cell could not be scrolled to; and `findLines()` returns
the RENDERED lines, so `Ctrl+F` could not find the elided text either. A reader
who knew a phrase was in the docs was told there were no matches. The rendering
limit was documented; that consequence was not.

**After**: columns are budgeted against the pane — the widest gives up a column
at a time down to an eight-column floor — and each cell wraps inside its own, so
a row is as tall as its tallest cell. Truncation at the pane edge, counted the
same way:

| pane | truncated rows, before | after |
| --- | --- | --- |
| 70 | 296 | 34 |
| 91 | 192 | 9 |
| 120 | 136 | **0** |
| 160 | 99 | **0** |
| 200 | 77 | **0** |

Every one of the nine remaining at 91 is a **code fence**, which still
truncates on purpose: reflowing code changes what it says. No rendered row
exceeds its pane at any width, so the compositor invariant holds.

**How it is built.** The word splitting and the greedy packer were extracted
from the paragraph wrapper and are now shared, so a cell breaks exactly where a
paragraph would — and there is one implementation for the duplication gate to
be happy about. Link spans come back per wrapped fragment and column-relative,
which is what lets a link survive being wrapped: three tests that used to assert
a link was DROPPED by truncation now assert it survives, which is the whole
point of the change.

**The mutation follow-up, and a correction to the entry above.** Infection over
the change: 118 mutants, 22 escaped, MSI 81%. Every escape is an
Increment/DecrementInteger on a defensive fallback — `?? 0`, `?? 1`,
`max(0, …)` — whose branch cannot run, because `$widths` is the union of every
row's columns and each row iterates a subset of them. The analyzer requires the
`??` (it cannot prove membership across two arrays), so they stay: equivalent
mutants, recorded here so the next reader does not re-derive it.

Two tests were written for those escapes before reading them properly — a
column-alignment invariant and a wrapped-link-position check — on the assumption
that the escapes were LAYOUT arithmetic. They were not, and both tests turned
out to kill nothing the suite did not already kill: verified by disabling them
and re-running the same mutations, which still failed. Deleted.

That corrects the claim in the entry above it. "Pinning content does not gate
geometry" was true of `FindInFilesModal`, whose tests ask whether some text is
PRESENT — and a box a column too wide still contains the text. It is not true
here: `testATableColumnPadsToTheWidestCellPlusATwoColumnGap` and its neighbours
pin the exact rendered rows, and an exact row moves when the arithmetic does.
The rule is not about content versus geometry; it is that a presence assertion
gates nothing about layout, while an exact-rendering assertion gates it well.

**Two things the gates caught.** The column budgeter had an empty-set guard that
`max()` seemed to need; the coverage gate showed the line never runs, because
with no columns the row is one space wide and the loop containing the `max()`
cannot start. Deleted, with a test for the degenerate table that made the
argument concrete. And the test class hit mago's `too-many-methods` — bisected
in `mago.toml` to that class's own count — so the table cases moved to
`MarkdownTableTest` rather than the threshold moving.


## Reviewing the day's own diff (2026-09-09)

Fourteen commits with one pair of eyes on them, so the diff was read back as a
reviewer would. One finding, and it is in my own change from earlier the same
day.

`PickerLayout::layoutList()` measures its width over the UNFILTERED items on
purpose — the comment there says why: a box sized from what survives the filter
shrinks and re-centres on every keystroke, walking out from under the eye
reading it. The filler-row fix counted `no matches` **only when the filler was
about to be drawn**, which reinstates exactly that. Measured: 15 columns, then
27 on the keystroke that emptied the list.

The commit message even argued for the conditional — "measuring it always would
widen every populated list for a row it never shows" — a plausible-sounding
trade that swapped a static width for a dynamic resize, against a principle
written down three lines above the line being changed. Both layouts measure it
unconditionally now, and the no-resize property has its own test: one width
across a filter that matches everything, something, and nothing. Re-planting the
conditional fails it, and so does dropping the measurement.

The lesson is not "review your own diff" so much as **read the comment beside
the line you are changing**. The reason that box is sized the way it is was
already there, in prose, three lines up — and the fix that broke it cited a
trade-off the codebase had already decided.

## What the wrapping cost, measured (2026-09-09)

The rest of the day's diff, read back the same way. No further defects; two
numbers worth keeping and one wart written down.

**Rendering is memoized on width.** `HelpViewerWidget` re-renders only when
`$cols` changes, so the column budgeter's shrink loop is paid on a resize, not
per frame. Alternated A/B/A/B against the pre-change renderer:

| workload | before | after |
| --- | --- | --- |
| all `docs/*.md` × 4 widths (40 renders) | 95–97 ms | 108–112 ms |
| `plugins.md` at 70 columns, the worst case | 5.97 ms | 8.20 ms |

About 0.35 ms per document render, and 2.2 ms on the largest document at the
narrowest width — comfortably inside a frame, and most of it is producing the
extra rows rather than the loop, which runs a few hundred trivial iterations
even against a 600-character cell. No optimisation warranted; the number is
here so the next reader does not have to take it on faith.

**The wart.** `DiagnosticLog`'s clock is a trailing default, so `freeze` and
`perf` stamp from their injected clock while `error` and `dap` — built where no
container is at hand — stamp from the wall clock. A time-frozen test sees the
two disagree. Nothing asserts on a crash stamp, and `Ide` cannot reach the clock
without widening the `porta.ide` locator for one caller, which its own
membership gate refuses. Recorded in the class docblock rather than fixed.

## The dependency upgrade, four commits (2026-09-09)

Thirteen direct packages were behind. Done one package group per commit, each
green before the next, because the history said all three risky shapes had bitten
here before — a tui bump that moved a golden (`afa6e6c1`), a mago bump that
raised a false positive (`05793753`), and `mago.toml`'s own warning that an
upgrade recounts metrics.

**What actually happened, against what was expected:**

| expected | actual |
| --- | --- |
| a golden diff from `symfony/tui` | none — both byte-exact goldens unmoved |
| the `BidiTui` private-property mirror breaking | intact: `Tui.php` has ONE change, in teardown cursor math |
| mago finding new things | yes, 7 — 5 redundant docblocks, 1 false positive |
| thresholds recounted by mago 1.47 | no — the one that moved, moved because the CODE did |
| infection 0.35 breaking its config | no — all five keys survive the schema |

**The pre-flight was worth it and would have been worth it even at zero
findings.** `src/Ui/BidiTui.php` reflects four private `Tui` properties and
mirrors a private method body, and nothing in the suite checks that mirror is
faithful — so it is checked by reading the vendor diff, not by running tests.
All four names survive and `processRender()` is byte-identical.
`TerminalInterface` is unchanged, so `NonBlockingTerminal` misses no method, and
`Terminal::getColumns()` moved `?? 80` to `?: 80` — a cached ZERO now falls back
to 80×24, which we inherit because we delegate.

**`AnsiUtils` gained real width fixes** that could have moved everything and did
not: `visibleWidth()` now walks graphemes when combining marks or ZWJ are
present, and tabs measure as `TAB_WIDTH` in the slicers where `mb_strwidth`
counted them as one. The cursor-marker format is unchanged, so the hard-coded
regexes in `OverlayCompositor` and `SpanSlicer` still match.

**The one threshold that moved is a lesson about attribution.**
`too-many-methods` went 87 → 86 — and NOT because 1.47 recounts it. Splitting
`MarkdownRendererTest` earlier the same day dropped the top class, so
`ResultsPanelWidgetTest` at 85 is the pin now. Had the two changes ridden in one
commit, the drift would have looked like the metric change `mago.toml` warns
about. Probing each of the nine at one below its pin is what separated them.
Also corrected: the `too-many-properties` inventory line read 32 against a pin
of 31 with no correction note — 31 is right, and the prose was the stale half.

**Infection 0.35 changes the denominator, not the quality.** The same reference
diff went 118 mutants / MSI 81% to 126 / 75%: more variants per line, same
lines, same equivalent class.

### And it found a test I had deleted too early

One escape at line 330 was NOT the usual defensive-fallback shape. The
empty-fragment fallback a SHORT cell receives on a tall row carries
`width => 0`; at `-1` every later column on that continuation row shifts one
place right. The table is visibly crooked and the whole suite passes, because
every assertion in that class asks whether text is PRESENT.

That is the column-alignment invariant deleted earlier the same day as
"redundant" — a judgement made by testing it against three mutations and
generalising. This is a fourth it catches and nothing else does. Restored, and
tightened: the first version only caught text crossing LEFT into the padding,
so `width => -1` (which shifts everything RIGHT) still escaped it. It now
asserts where each cell BEGINS, and both directions die.

The rule this leaves: "I verified it against the mutations I thought of" is not
"it is redundant". The mutation tool enumerates; I do not.

## The no-intl leg, checked without being able to run it (2026-09-09)

The upgrade's one remaining risk was CI's `intl` / no-`intl` matrix, which this
box cannot reproduce — intl is compiled statically into the local PHP, so it
cannot be unloaded. What CAN be checked is the thing that leg actually exercises,
and this upgrade raised the stakes on it: `AnsiUtils::visibleWidth()` now walks
GRAPHEMES whenever text carries a combining mark or a format character, where it
used to call `mb_strwidth()`. Without intl those calls land on
`symfony/polyfill-intl-grapheme`.

Two things needed to be true.

**The polyfill provides the function at all.** `grapheme_str_split()` is a PHP
8.4 addition; had the polyfill not carried it, the no-intl leg would fatal on
the new path rather than fail an assertion. It does (`bootstrap.php:62`).

**Native and polyfill agree on WIDTH**, which is all `visibleWidth()` uses the
split for. Compared across 18 strings — Latin plus combining marks, ZWJ family
and tag-sequence emoji, regional indicators, Devanagari conjuncts, Hebrew with
niqqud, Thai, Arabic, CJK, and zero-width joiners/marks:

- **2 split differently.** `क्ष` (KA + VIRAMA + SSA) is one cluster natively and
  two under the polyfill.
- **0 differ in width.** The Devanagari case sums to 2 either way — one cluster
  of width 2, or two of width 1.

So the two CI legs measure the same widths even where they disagree about
cluster boundaries. Worth knowing that the disagreement exists: `sliceByColumn()`
walks the same split, so a slice boundary landing inside a conjunct would keep it
whole under intl and cut it under the polyfill. Not reachable by anything here —
no shipped locale is Devanagari and no fixture carries one — and not fixable
from this side in any case.

Incidentally the new path is a fix, not just a change: `mb_strwidth()` scored
that conjunct 3 columns, the grapheme walk scores it 2, and 2 is what a terminal
draws.

## The symfony-lsp round: four symptoms, four causes (2026-09-09)

Four things the user reported from real use, none of which shared a fix. Worth
recording because the shape repeats: the plugin could download a binary but had
no notion of *state* — it never asked what was installed, whether it ran, or
whether it was being used.

**"could not resolve the latest symfony-lsp release"** (`fe0000ff`). The tag
lookup went through `api.github.com`, which is 60 requests/hour per IP
unauthenticated. That budget was exhausted for this IP while nothing else was
wrong. The tag now comes from the `releases/latest` redirect on `github.com` —
ordinary release traffic on the host the download already uses — with the API
kept as a fallback that carries `Authorization: Bearer` when `GITHUB_TOKEN` or
`GH_TOKEN` is set. The blindness was the deeper half: `curl -fsSL` discards the
status, so rate-limit, offline, DNS failure and a missing curl all produced one
string. They are four messages now, and the rate-limit one names the reset time.

**A binary that could not run replaced one that could** (`52175da9`). There was
no smoke test, and the rename is atomic and irreversible. A wrong-architecture
download now fails `--version` while the old copy is still on disk.

**An upgrade silently did nothing** (`55acea0e`). The plugin relaunched by side
effect — writing `binary: ''` to trip a reconfigure — and
`LspSessionPool::reconfigure()` returns false when the resolved map is
unchanged. On a FIRST install the map changes (null → path) so it worked; on an
upgrade the argv is byte-identical, so no session stopped and the running
server kept serving the inode `rename()` had unlinked. `reload()` is the
explicit force, reached through `PluginApi::relaunchLanguageServer()`.

The test that caught it did not exist because every relaunch test covered the
first install — the one case that *cannot* short-circuit.

**"Install Server" stayed in the menu** (`3a55f8cb`). It cannot disappear:
`MenuItem`'s `isEnabled` predicate greys an entry, it never hides it. Hiding
would also have removed the update path, which was the affordance actually
missing — re-running install WAS the update, which nobody could guess. Two
commands over one action, each enabled when it applies.

### The follow-ups, and one measurement that closed a bullet

**Probe curl and tar before a byte moves.** tar is not needed until the archive
is on disk, so a machine without it waited out a ~9MB download to be told
`could not extract` — which reads as a corrupt release. Both are resolved with
`ExecutableFinder` up front now, and the old `-1 && -1` heuristic for "curl is
not available" is gone: a missing tool no longer reaches that code, so leaving
the branch would have made it a wrong label for a timeout.

**The scratch reaps itself.** Quitting mid-download runs neither the success
sweep nor the failure sweep, so ~9MB stayed under a pid-keyed name — and pids
recycle, so that leftover was also the tree the next install with the same pid
would extract into. Proven, not argued: with the sweep disabled the test lands
the *stale* binary and reports success. Directories are reaped only when
nothing in them has been touched for an hour, because a second Porta window may
be downloading into a sibling right now.

**`hash_file()` on the archive stays synchronous.** Measured on the real
artifact rather than argued: sha256 over the 8.9MB binary is **37ms** warm
(five runs, 36.6–38.2), so the ~3.5MB compressed tarball costs roughly 15ms —
one dropped frame, once per install, inside an operation that already spent
seconds on the network. An async chunked hasher or a `shasum` dependency buys
that frame back and adds a failure mode. Re-open it if the release grows an
order of magnitude, or if the same read moves onto a per-keystroke path.

**And a claim in core that was wrong.** `AsyncProcess::start()` carried
`@codeCoverageIgnore` over its unspawnable-binary return, justified as "proc_open
spawns even unusable binaries (exec failure surfaces later as an exit code), so
this is unreachable in tests". Not on the ARRAY form: PHP resolves the program
itself and `proc_open` returns false. The path is reachable, it is the only
signal a caller has for "this tool is missing", and symfony-lsp's error messages
were already leaning on it. It has a test now and no ignore.

## The floor that stopped fitting its own rows (2026-09-09)

Found the way this round is supposed to find things: in the user's own
`.porta/layout.json`, which carries `viewSidebarWidthPercent: {"settings": 8}`.

Eight percent is not a width anyone chooses. It is what a drag to the stop
persists, and where it lands is decided by `SettingsNavWidget::minWidth()` —
because `LayoutController::clampSidebarWidth()` applies minWidth OUTSIDE its
max-ratio and neighbour caps:

    max($sidebar->minWidth(), min($width, $sidebar->maxWidth(), …))

so on any terminal narrower than 200 columns, `settings: 8` resolves to exactly
`minWidth()`. That number was a literal **14**.

**14 was right when it was written.** `3a4340cc` (15 July) extracted the
`PanelWidth` trait and gave the nav its own floor; the nav then held three
sections and the language catalogue, and its widest row was `   JavaScript` at
13 columns. Since then Interface and Highlighting joined `SECTIONS`, git became
a plugin, and every installed plugin became an indented sub-row under Plugins.
`   local-history` needs 16. Rendered at the floor, with this machine's own
nine plugins:

    |   image-view…|
    |   graph-view…|
    |   local-hist…|

Three of the nine names the sidebar exists to name, cut — at the one width the
sidebar is guaranteed to be drawn at.

**Translation makes it worse than the English screen shows.** Rows go through
`trans()`, and French renders Keybindings as `Raccourcis clavier` — 19 columns.
At 14 a French user reads `Raccourcis c…` with no plugins installed at all.

`minWidth()` measures its rows now, through the same `rowText()` the render
draws, floored at the old 14 (so a bare English install drags exactly as far as
it always did) and capped at 24. The cap is not cosmetic: given the `max()`
above, a minimum derived from content with no bound of its own would let one
absurdly named plugin win against the terminal's own width. 24 is what
`PanelWidth` asks of a side panel generally; past it, rows truncate as before.

Both halves are mutation-checked — freezing it back to 14 fails three tests,
dropping the cap fails the fourth.

### Why only this widget

The same shape was checked at every other `minWidth()` and left alone.
`FileTreeWidget` (12) and the git list panels (24) hold filenames and commit
subjects — unbounded content, where a content-derived floor is meaningless.
`GitTabsWidget` (30) has finite translated content and was measured: German's
` Änderungen ` + ` Verlauf ` comes to 24, inside its floor.

The settings nav is the one whose content is finite, known at render time, AND
grew after its bound was set. That is the whole distinction — not "measure
every floor", but "a floor over a growing set has to be measured".

## Two tabs called plugin.php (2026-09-09)

`TabListProvider::title()` was `basename($b->path)` and nothing else, so any
two open files sharing a name were two identical tabs. In THIS repository that
is not an edge case — fourteen `plugin.php`, a `composer.json` per package, a
`Installer.php` under more than one plugin — and the cost is closing or editing
the wrong one of a matched pair.

PhpStorm and VS Code both answer with `name (dir)`, and that ordering turns out
to matter here for a reason neither of them has: the side tab strip
(`tabBarPosition: right`, which is what this machine runs) gives a name 19
columns. A leading `symfony-lsp/plugin.php` would truncate away the part that
says what the file IS; `plugin.php (symfo…` still reads.

The qualifier grows a directory at a time, because one level is not always
enough: two `GitSidebar.php` under `<plugin>/src/View/` agree on `View` and on
`src/View`, and separate only at the plugin name. It stops at the first depth
that distinguishes, so the common case stays `plugin.php (git)`.

**Only path-derived titles take part.** Every help page is deliberately one
`Documentation` tab and a titled buffer shows the title it was given — those
are decisions, not collisions, and qualifying them would undo one. The guard is
mutation-checked: removing it turns two `Project Architecture` tabs into
`Project Architecture (a)` and `(b)`, and the test says so.

### Where it is NOT a second site

Checked rather than assumed. Both file pickers already carry the directory as
their own field beside the name — `FilesSource` and
`SearchEverywhereController` each pass `dirname()` as the hit's secondary text,
so Search Everywhere and Go to File never showed a bare basename. The tab strip
was the one surface where the basename stood alone.

## A rebind you could make and never unmake (2026-09-09)

The user's `.porta.json` carries `keymap: {"cursor_left": ["left"]}` — an
override that binds the action to the key it already had. That is what pressing
Enter on the Keybindings page and then pressing a key does, and there was no
way back: the page had `rebind` and nothing else, so undoing it meant editing
the file by hand.

**An override is not only a binding — it is a PIN.** Whatever the file says
survives every future change to the shipped default, silently and forever. A
line that looks like a no-op today is a divergence tomorrow, and this one is
already in a file that travels with the project.

Two halves, and neither works alone:

- **`Del` resets the selected action**, dropping its line from the current
  scope's file — and dropping the whole `keymap` block once its last line goes,
  because a file left saying `{}` reads like a decision nobody made.
- **Overridden rows are marked** (the modified colour on the shortcut). Until
  now the one row with something to reset looked exactly like the two hundred
  showing a default. A colour, not a glyph: this row budgets its width to the
  column, and a marker column would come out of the label.

The flash names the default it went back to (`Reset Copy Path to Ctrl+Shift+C`),
and when the OTHER config file also binds the action it warns instead, reusing
the string `rebind()` already had for the same situation.

### This is not the provenance the page turned down

That settled item is "where did this binding come from", which would mean
re-deriving the merge of three *widget* keymaps outside the merge. This asks
something else entirely: does `keymap` name this action in `.porta.json`? The
config layer knows that exactly, and `layerOverruling()` was already asking it.

### The header hint, measured

`· Del to reset` went in LAST rather than after `⏎ to rebind`, and that is not
a style choice. The settings pane renders as narrow as 60 columns, where the
header truncates — putting the new clause in the middle pushed `Ctrl+F to
filter` off the screen, and an existing test at exactly that width caught it.
Last, the new hint is the one that truncates.

## The catalog that loaded as nothing (2026-09-09)

Found by tripping it. The two new strings above were appended with
`trans-unit` ids that already existed elsewhere in the file, and the result was
not an error — it was **every French string silently reverting to English**.

The chain: duplicate ids are well-formed XML but violate the XLIFF schema;
Symfony's loader validates against that schema and throws
`InvalidResourceException`; and {@see LenientXliffLoader} deliberately catches
it and returns an EMPTY catalogue, because English is the right thing to show
when a translation file is broken. All correct individually, and together they
make a whole locale disappear with nothing logged.

Every gate passed while that was true. `CatalogCompletenessTest` parses the
files with `simplexml` — which answers "is this well-formed XML", a question
the defect passes. What caught it was a widget test that happened to measure a
French label's width, three steps later and for an unrelated reason.

`testEveryCatalogSurvivesTheLoaderThatShipsIt()` now loads all seven through
the real loader and asserts the unit COUNT matches English — not merely that
the load returned, because an empty catalogue is exactly what the defect
produces and "it loaded" is satisfied by it. Reintroducing the duplicate id
fails it with `messages.fr.xlf loads 0 of 748 units`.

### The duplicate the gate led to

The same trip turned up a pre-existing one: `Cannot write %file%` had TWO
`trans-unit` elements in every catalog, with different ids. Well-formed, schema-
valid (the ids differ), and invisible — but the message id IS the source text,
so the loader keeps whichever comes last and the other unit's translations are
dead. In French, Japanese and Hebrew the two had genuinely DIVERGED
(`d’écrire` vs `d'écrire`, `を書き込めません` vs `に書き込めません`,
`את` vs `אל`), which means someone translated a line and watched it change
nothing.

The dead unit is gone from all seven, keeping what actually shipped so the
cleanup is structural — except French, where the surviving copy carried a
straight apostrophe against a catalog that uses `’` twenty-eight times to
thirteen. That one is a typo and was fixed with it.

Not gated: a duplicate SOURCE is legal XLIFF and the loader's last-one-wins is
sane behaviour, so a test would be asserting a house style rather than a defect.
Worth knowing it can happen, which is what this paragraph is for.

## The status bar dropped the file, and kept the cheat sheet (2026-09-09)

Rendered the real bar at four widths in three locales and looked at it. In
English at 100 columns, and in **German at 140**, the row begins:

    |  1:1  LSP:keiner (einen PHP-Server in den Einstellungen aktivieren)  Ctrl+S …|

The file name is not truncated there. It is **gone**. 140 columns is an
ordinary terminal, and the row is spending its width telling the user which
keys exist instead of which file they are in.

The cause is an ordering that was right when it was written and is not now:

    $right = AnsiText::truncate($right, max(0, $columns - 3));
    $leftMax = max(0, $columns - $rightWidth - 3);

The right side takes what it wants and the left gets the remainder — down to
zero. The comment above it even says so ("the steady-state left (the file path)
yields as before"). That was fine while `$right` was short; `$right` is now
pane facts plus a five-item key legend, and translated it is the whole row.

The right's TAIL is that legend, so reserving a floor for the left before
cutting the right spends the difference on hints nobody needs twice. The floor
is 24 columns, never more than the left actually has, and never more than half
the row — on a 40-column split, spelling a path in full at the cost of the
caret position trades one missing fact for another.

### And the path was cut from the wrong end

Fixing the floor alone would have bought `src/Some…`, which is every file in
that directory. The left is elided in the MIDDLE now — `src/Some/De…pleClass.php`
— because it ends with the file's name and begins with the contributed cells,
so both ends carry and only the directories in between are spare.
`AnsiText::elideMiddle()` slices by COLUMN on both sides: a double-width
grapheme landing on either cut must not hand back a row one column wider than
asked, which is the classic way chrome overflows and garbles the line beneath.

One consequence, taken deliberately: the clickable status cells are withdrawn
once the left is elided at all. Their spans are offsets into the UNCUT string,
and a middle cut moves every column after it while leaving the width the old
filter compared still plausible — a hit region that answers for the wrong cell
is worse than none. Mutation-checked, along with the floor, the half-row cap
and the elision itself.

## The sweep, and what it found on its first run (2026-09-09)

Three defects in one session shared a shape: a width chosen against the English
screen, and content that later grew past it. The nav's floor was 14 while
French spells Keybindings `Raccourcis clavier` (19). The keybindings header
lost `Ctrl+F to filter` when a fourth hint went in. The status bar dropped the
FILE NAME at 100 columns in English and 140 in German. Each was found by
rendering a screen and looking at it, which is not a thing that happens on
every commit.

`ChromeSurvivesEveryLocaleTest` does the looking: four chrome surfaces, all
seven shipped locales, six widths from 60 (below `COLLAPSE_BELOW`, where the
sidebar folds and the IDE is still meant to work) to 140. 422 assertions in
5.4 seconds.

**The assertions are locale-INDEPENDENT on purpose.** Comparing against an
English string is the assumption under test, so they ask structural questions
instead — is the element still there, and if it did not fit, does the row say
so. Menu titles are read off the widget itself. `Ctrl+F` is Latin in every
catalog. The fixture file is named so its tail is unmistakable.

The rule the whole thing encodes: **a surface may truncate; it may not silently
drop.**

### It found a fourth on its first run

At 60 columns the German and French menu bars end at Ansicht / Affichage, and
the row read as though Hilfe / Aide did not exist. They were never
unreachable — `menu_left`/`menu_right` cycle the whole list, not the drawn part
— so what was missing was any sign the row had been cut. It carries the
ellipsis now, and the mark is paid for out of the TITLES: a row filled to its
own edge has nowhere left to say it was cut, and taking the column from the row
instead renders 13 columns into a 12-column pane, which is the over-wide line
that garbles whatever sits beneath it.

### And two things the sweep corrected about itself

The first version rendered the settings nav through a narrow `RenderContext`
and asserted no row was elided. It passed for the wrong reason: the widget
renders at `min(its own width, the context)`, so a bare context measures the
default 22 and says nothing about the floor. It calls `setWidth()` now, and
asserts the width took.

The first attempt to prove the header assertion could fail mutated the English
source string in the widget — which changed the trans ID, missed the catalog
entirely, and made every locale render the English header. A mutation that
breaks the lookup is not a mutation of the thing under test. The honest one
reorders a catalog TARGET, and the gate does catch that.

Japanese needed one real change to pass at 60: `%count% 件のアクション` became
`%count% 件`, which is idiomatic and fourteen columns shorter. Every locale now
closes `Ctrl+F` by column 55, comfortably inside the narrowest supported width.

## "Module" that quietly means everything (2026-09-09)

`search-prefs.json` on this machine says `"scope": "module"` — the user's
standing Find-in-Files scope. What `Module` resolves to is the nearest ancestor
of the current file holding a `composer.json`, **or the whole project when
there is none**, and the selector said `Module` either way.

In this repository that fallback is the common case, not the edge one. The
packages live under `plugins/`, so editing anything in `src/` gets it: the
scope reads `Module` and searches everything. Editing
`plugins/git/src/Widget/…` gets `plugins/git`. Same word, two completely
different searches, and no way to tell which one is about to run.

The built-in labels say where they land now:

    Module (plugins/git)      Module (Whole Project)      Directory (src/Deep)
    Open Files (3)            Open Files (0)

`Whole Project` and `Current File` are left alone — they name themselves
exactly, and appending the path would be repeating the label back.

`Open Files (0)` is the same defect from the other side: with nothing open that
scope matches nothing at all, and a search returning no results because its
scope was empty looks exactly like one that searched properly and found none.

Reusing the existing `Whole Project` id rather than minting a new "whole
project" string: it is already in all seven catalogs, and one more literal
would need `en` plus six targets in the same commit for a parenthetical.

Safe by construction on the persistence side — `search-prefs.json` stores the
KIND and the selector index comes from `array_search($kind, BUILT_INS)`, so the
label text is display-only and could not shift a saved choice. The modal's box
clamps at 80 columns and the label elides inside it, so a deep package path
widens the modal at most to that.

## The rule column was the first thing cut (2026-09-10)

`483b8999` put the rule that fired at the END of a Problems row, and
`d24e1135` wrote down why: *"At the end rather than in front, because the rule
is what you search for to suppress it or to tell two identically-worded
findings apart; the sentence is what you read the row for."*

Both true. But the end of a row is the first thing a narrow panel throws away,
and the row is `path:line  message  rule` — so a realistic mago finding loses
it at 100 columns:

    ● Unused import: this `use` statement imports a symbol that is never refe…

The rule fails exactly where the docs say it earns its keep: **long messages
are the ones where a rule name is the only thing telling two findings apart.**

The placement was never the problem, so it has not changed. The rule travels as
its own `tail` field instead of concatenated onto the message, and the panel
reserves its width and elides the SENTENCE. Below a 24-column floor for the
message the row goes back to the ordinary whole-row cut — a rule name with no
sentence beside it names nothing anyone could act on.

### The shape this exposed

`array{path: string, line: int, text: string, ranges?: …}` is written out by
hand in **eighteen files**. Adding one optional key meant editing fourteen
declarations across eight of them before the analyzer went quiet, and the only
thing keeping them in agreement is that somebody notices.

Every other row in this codebase that travels between subsystems is an object —
`DisplayRow`, `SearchHit`, `KeybindingRow`. This one is a raw array because it
started as a grep result and grew consumers. Not refactored in that commit: it
is a mechanical change and a defect fix should not carry one.

**And "a small readonly class" turned out to be wrong** — see the entry below.

## The other list that scrolled in silence (2026-09-10)

The menu bar was made to mark its overflow this morning under one rule: **a
surface may truncate, but it may not silently drop.** That rule has a vertical
half, and only one widget in the IDE was keeping it — `TabBarWidget`, which has
drawn ▲/▼ edge rows since it was written. Everything else that scrolls drops
in silence.

Most of them are fine that way. The keybindings list is two hundred rows with
its count in the header and a filter. The results panel carries `N results` in
its status. The file tree is unbounded by nature and nobody expects to see all
of it. What they share is that the reader already knows the list is longer than
the window.

The settings nav is the one that is not like that: five sections, one indented
row per installed plugin, then the language catalogue — a finite, complete menu
of destinations, with no count anywhere. With nine plugins that is 28 rows, and
on a 24-row terminal the last six were simply not there, indistinguishable from
a list that ends where it appears to.

It reserves the two edge rows now, and only while it actually overflows, which
is the same trade `TabBarWidget` makes. A reserved ROW rather than a marker
column, because this widget's floor is measured to the column: a marker in the
last cell would come straight out of a name, which is the defect its own
`minWidth()` was fixed for earlier today.

The indicator draws unconditionally and the caller picks between it and a
blank — the sibling's shape, and the one that keeps mago's
`no-boolean-flag-parameter` quiet without an expectation comment.

**Not swept further on purpose.** The rule is not "every list gets arrows"; it
is that a list whose content the reader takes to be complete has to say when it
is not. Applying it to the keybindings list or the results panel would add
chrome to lists that already answer the question in words.

## One row shape, and the two ways it refused to become a class (2026-09-10)

The previous entry said the fix for fourteen hand-copied declarations of the
results-row shape was "a small readonly class, like `DisplayRow` or
`SearchHit`". Tried it. It cannot be one.

**`PluginApi::showList()` takes that shape.** It is part of the published
plugin API, so every plugin that builds rows builds plain arrays. Turning it
into a class is a breaking change to code this repository does not contain.

So it stays an array and stops being fourteen arrays: `@phpstan-type ResultRow`
lives once on `Porta\Support\ResultRowShape`, and every consumer does
`@phpstan-import-type ResultRow from …`. mago resolves it — checked before
committing to the sweep, and checked again afterwards by feeding a wrong shape
to an imported signature and watching it fail. No runtime change, no new arch
edges: the annotation carries the FQCN, so not one `use` statement was added.

Three things that had to be got right, none of them obvious:

**The holder cannot share the alias's name.** `final class ResultRow` holding
`@phpstan-type ResultRow` collides: the `use Porta\Support\ResultRow` wins and
every import silently resolved to the empty CLASS. Analyze went from clean to
noisy on unrelated lines, which is what gave it away. The holder is
`ResultRowShape`; the alias is `ResultRow`.

**The annotation belongs in the class docblock, above the attributes.** Written
between `#[AsWidget(...)]` and `final class`, it is a floating comment and not
the class docblock at all — mago reports `Cannot find … Porta\Widget\ResultRow`
and the alias resolves to nothing.

**A class with no members is the point, not an oversight.** `ResultRowShape`
carries no code because it cannot; the docblock explains that so the next
reader does not "clean it up".

*Re-opens if* `PluginApi` ever versions its row API, at which point the class
this wanted to be becomes possible.

## Infection over the session's own thirteen commits (2026-09-10)

Ran the project's own review tool across everything changed since
`3a55f8cb` — 309 mutants over 24 files, **259 killed, 47 escaped, MSI 84%**.
Read as a review rather than a score. Four of the 47 were real.

**`elideMiddle` was graded in one direction only,** which is the exact defect
this session committed a fix for twice (the doc gate, the catalog gate). The
sweep asserted `visibleWidth(...) <= $width` across widths 2–30 — and every
arithmetic slip in that function makes the result NARROWER, so all of them
passed. It is graded from below now, and an ASCII case pins the width exactly.

Tightening it immediately disproved my first guess at the bound. I wrote
`>= $width - 1`, reasoning that one cut could straddle a double-width grapheme;
width 16 came back 14. **Both** cuts can straddle, and each one that does costs
a column. The comment records the wrong number and the case that killed it.

**The settings nav's overflow test never came near equality.** `count($entries)
> $rows` with `>=` reserves two edge rows to announce an overflow that does not
exist — dropping two entries that fit in order to say the list is longer than
it is. Nothing tested exactly-fits.

**Delete with no reset callback wired dereferenced a null row.** The guard is
`null !== $row && null !== $this->onReset`; with `||` it is a fatal, and the
mutation escaped because every test wires both. Callbacks on that widget are
installed after construction, so "not wired yet" is a real state.

**The rule column's two-space gap was unasserted.** Removing the separator runs
the sentence and the rule together as one word, and
`assertStringContainsString('worse.unused_import')` passes either way. Asserted
against `…  worse.unused_import` now, cut and gap together.

### The other 43, and why they stay

Checked, not waved past. The bulk are the centring arithmetic in the ▲/▼
indicator rows (`intdiv($width, 2) - 1`) — moving the glyph a column is not
behaviour any test should freeze. `MenuBarWidget`'s `break` in
`fittingTitles()` is a provably equivalent mutant: `$used` only grows, so once
it passes `$columns` the counter is never reached again whether the loop
breaks or not.

The lesson worth keeping is not the 84%. It is that **three of the four were
one-way assertions** — a bound with no floor, a comparison never tested at its
boundary, a callback never tested absent — written by someone who had spent
the same day fixing exactly that shape in two gates.

## What mutating the PLUGINS costs, and what stops it (2026-09-10)

`infection.json5` says `"directories": ["src"]`, so no plugin has ever been
mutation-tested. The note in `porta-mutation-scope-src-only` records the
consequence — a green `composer mutate` says nothing about `plugins/` — but not
whether the gap is worth closing. Measured now.

**The recipe works.** Infection needs a coverage report that contains plugin
files, and the parallel run cannot produce one (the `<source>` block in
`phpunit.xml.dist` explains why). The serial plugin pass's own flags do:

    php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=#/(vendor|tests)/#' \
        vendor/bin/phpunit --testsuite=Plugins --coverage-filter plugins \
        --coverage-xml=… --log-junit=…

Without `pcov.directory=.` the report holds `src/` only and Infection reports
`0 mutations generated / MSI 0%` — which reads exactly like "nothing to fix"
and is worth recognising, because it is the failure mode this whole exercise
started from.

**What it found: nothing to fix.**

- `symfony-lsp` — **437 mutants, 437 killed, MSI 100%.** The plugin changed
  most in this session, and its own tests are stronger than anything core got
  today (84%).
- `todo` — 12 mutants, one survivor, and it is equivalent: dropping
  `array_values()` around an `array_filter()` whose result only meets
  `implode()` and `[] === $parts`, both indifferent to list-ness. The call is
  typing, not behaviour.

**What stops it being routine.** Infection 0.35.4 aborts mutant generation on
`plugins/git/src/Widget/GitPaneKeys.php`:

    Expected an instance of PhpParser\Node\Stmt\Class_. Got: …\Stmt\Trait_

It is a trait composing a trait — but that alone is not the trigger:
`src/Widget/Support/ListPanelInteraction.php` has the same shape and mutates
fine (128 mutants generated in that directory). The difference is that
`GitPaneKeys` composes `PaneNavigationCallbacks` from `src/`, outside the
configured source directories.

**`--dry-run` never reaches that step**, which cost the best part of an hour:
every plugin dry-runs clean, and `git` aborts the moment it is run for real. A
dry run is not a check for this.

No `composer mutate:plugins` script, deliberately: one that aborts on the
largest plugin is worse than a documented recipe. *Re-opens if* the upstream
`Trait_` abort is fixed, at which point the recipe above is the script.

**And a live trap in `composer mutate` itself, checked and NOT found.** The
obvious worry was that the same abort waits in core the day someone edits a
trait — `composer mutate` is always diff-scoped, so it only ever parses changed
files, and today's diff happened to contain none. Ran the whole of
`src/Widget/Support` (traits included) for real: no abort. The trigger needs
the cross-tree composition, so core is clear.

## The one toggle that could not warn (2026-09-10)

Went looking for settled decisions whose recorded *re-opening condition* had
since been met — a stale condition is the same defect as a stale justification,
and it costs the next reader a round. One had: "Refusing a write the other
layer overrules" says it re-opens if the settings screen gains a way to edit a
scope other than the one in effect, and {@see SettingsScopeRow} is that. The
decision survives anyway, for a reason that was not written down — switching to
Project scope to store a PERSONAL default writes it into a file that travels
with the repository — so the entry says so now rather than inviting the same
round again.

Checking its other claim found a real gap. It says **every** path that can
write into a shadowed key warns and proceeds — "a setting, a reset, a plugin
toggle, a rebind". The plugin toggle could not:
`ShadowedWriteWarning::warnIf()` reads `origin`, and
`SettingsPluginsController` was the only settings controller that set it on no
row at all. With `origin` null the warning returns early, always.

That matters most on precisely this row. The two plugin lists merge
differently, and CLAUDE.md's settled list singles the asymmetry out:

- `disabledPlugins` **unions** — both layers saying "not this one" agree, so a
  global disable always lands and can never be shadowed.
- `enabledPlugins` is **replaced whole** by the nearest layer that has it.

So flipping a BUNDLED plugin on from Global scope, while the project has an
`enabledPlugins` list — which this machine's `.porta.json` does, with nine
entries — wrote correctly to the user file, was discarded entirely by the
project list, and said nothing. Every other row on every other page would have
warned.

The toggle carries `origin` now, and only for bundled plugins: an installed
plugin's toggle writes `disabledPlugins`, and tagging that would claim a
conflict that cannot happen. Both halves are mutation-checked — dropping the
provenance fails, and claiming it for installed plugins fails too.

Nothing new was needed to say it: the existing funnel in
`SettingsViewController::afterWrite()` already routes every form edit through
`ShadowedWriteWarning`, and the row was the only thing missing.

### And the arch gate earned its keep

The test fixture wanted a temporary directory of bundled plugins, and the first
version chained off `new TempProject()->dir`. `composer arch` failed it by
name: the temporary is freed — and its directory removed — before the installer
ever globs it. That check exists because the trap has been hit before; this is
the first time it caught it before a flake did.

## The scopes the docs never mentioned (2026-09-10)

Auditing the re-open conditions (previous entry) meant reading the settled list
closely, which led to reading the user docs the same way.
`docs/search-navigation.md` described `Alt+S` as "Whole Project or saved
scopes" and stopped. Four of the five built-in kinds were undocumented —
Directory, Current File, Open Files and **Module**.

Module is the one that matters, and it is the one this machine has persisted as
its default. A reader never told the scope exists has certainly never been told
that it searches the WHOLE PROJECT when nothing above the file holds a
`composer.json` — which, in a repository whose packages live under `plugins/`,
is what it does for everything in `src/`.

The doc has a table now, one row per kind, saying what each searches. And a
gate, because nothing else reads prose.

### The gate's first version proved nothing

It asked whether each `ScopeKind::label()` appeared **anywhere** in the
document. Renaming the `Module` table row to `Modul` left it green: the word
still occurs in the sentences around the table. A vocabulary check against a
whole document is not a check that the vocabulary is described.

It reads the `| Scope | Searches |` table now, asserts one row per enum case
and no more, and that no row is empty. Three doc mutations kill it: rename a
row, delete a row, empty a row's description.

That is the third time in two days that a first-draft assertion has been
one-way — anywhere-in-the-file, `<=` with no floor, a comparison never tested
at its boundary. The pattern is asking a question the defect can answer
correctly.

## Eight widgets that had never been squeezed (2026-09-10)

`search-history.json` on this machine reads `["nb", "mb_", "mb", "co"]` —
someone auditing multibyte width calls, which is the family this whole session
has been fixing. Finished the audit.

**The `mb_strwidth` sweep came back clean.** Seventy call sites; one measures a
value that is also styled elsewhere, and it is right by construction —
`KeybindingsListWidget` measures the PLAIN `$shortcut` while drawing the styled
`$right`, and SGR adds no visible width. Measuring the plain value is the safer
of the two.

**The gate behind it was half-applied, and that is where the defect was.**
`RendersExactWidth::assertFitsEveryWidth()` renders at every width from 1 to 20
and asserts no row exceeds the pane — the guard against the over-wide row that
garbles the line beneath it. Seventeen widgets carry `#[AsWidget]`; **eight had
never been through it**, including both settings panes.

One of the eight failed: `KeybindingsListWidget` drew **eight columns into a
one-column pane**. The row is `label … shortcut`, and only the LABEL yields —

    $labelWidth = max(0, $width - $rightWidth - 2);

so once that floor is reached the row is still `' ' + shortcut + ' '` wide,
whatever the pane. `Ctrl+Alt+Shift+C` is sixteen columns; any pane narrower
than eighteen got a row wider than itself. The composed row is clamped now, a
no-op at every width where the label has room.

The other seven fit. All eight are in the sweep's provider now, so the next one
fails a build instead of garbling a screen.

### The vacuity guard earned its keep on the way in

`DockTabsWidget` with no provider renders zero rows, and a loop over no rows
asserts nothing while reporting success. The sweep refuses that explicitly, and
it refused my first fixture by name. The lesson it encodes is the same one this
session keeps relearning from the other side: an empty result satisfies almost
any assertion you can write about a result.

## Doc examples that nobody was checking (2026-09-10)

Swept the user docs against the surfaces this session changed, the way
`search-navigation.md` turned out to need. Two were under-described, both in
`docs/editor.md`: nothing said what happens to two open files with the same
name, and the Problems panel's rule column was documented as sitting "at the
end" without saying that being at the end no longer makes it the first thing
dropped.

Both now carry worked examples — and a worked example is the part of a doc a
reader trusts most and the part nothing checks. `plugin.php (git)` is a promise
about a format, and the format lives in `TabListProvider`.

`TabNamingDocTest` executes the promise: it builds the buffers the doc
describes, asserts the provider returns exactly the strings printed there, and
asserts the doc still contains them. Both directions were mutation-checked —
changing the format to a prefix fails it, and editing the doc's example to
`(symfony)` fails it too.

That is a different question from the one the other doc gates ask.
`KeybindingsDocTest` and `SearchScopeDocTest` ask whether a vocabulary is
MENTIONED; this asks whether a demonstration is still TRUE, which is the
failure mentioning cannot see.

### Checked and left alone

`.porta.json` is gitignored and untracked — the dead
`plugins.symfony-lsp.binary: ""` key left by the relaunch trick removed in
`55acea0e` is in the user's personal config, not the repository's, so it is
theirs to clear and not mine.
