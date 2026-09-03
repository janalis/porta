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

*Still out:* running a lens's command on click (the lens carries one; nothing
invokes it), and `clampToCaret()` still counts document lines, so the caret can
scroll a row or two imprecisely when many lenses are on screen — the same
relationship folding already has with it.

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

- **Overlap detection between text edits.** LSP says overlapping edits make a
  `WorkspaceEdit` invalid. Porta applies them bottom-up and both land, the later
  one clamping into whatever the earlier produced: corruption rather than an
  error.
- **Cross-file undo.** A multi-file rename is one undo step *per buffer*, and
  files rewritten on disk (those with no open buffer) cannot be undone at all.
  The refusal pass added this round narrows the blast radius — an edit that
  cannot be honoured now changes nothing — but it does nothing for an edit that
  applies successfully and is then regretted.

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

*Still open:* a DELETE strands marks the same way, though far less visibly —
`BookmarkStore::load()` drops vanished paths at startup, so it self-heals across
a restart. Worth a `FileDeleted` fact the day anything else needs one.

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
What is NOT done and was never in the v1: `vScroll` is still a document line, so
a line taller than the viewport cannot be scrolled into; and code vision, which
this was supposed to make nearly free, remains unbuilt.

*Re-opens on:* someone hitting the tall-line scroll limit in practice. It is a
one-line-at-a-time behaviour, not a correctness bug, and pricing the (line,
segment) scroll position was the saving that made this affordable at all.

