# Settled questions

Things that were measured and rejected, or measured and deliberately kept.
Each entry says what would re-open it. Re-proposing one without new evidence
costs a round; that is the only reason this page exists.

This page is the verdicts. The measurements behind them — every round, dated, in
the order it was written — are the [decision history](decisions/README.md): find
an entry by its title there, or `grep -rn` `docs/internals/decisions/`, and read
that entry alone. When a docblock or another page says "decisions.md has the
numbers", the numbers are in the history.

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

**Hosting a view's region off-tree, or inside a widget's own render — rejected.**
Off-tree, a widget has no context: no rect to hit-test, no focus, no theme, no
render requests. Rendered inside a tree widget's `render()`, it lands in the
layout's measuring pass with position tracking off, so no rect is recorded. The
region stays attached under a hidden host and is rendered before each frame
(`ViewWindowPresenter`).

*Re-opens on:* symfony/tui gaining real layers (positioned children with tracked
rects), or the renderer's resting position stack changing — `ViewWindowPresenterTest`
fails first.

**The extraction audit is closed.** Six rounds measured every candidate against
one test: a method may be extracted when it *exclusively* owns its dependencies
(counted inside the method versus across the file) and touches none of the
cross-event drag state. Rejected, with the count that rejected each:

| Candidate | Exclusive dependencies |
| --- | --- |
| `BufferService`'s pane-swap cluster | 1 — later 2, and extracted (see below) |
| `CodeEditorWidget`'s render cluster | 3 of ~23 (see below) |
| `GitViewController`'s history tier | 1 of 11 |
| `HelpViewerWidget`'s history stack | 0 of 6 — still; the whole page became a base on 2026-09-30, a different shape |
| `HelpViewerWidget`'s FindTarget adapter | 1 of 7 |
| `DiffViewWidget`'s selection model | 0 of 8 |
| `DiffViewWidget`'s document/highlight cluster | 2 of 10 |
| `OverlayHost`'s search surface (2026-10-23) | 0 of 3 — `$search`, `$searchFrame` and `$searchBox` are each read by the shared surface dispatch (`topmostSurface`, `frame`, `boxOf`, `layout`, `cancelDrag`, `moveSelection`) |

The render cluster fails twice over: it also *writes* four fields the mouse
paths read, which is the shared cross-event state clause.

Also rejected: unifying the four multi-field form state machines (eight variance
axes across four participants, and `ResultsPanel` has no active-field concept at
all), and splitting any of the four widget-roster enumerations — every split
*increases* total coupling edges, and the three rosters are different-order,
different-purpose lists.

*Re-opens on:* a changed dependency count or a removed cross-event seam, applied
to one candidate. Not a fresh sweep.

The pane-swap cluster met that condition on 2026-09-30. The preview split gave
it a second exclusive dependency, and it is `Workbench/EditorSlot` now.
[The live preview split](decisions/history-07.md) has the reasons.

`PluginApi`'s registration buffers met it on 2026-10-23, measured rather than
proposed: each of the sixteen `PluginBuffer`s was touched by exactly three
places — built, appended by its verb, drained at commit — so all sixteen were
exclusive. They are `Plugin/Api/PluginContributions` now, drained through one
`contributions()` instead of sixteen public `buffered*()` methods no plugin may
call, and mago's method pin fell from 94 (PluginApi) to 90 (the editor widget
and `SettingsFormWidgetTest`). The four plain arrays with semantics of their
own stay on the API.

**A preview beside the editor is not an editor split.** Editor splits are
declined: they need a second `CodeEditorWidget` and a focused-editor
indirection through all 25 of its injection sites. The preview split keeps one
editor and puts a pane beside it. *Re-opens on:* two TEXT views of one file.

**Namespace cycles are closed.** The import graph had 42 top-level cycle pairs;
the two stable-dependency inversions worth fixing were fixed. Verified at class
granularity: the one real SCC — a seven-class ring closed by the old view
registry's two `attach*()` setter fields — is gone, not pinned. A string-keyed
`ViewRegistry` has no concrete controller fields to close it with. What remains
allowed is one degenerate pair, `IdeContainerFactory`/`Ide` (a version
constant). `PluginApi`/`PluginManager` was the second until 2026-10-23: the
reason pinned beside it — "every verb reports through the manager's fault
barrier" — had stopped being true, and once the manager builds the API and
hands it `runtime()`, `tools()` and `dispatch()` as closures, the API names no
manager at all. Relocating the widget→domain-VO edges was
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
- **A fact bridge in `porta.php`.** The plugin's widgets dispatch on its own
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
- The `#[AsWidget]` flex styles vanished with the attribute; `porta.php`
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
real gap in the working tree: `porta.php` was still building `GitRemote`
without its lane, so shutdown reached one lane of two. The defect the commit
claims to fix, reintroduced while fixing it, caught only because nothing
asserted the claim.

The fifty files are grouped by tier now (`Process/`, `Parse/`, `Diff/`, `View/`,
`Command/`, `Blame/`, `Setting/`, `Support/`), with the membership rules in
`plugins/git/porta.php`'s docblock — the only plugin large enough to need its
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

**Measure a cell with `AnsiUtils::visibleWidth()`, not `mb_strwidth()`.** The
2026-08-14 decision to leave the split alone rested on the two agreeing on
every cluster class. symfony/tui d0adb8b made them disagree: a ZWJ sequence or a
skin tone is two columns, a decomposed accent one, where `mb_strwidth()` sums
the code points. Re-opened and converted 2026-09-30 (dated history): the painted
sites call the painter's measure, `TextMetrics` walks graphemes with
`graphemeWidth()`, and the caret, search-highlight and breadcrumb tests fail on
the old measure. The git diff view paints its text with tabs expanded to the
editor's stops and maps every column on that text (`DiffViewRenderer::shown()`,
`columns()`); the model keeps the raw tab for copies and patches.

**Markdown is parsed by Porta's own parser, not `league/commonmark` — decided.**
The library's nodes carry no inline positions, and a later WYSIWYG mode needs
every rendered cell traced to a source byte. `src/Markdown/` passes all 652
CommonMark 0.31.2 and 24 GFM extension examples, three pinned to GitHub's output
(dated history).

*Re-opens on:* a spec version it cannot cheaply follow, or a parse cost the
preview's feed cannot absorb.

## Plugin API

**A project's plugins and runnable config wait for trust — kept.** Project
plugins used to be opt-out, "trusted like composer scripts". But opening a
clone ran its `porta.php`, its `.porta.json` named the git binary and the
language server, and the status poll ran both with nobody asking. Now an
untrusted project opens restricted (`Config/WorkspaceTrust`). Trust is per
project and asked once; `PORTA_TRUSTED_FOLDERS` exists for CI and the suite.
The runnable-key filter matches by NAME (`…path|binary|command|converter`,
plus a short list), so a third-party plugin's setting named that way is
covered without a new descriptor flag. `PluginApi::projectTrusted()` came
later (2026-11-05), once mcp, symfony-lsp and graph-viewer each read
`WorkspaceTrust` themselves from `porta.php`; git's process classes still read it
directly, since review and blame build them with no API at hand. *Re-opens on:* a plugin setting that runs
something and cannot be named to match; that one would need the flag.

**`registerWelcomeStep()` — designed and rejected.** The plugins worth
recommending are exactly the ones that are OFF, so they have not loaded and
cannot announce themselves. The only cards such a seam could produce are ones
already ticked.

**`EditorPaneWidget::flank()` — kept, one caller by the user's choice.** The
merge view is ours | result | theirs, and the result must be the one real
editor (splits stay declined), so a pane has to sit on the editor's other
side. Its one caller is the diff plugin's merge pane, against the rule on
one-consumer roles; the user chose the layout over the rule (2026-10-21). The
alternative kept on file is result | ours ‖ theirs — one pane drawing both
sides, no core change. *Re-opens on:* the merge view moving to that layout,
which makes the seam dead code to remove.

**Unifying the five plain-array extension buffers — rejected.** It needs five
variance axes for four conforming cases. Each array says at its declaration why
it is not a `PluginBuffer`.

**Graph-viewer's exports on `MenuSurface::EditorContext` — rejected.** That
surface resolves from the code editor widget's rect, and a viewer pane detaches
that widget — so a viewer-scoped command there is gated on exactly the state
that hides it.

## Remote projects

**A cache behind the filesystem seam, not virtual paths.** Opening a project on
a server keeps `$rootDir` a local directory, the cache, and puts the server
behind `Filesystem/ProjectFilesystem`.

The alternatives were measured against what reads the root:

- **Virtual `sftp://` paths everywhere.** About 62 `$rootDir` consumers and 57
  `ProjectPaths` users `realpath()` it, and so do `Lsp/Uri`, the buffer keys
  and every store.
- **PHP stream wrappers.** These are a process-wide global. `realpath()` fails
  on them. And they are synchronous: the tree's per-entry `is_dir` would cost
  a round trip each, on the loop that renders.
- **An sshfs/FUSE mount.** It needs software installed, and grep and a
  language server over a network mount are slow.

**Unfetched files are empty placeholders**, so listing-only code (the tree's
poll, quick-open, the reconciler) needed no change. Only the readers of
content ask `available()`.

**An upload carries its bytes from when it was queued.** It does not read the
file at its turn. A save followed by a rename before the upload ran found
nothing to send, and the rename after it failed on the server.

**Run, terminals, the debugger, language servers and tool plugins are refused
on a remote project.** The cache holds what was opened, not the project.

*Re-opens on:* remote execution (a transport that can run a command there),
which would make Run and a language server over ssh possible.

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

**The two coverage passes run at once — kept.** They share no state, and
overlapped they cost ~145s against ~190s in sequence. `coverage:src` keeps the
container cache on (~45s), safe because `--require=src` makes a lossy merge red. The one shard that failed
only when they overlapped was a test race, and it is fixed. Lower
`PORTA_SHARD_PROCESSES` before going back to sequence.

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

**custos (2026-10-08): four rules off, every finding failing.** The gate runs
`--fail-on=info`. `custos.json` switches off four rules, each because it
contradicts a tool already gating:
- `ElvisOperatorCanBeUsed`: mago's `no-shorthand-ternary` forbids `?:`, and
  rector skips `TernaryToElvisRector` for the same reason.
- `ClassConstantCanBeUsed`: the class-name strings are strings on purpose (see
  the `StringClassNameToClassConstantRector` skip).
- `UnnecessaryCasting`: 13 of its 35 "redundant" casts are what mago's analyzer
  needs to concatenate a `false|string`, `null|string` or `mixed`, and mago
  reports a truly redundant cast itself (`redundant-cast`).
- `ClassMethodNameMatchesFieldName`: all 72 were the callback-setter idiom
  (`onCommit()` stores `$onCommit`) or test fakes whose method names an
  interface fixes.

`JsonEncodingApiUsage` stays on. Tests pass `\JSON_THROW_ON_ERROR`, bar one helper
that falls back to the raw text. The 22 src sites that read network frames,
other programs' output or hand-editable files, or encode a log line, a map key
or a settings field, keep their tolerant answer under a reasoned
`@noinspection`. The five encodes whose `(string)` cast hid a failure (review
metadata, a forge request body) now throw instead of writing `""`.

Seeded fuzz tests moved from `mt_rand` to `Randomizer(new Mt19937($seed))`
rather than suppressing `RandomApiMigration`: `random_int()` cannot be seeded.

The first run's 773 findings had four real defects behind them:
- ssh's control folder, when it could not be made, still went to ssh as
  `ControlPath`, and OpenSSH exits on a missing one.
- A `Code` parameter in `JoinCommand` was reused for an exit status.
- A test discarded the `Sources` it built.
- Six `mkdir()` results were never read.

The race-guard re-checks (`&& !is_dir()` after a failed `mkdir`) cannot be
killed by a test; they are the shape custos asks for, not a claim of coverage.

Of the rules custos leaves off by default, seven are switched ON (the seventh,
`MultipleReturnStatements`, below):
`NullPointerException`, `DisconnectedForeachInstruction`,
`ClassOverridesFieldOfSuperClass`, `UnqualifiedReference` (a first-class
callable like `strval(...)` gets its `\` like any call), `UnNecessaryDoubleQuotes`
and `ComparisonOperandsOrder`. The last runs with `comparisonStyle: yoda` and
catches what mago's `yoda-conditions` lets through: a parenthesised left side,
`($x ?? null) !== null` (48 of them). One `is_string(...)` stays unqualified,
because mago narrows a filter to strings through it and not through
`\is_string(...)`. The first run of the first three found:
- 208 test dereferences of a nullable, nearly all a `?WidgetRect` read with no
  check (now `Widgets::rect()`).
- A highlighter's private `int $running` shadowing its parent's private
  `bool $running`; the counter is now `$inFlight`.
- A `stream_set_blocking()` repeated on every message.

The false positives this round met went upstream (janalis/custos#2: `?->` and
loop-condition narrowing, named arguments, escaped backslashes in patterns,
string destructuring, filesystem calls costed as cheap, and more) and shipped
in 0.1.1; the suppressions they needed are gone. Left off, by choice:
- `UsingInclusionReturnValue`: it flags the plugin contract itself, as a
  `porta.php` returns its instance.
- `BadExceptionsProcessing`: its only hits are "too many statements in a try",
  a style call; the swallowed-exception half found nothing.
- `EfferentObjectCoupling`, `CompactCanBeUsed`,
  `ParameterDefaultValueIsNotNull`, and `EmptyClass` (a command event with no
  payload is a class with no members, on purpose).

`MultipleReturnStatements` was left off with them, then switched ON at its
defaults (3 returns warn, 5 fail) by request: 435 methods across `src/`,
plugins and tests, with no suppression. mago's `no-else-clause` rules out the
`if/else` funnel, so the shapes are `match`, combined guards, `array_find` and
`array_any`, a `break` out of a search loop, and private helpers. It moved two
pins: `MouseRouter` dropped from 145 to 141 cyclomatic, and `BufferService` is
now at the kan-defect limit. One frame-reading bug fell out of it: the mcp
WebSocket reader took a 16-bit length of exactly 127 as the 64-bit marker.

custos 0.1.6 (2026-10-19, now the required minimum) added the failure-check rules: unchecked
`glob`, `file_get_contents`, `preg_replace`, `fopen`, `imagecreatefromstring` and
`realpath` results, short `unpack` buffers and repeated `match` arms. Its 64 first
findings were nearly all tests reading fixtures; they now handle the failure (the
`false === $found ? [] : $found` idiom, a `(string)` cast, an explicit `fail()`),
because custos does not read PHPUnit assertions as checks. One was real:
`GuestSession::channel()` guarded its `fopen` with an `assert()`, which production
compiles out. Three are false positives, suppressed with the new rules' own syntax,
`// @custos-ignore <Rule> -- why` above the statement (`@noinspection` does not reach
them): both WebSocket readers' `unpack`, whose first `match` arm already returns null
on a short buffer, and `FindBarWidget`'s two different flags read as one repeated
condition. That one is marked fixable, and its fix would delete a branch, so never
run `custos fix` over it.

## Leave it alone

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

**The cyclomatic and kan-defect pin holders — measured 2026-10-23, left alone.**
Each class at either pin was split by shared MUTABLE state (methods linked
only through fields they write or read, not through helpers they call) and by
per-method branch counts:

- `Editor/Document` and the graph viewer's `GraphViewerWidget`, tied at the
  cyclomatic pin (143): one state component each, 18 of 87 and 37 of 46
  methods, and the complexity is spread thin — the busiest method is ~22
  branches of ~260 (`applyEditsKeepingCursor`, `handleInput`).
- The nine kan-defect holders (`GuestSession`, `HostSession`,
  `BufferService`, `RemoteFilesystem`, `LspSessionPool`, `PluginManager`,
  `FindBarWidget`, `MarkdownPageWidget`, and `KeybindingsDocTest`): one
  dominant state component each. What falls outside it is one or two methods
  over one field (`RemoteFilesystem::walk`, `LspSessionPool::missing`,
  `MarkdownPageWidget`'s search matches) — single-consumer VOs, which this page
  already rejects.

kan-defect counts a class's `if`s, loops and `switch`es; at these sizes it
measures the class's size, and a pin is not lowered by shaving branches for
it. `MouseRouter`'s drag fields do form their own component, but arming a drag
happens inside the click handlers that use a dozen other collaborators, and
it holds neither pin.

*Re-opens on:* a component of three or more methods owning its mutable state
in one of these classes — the `PluginContributions` shape.

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
- `Plugin/Runtime/PluginRuntime`'s constructor (26, the parameter-list pin;
  measured 2026-10-23) — a value object the container fills, read by one
  consumer, `PluginApi`. Grouping its four decoration sinks would be a
  single-consumer VO inside a single-consumer VO; the pin moves, nothing reads
  better.
- `Workbench\Menu\MenuModel::item()` (8 params) — a private helper called only
  by `items()`, which already owns every one of those params itself; wrapping
  them travels the same data through a new object with no consumer outside
  this one file.
