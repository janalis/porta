# Subsystems

Orientation for each part of the workbench. Read the section for the area you
are about to touch.

## The event loop

`revolt/event-loop` drives both the `symfony/tui` render/input loop and all
async work: LSP over stdio, git subprocesses, the integrated terminal PTYs (one
per session), run configurations. **Nothing blocks.** Long operations are timers
or callbacks on the shared loop.

Recurring work that touches the filesystem is **sliced**: the reconciliation
poll (`FilesystemReconciler::pollSlice()`, armed in `Ide::run()` at 4Hz) checks
at most four buffers and eight tree directories per tick, so a big project
degrades to slower change *detection*, never a longer loop stall. The
synchronous full pass (`Ide::pollFilesystem()`) remains the seam tests drive
when they need an external edit's effect observable immediately.

## Rendering

Widgets render to `string[]` measured in **display columns**
(`mb_strwidth`, `AnsiUtils::visibleWidth`), so CJK and other wide characters and
ANSI-styled text align correctly.

**Never re-slice your own sliced ANSI output in a loop.** The vendor slicers
(`AnsiUtils::sliceByColumn`/`sliceWithWidth`) replay every escape they skip
into each slice, so an accumulate-and-reslice fold multiplies escapes
*geometrically* — this one pattern produced four separate scroll-freeze bugs
(the decoration-span fold, indent guides, inlay hints: OOM at ~12 iterations;
see `LineDecorator::applyAll`'s docblock). Apply N decorations in ONE pass
over the raw line: spans go through `LineDecorator::applyAll`, insert/overlay
loops slice the raw line once per gap. A single bounded splice per row
(`OverlayCompositor::into`, `ModalShadow`) is fine.

Widgets cache their rendered output, and `invalidate()` only bubbles **up** — so
a change that must relabel the whole tree, such as a live locale switch, has to
clear render caches recursively. That is what the chrome-root sweep is for; see
[container.md](container.md).

Layout is horizontal and vertical `ContainerWidget`s. Sizes come from rendered
content, with proportions persisted per project.

**Frame scheduling** lives in `Ui/BidiTui`, layered over the vendor's flag +
adaptive-ticker model. `requestRender()` additionally queues ONE deferred
`tick()` per burst, so input paints in the same loop iteration instead of
waiting out the ticker's fresh 10ms repeat (the ticker stays as the retry
fallback). While the `NonBlockingTerminal` still holds undelivered bytes,
`processRender()` skips frame generation (back-pressure without blocking); the
writer's `onDrain` hook, armed on the first skip, repaints the moment the tty
catches up. An idle Tui goes fully quiet — `BidiTuiTest` soaks for it.

Frame generation is **capped at 60fps** (`MIN_FRAME_INTERVAL`): a trackpad
fling delivers each wheel report as its own stdin chunk, and uncapped that was
one full frame per event — enough to saturate the loop. A frame inside the
window is skipped with ONE trailing `Scheduler::delay` wakeup for the
remainder, so floods coalesce to the cap rate and the final resting frame
always lands. An idle→event frame still paints immediately, and
`requestRender(force: true)` (resize, the test `frame()` idiom) bypasses the
cap. Pacing is wired by `Ide` via `setFramePacing()`; bare test rigs that skip
the call are uncapped. `Support/PerfCollector` (always on) logs per-second
frame/input evidence to `.porta/perf.log`, with the paint time split into
`renderMs` (widget tree) and `writeMs` (ScreenWriter/tty), the terminal
backlog and the 1-minute load average per beat — and any single frame over
100ms gets an immediate `slow-paint` line naming the layer. BidiTui always
runs the manual render path (byte-equivalent to the vendor's, verified) so the
two phases can be timed separately.

The editor viewport **detaches from the caret on wheel scroll**
(`Editor/EditorScroll`): the caret may leave the screen and the selection
survives; any caret motion — version, line or column change — re-attaches via
the render-time `clampToCaret`, the one choke point every caret move already
crosses.

## Editor core

`Editor/Document` is the text buffer: a byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indent via `.editorconfig`. The
whole-line verbs keep their mutation, cursor clamping and undo here but delegate
the algorithm to the pure statics in `Editor/LineOperations` — except
`indentSelection` and `dedentSelection`, which track cursor and anchor deltas
*inside* the mutation loop.

Coordinate math (byte ↔ UTF-16 ↔ display columns, offset/position, the
visual-lines tab cache) lives in the internally-constructed
`Editor/PositionMapper`, which delegates to the pure-static `Editor/TextMetrics`.
`clampLine` and `clampCol` stay on `Document` because the mapper calls them
back.

`Editor/MultiCaret` and `Editor/EditorActions` layer multi-cursor and editing
operations. `Widget/CodeEditorWidget` with `Widget/Render/EditorRenderer`
renders the viewport; the caret is spliced in as a zero-width APC marker that
`ScreenWriter` converts to a hardware cursor position.

`Document` is the second-largest class in `src/` and pins no lint threshold,
because its mass is spread over 85 small methods. Correct — but it means the
ratchet gives no signal if it grows. Watch it in review.

**Whole-document swaps go through `Editor/DocumentReplacer`**, which is also
`PluginApi::replaceDocument()`: one undoable edit, caret clamped into the new
text, a full LSP `didChange`, and three silent refusals that *are* the contract —
nothing open, a viewer pane holding the slot, and an identical text (a tool that
changed nothing must not mark the buffer modified). It also tells the truth about
the modified flag: a swap whose text is byte-for-byte what the file holds marks
the document saved and re-stamps it, because otherwise the poller reloads
identical content and flashes "Reloaded" after every format. Size is compared
before content, since a project-wide replace deliberately diverges from disk.

## Input routing

`Input/MouseRouter` maps every SGR mouse event to the pane under the pointer and
owns cross-event drag and click state. **Its `route()` guard order is
behaviour** — active drags run first, then the panes front-to-back — and the
class docblock documents it.

Hit-testing uses the renderer's per-widget rects via `Input/HitTester`, whose
`contains()` is the shared pointer-in-rect predicate.

Satellites carry the parts that need no drag state: `WheelRouter` (all wheel
routing), `ContextMenuRouter` (all right-click routing), `HoverRouter`,
`ClickCounter`, `ZoomGesture`, `WelcomeMouseRouter`. The per-view ones live with
their views — the git plugin's `GitMouseRouter`, `Settings\SettingsMouseRouter` — because the
view carries them: `ContributedView::pointer()` is what `ViewRegistry::register()`
wires into `ViewPointers`, so a view and its pointer arrive together or not at
all.

Named zone rows share `Ui/ClickZones` — `clear`, `add(name, start, end)`,
`at(col)`, half-open, first match wins, which the help header's overlapping
padded zones rely on. Five other zone lists are deliberately not converted:
non-string payloads and positional lookups would launder worse than the loops
they keep.

**A trackpad pinch cannot reach a terminal program.** macOS delivers it as an
NSEvent magnify gesture and the terminal keeps it; no mouse protocol encodes a
magnification. What a trackpad *does* send is Ctrl+two-finger-scroll as ordinary
wheel notches, so that is the pinch: `Input/ZoomGesture` reads the gap between
notches — a 0.1s window, an order of magnitude above trackpad spacing and
comfortably below a repeated wheel click — and a continuation asks for a factor
while an isolated notch steps the ladder. The opening notch of a gesture is
necessarily a rung, since nothing can know a burst has begun until its second
notch.

## Full-screen views

`Workbench/View/ViewRegistry` keys `ContributedView`s by a **string id the view
supplies**. It was an enum, and the enum was the reason a view had to be known
at compile time — adding one meant editing `WorkspaceView`, which a plugin
cannot do. Everything that ranged over `WorkspaceView::cases()` now ranges over
what has registered.

`ownsMainArea()` moved onto the interface for the same reason, and Welcome is
what proves the distinction is real: it is *active* without *detaching* the main
area, so `isAnyOpen()` and `isAttached()` count only main-area-owning views.

A view carries everything it needs to be driven:

- **`pointer()`** — its `ViewPointer`, or null when it has no panes to click.
  `ViewRegistry::register()` wires it, so a view and its pointer arrive together
  or not at all. Three services used to carry `#[AsEager]` purely to register
  themselves.
- **`contextMenus()`** — its `ViewMenuSource`, on the same terms.
- **`focusTarget()`/`cycleFocus()`** — its own focus ring. Welcome's
  `cycleFocus` returns **false**, so `FocusCoordinator` falls through to the
  editor ring while the dispatcher's modal branch swallows keys.

`Workbench/View/ViewHost` does the main-area swap — clear, add the region,
restore the editor panes in RTL-aware order — once for every view.
`Layout/ViewRegions` mints the region: a compiled slot cannot reach a view core
does not know at build time, and the container is built before any plugin PHP
runs.

A plugin registers one with `PluginApi::registerView($id, $factory)`. The
factory runs ONCE, after plugins commit, with the seven-member `ViewServices`;
its region is minted before the call, so it can fill both panes immediately.
The git view is the proof that this carries a real one — see
`docs/internals/decisions.md`.

## Floating surfaces

Every modal floats over the whole workspace on one per-pane span path: spans
pushed through `setModalOverlay` plus a backdrop dim, presented by
`BidiTui::onBeforeFrame` hooks. The Welcome modal, Find in Files, and the
pickers (the centered list overlay, Search Everywhere, the mini prompt) all use
it.

`Overlay/` is organised by a strict three-tier rule, and a new floating feature
lands one class in each:

- **`State/`** — the mutable per-surface model (open, selection, query). Never
  returns `string[]`.
- **`Presenter/`** — the eagerly-resolved per-frame span producer. All four
  share `OverlayDelivery`: pane-union bounds with the pre-first-frame retry, the
  per-pane slice loop for both span slots, and the all-panes clear sweep.
  Delivery targets current panes and clearing targets all of them, because a
  surface may close across a view swap.
- **`Region/`** — pure geometry and hit-test value objects.

Each presenter keeps its own gate, state source and clear-once guard. **That
guard is the invariant that lets four writers share the span slots without
clobbering each other — do not centralise it.**

**The stateless render tier stays in `Widget/Render/`** (`ModalFrame`,
`PopupFrame`, `ModalShadow`, `OverlayCompositor`, the menu overlay helpers,
`ToastCard`). It returns `string[]` bearing the `$innerWidth + CHROME_COLS`
invariant and is cross-cutting — the editor renders there too — so it is not
overlay-owned. Do not collapse it into `Overlay/`.

`OverlayHost` owns picker state, layout and hit-testing in screen space. Opening
any floating surface closes the others. Pickers are fixed-centered, not
draggable, and the open modal owns the caret. That precedence has one spelling:
`topmostSurface()` — prompt, then search, then list — and its click, hover and
move dispatchers. `moveSelection` skips the prompt, which has no selection, so a
wheel with both open moves the list.

The menu dropdowns and the context menu wear the tighter `PopupFrame`
border-only language, with item-run hit-testing where the border swallows and
outside dismisses. The completion popup and signature hint stay
editor-pane-composited and framed the same way.

## The bottom dock

The terminal panel, the debug panel and the list tools share one dock above the
status bar, coordinated by `Workbench/Dock/BottomDock`, which also owns
`ResultsPanelMode` routing and the shared focus-return choreography.

All three extend `Widget/DockPanelWidget` (itself an `OverlayWidget`), which is
why `visiblePanel()` returns one type instead of a three-way union. It declares
`statusText()`, `setVisibleRows()` and the two verbs that genuinely differ:
`wheelBy(int $rows)` — the terminal scrolls its **scrollback**, so wheel-up goes
into history and the sign flips, while the two lists `scrollBy` — and
`clickRow(int $row)`, a no-op on the terminal, whose body clicks only refocus.
Those two retired the `instanceof` downcasts in `MouseRouter`.

It is a **class, not an interface**, deliberately: `FocusRing` and `PaneResizer`
compare the panel against the focused widget, and the router hands it to
`setFocus()` and the hit-tester, all of which need an `AbstractWidget`.

`wheelBy` is not named `scrollBy` precisely because the terminal inverts
direction. Sharing the name would make two opposite meanings look
interchangeable.

**Every list tool lives as a tab in the `DockToolTabs` model** — the model, not
the widget, is the source of truth. The single `ResultsPanelWidget` displays
exactly one tab, and tool tabs coexist: each Find-in-Project query, Problems,
TODO, each Usages list, the logs, Local History — one strip tab apiece, closed
only by its ×.

Instance identity is (mode, title). A plugin's contributed tab carries a **null**
mode rather than a new enum case, because `findByMode()` returns the *first* tab
in a mode, so a shared case would let a core caller clobber a contributed tab
while a null can never equal an enum case.

Switching tabs preserves rows, title, searching and the per-tab view state —
filter text, grouping, collapse, context and selection ride each tab as a frozen
`ResultsViewState`, identity-keyed so a live refresh of a *parked* tab cannot
dislocate them. Async completions write into their captured tab and reach the
widget only through `refreshIfBound`, so a late grep can never clobber the tool
on display.

`Widget/OverlayWidget` carries the panels' two axes: `isOpen()` (alive) versus
`isVisible()` (open and revealed). At most one panel renders; `Esc` is
`dismiss()`, and everything stays alive.

## Commands and keybindings

Single-sourced. `Config/ActionCatalog` is the descriptor table for core's
rebindable actions; `Config/ActionRegistry` is the whole set — the catalog
composed with what plugins declare, arriving through the consumer-owned
`Config/RebindableActionSource`, so `Porta\Config` imports nothing from
`Porta\Plugin`.

The union is **core-wins in both senses**, label and position, and
`registerActions()` refuses a catalog id outright — so a plugin can neither
relabel `save` nor reorder the keymap page's first rows.

Consume the **registry** wherever the question is "what can the user rebind";
the catalog stays the right constant for core's own tables.

`IdeConfig::load()` does not check whether a keymap id exists: it parses
`.porta.json` before the container, let alone the plugins, so it judges *shape*
only. Membership is asked once, in `KeybindingsFactory::create()`, which drops
unknown ids and reports each one time.

The keymap page resolves each row's key in three tiers — editor, then the
registered panes in registration order, then the IDE map — because a pane's
actions are bound on the pane and nowhere else.

`Config/CommandRegistry` holds IDE-level commands (id → handler, key-bound?,
in-palette?), and `Workbench/Menu/MenuModel` builds the menu bar and context
menus from these. **Don't hand-maintain parallel keybinding, palette and menu
lists — extend the catalog and registry.**

A `when` closure gates **key dispatch only**: a false `when` lets the key fall
through to the focused widget, while the palette and menus still run the
handler, which must self-guard. To remove a verb from all three, don't register
it.

## Syntax highlighting

Pluggable, and the engine is not privileged. `Highlight/CodeHighlighter` is
per-buffer and owns the caching, document-version bookkeeping, the 512 KB cap,
the line-count guard and the per-line SGR re-open. Behind it is a
`SyntaxHighlightProvider`: `id()`, `supports(language)`, `highlight(request,
sink)`.

**The seam sits below the version-cache check**, so a backend runs once per
document *version*, not once per frame. Wire it above and cursor blink invokes
third-party code. A test pins this. Don't move it.

**One delivery path for sync and async.** A synchronous backend calls
`$sink->deliver()` before returning; an asynchronous one delivers later. Core
tells them apart by whether the cache moved, so there is a single state machine.
`deliver()` drops anything whose version isn't the in-flight one, which covers
stale, abandoned and unsolicited answers.

**Async is paint-then-refine**: paint the previous cache, then repaint on
delivery. Delivery both invalidates the widget *and* requests a frame — widgets
memoise rendered lines, so `requestRender()` alone repaints from a stale cache.
Three faults (throw, mangled line count, timeout) drop the backend to plain for
that buffer.

The incremental splice is synchronous-only: it re-highlights one line inside the
frame, which an async backend cannot do, and splicing two engines' colours into
one cache is incoherent.

Resolution: configured id, then the first claiming plugin in load order, then
`PlainTextHighlightProvider`. **There is no built-in engine in that chain** —
Tempest is a bundled plugin. Plain is constructed, never resolved, so it cannot
be removed. Loose fragments (diff hunks, the empty-editor placeholder) bypass
the registry entirely: they are non-contiguous lines from different files under a
reused version counter.

`Support/LanguageMap` is the single source of truth for "what language is this
file?", with **two columns** — the highlighting grammar name and the LSP
`languageId` — which deliberately differ (`tsconfig.json` is `jsonc`, but LSP
must hear `json`). A file with no entry resolves to null and **no backend is
ever asked**, which is why languages with no built-in grammar are still named
there.

## LSP

`Lsp/LspSessionPool` routes traffic to per-language sessions started lazily on
the first `didOpen` of a matching file. Sessions are keyed by command **plus
`initializationOptions`**, so JS and TS share one server process, while the same
argv under different options (a config override colliding with a plugin entry)
launches separately rather than reusing whichever started first. An LSP-provider
plugin supplies options via `registerLanguageServer()`'s optional closure,
re-resolved on every (re)configure, and `LspClient` sends them in the
`initialize` request; the config/env/`--lsp` override layers never carry any.
A failed start — or a mid-session death, which reports through the same
callback — evicts the session slot so the next `didOpen` relaunches, capped at
three failed starts per signature (then sticky until a reconfigure); a
successful start resets the budget. `reconfigure()` no-ops on an identical
resolved map, so a provider-plugin setting the servers never see does not cost
every language its warm session.

Feature controllers extend `Lsp/AbstractLspController`.
`NavigationController`'s position-bound verbs ride one private scaffold —
`requestAt` for ready-guard plus position params, `requestForDocument` for
uri-only — with transport-error and nothing-to-show funnelled into a `$notFound`
**closure**, not a message, because the verbs flash through two different sinks.
`send()` is the chaining seam; call hierarchy's inner hop enters there and
deliberately never re-runs `ready()`.

## Debugger

`Debug/DapClient` speaks the Debug Adapter Protocol over a child process's
stdio. The whole wire layer — spawn, non-blocking pipes, watchers, the frame
drain, write-error convergence, teardown — is `Support/JsonRpcTransport`, shared
with `Lsp/LspClient`; each keeps only its envelope.

`Debug/DapSession` is one launch's order-agnostic state machine: 0-based lines
internally, 1-based on the wire, conditional and exception breakpoints,
run-to-cursor via a wire-level one-shot, evaluate. `Debug/DebugController` owns
the breakpoint, watch and exception-filter stores (persisted and restored before
the first buffer opens, so gutter dots paint at boot), the editor decorations,
the dock panel, and a terminal session for debuggee output — created and made
active but not shown.

Adapters are config-only: `.porta.json` `languages.<id>.debug`, with the
registry in `Debug/DebugAdapters`.

## Git — a bundled plugin, not a subsystem

**All of it lives in `plugins/git/`.** Core keeps no git-named symbol: not in
the view enum, the menu model, the status bar, the settings nav, or
`.porta.json`. What is written below is still true of the code; it is just not
core's. `docs/internals/decisions.md` has the move and the seams it needed.

`GitProcessRunner` owns the only mutable state in the layer — the in-flight
process map — so `GitInfo`'s thirty-odd porcelain verbs are stateless
argv-builders over it. That in-flight map is also the **E2e completion
barrier**: git mutates the tree before the exit callback fires, so an empty map
means the operation landed on disk. `RunsIde::awaitGit()` reaches it through
`ViewRegistry::find('git')` — the view is the one plugin-built object core can
name.

The runner answers a run that cannot happen as exit 1 with no output. Every
caller already handles that shape — the `Outcome` verbs read the code, and every
parser returns its empty value for an empty string — so that one guard is the
whole availability rule.

Availability has **two predicates and they mean different things**:
`isAvailable()` is binary-and-repository, `isRepository()` is repository alone.
Outside a repository `activate()` returns before registering the view, the menu,
the branch cell or any verb — `ide_git` included, which is why that id is
`adoptable` — so `MenuModel` skips the unregistered items and the group is
never built. Inside one with no `git` binary the chrome all stays and says so
when run: hiding it there would leave a user plainly inside a repository with no
way to learn why git vanished.

The repository check is `file_exists($rootDir.'/.git')`, not `is_dir`: in a
linked worktree and in a submodule that path is a **file**.

Output parsing lives in the `*Parser` tier. The view's axes are all pure
enums — nothing crosses a persistence boundary, which is what backed enums are
for.

## i18n and RTL

`I18n/Translator` wraps `symfony/translation`. **The English source string is
the message id**, so untranslated lookups fall back to English, with XLIFF
catalogs in `translations/`.

RTL mirrors the layout (reversed pane order) and, via `Ui/BidiTui`, reorders
each composed row **per pane**: `Ui/PaneSegmenter` splits the row into each
pane's column run, and `I18n/PaneReorder` flips each run within its own span via
`I18n/Bidi`, a UAX#9 subset. The reorder never crosses a pane boundary. Code
panes marked `Ui/LtrPane` stay verbatim. Mouse clicks map visual → logical per
pane before hit-testing.
