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

A slice count is only half of it: **one `is_file()` can block for twelve
seconds** on a loaded box — 35 of the 41 blocked-loop backtraces in the user's
own `freeze.log` are stopped inside exactly that. So `Editor/PollBackoff` times
every slice and stands the poll down when one overruns 50ms, doubling the gap on
each further overrun up to 30s and dropping back to every tick as soon as a
slice is quick again. The freeze cannot be made shorter; it can stop happening
four times a second. `Support/DiagnosticLog` is the same lesson one layer down:
an append is ONE filesystem call (the write), because the directory check is
memoised and the size cap is counted from each write's return value — five of
those 41 backtraces were the watchdog's own stall line blocking on the very
stat it did not need. Durations there are compared in whole **microseconds**
(`Clocks::microseconds()`), because a float second carries the epoch in its
mantissa and a duration built from two of them lands a hair either side of the
threshold it is meant to sit on.

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

**Slice with the vendor's `sliceByColumn`, `sliceWithWidth` and
`truncateToWidth` directly** (or `AnsiText`). Until symfony/tui d0adb8b their
fast path sized a run with `mb_strwidth()`, one column per combining mark, and
Porta wrapped them in `Support/AnsiSlice`; the wrapper went with that fix. The
marked-line cases in `AnsiTextTest` and `OverlayCompositorTest` fail on the old
vendor, so a regression upstream shows up there.

**Styled text as markup.** `Styles::format()` renders Symfony Console's formatter markup against the
active theme. It lives in `Ui/Markup`: `MarkupParser` is Console's own tag
grammar and escaping, and `StyleSpec` maps a tag onto a tui `Style`.
- **Named tags.** Console's four named styles map to roles. Every `Role` is
  also a tag under `Role::tag()`, which is the name its `.role-*` selector is
  built from.
- **Flat runs.** Output is painted the way Console paints it. Each run of text
  gets the merged style of every tag around it. So a single span is byte for
  byte `$style->apply($text)`, and a closed inner span gives back the
  surrounding foreground, which a hand-nested `apply()` does not.
- **No cache.** The tree is re-parsed each call, so a theme switch recolors
  like any role lookup.
- **Wrapping.** `formatLines()` wraps the plain text and then styles each line
  whole. Use it when a body wraps, as the toast card does.
- **When not to use it.** Markup is for assembling spans of whole roles. The
  byte-level seams stay byte-level: splicing into a styled line, open-only
  `getAnsiRestore()` runs, decorator open/close pairs, and SGR-58 underline
  colours.
- **`Markup` values.** A `Markup` value object carries markup through the
  notifier. A bare string there is always literal, so nothing reaches a tag
  without saying so. `Translator::markup()` escapes its params.

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
`renderMs` (widget tree), `bidiMs` (the RTL reorder — zero in an LTR session)
and `writeMs` (ScreenWriter/tty), the editor
widget's own share as `edMs`, the syntax-parse kind counters
(`hlFull`/`hlSplice` — whole-document re-parses vs incremental splices, fed
by the highlighter's `onParse` seam), the terminal backlog and the 1-minute
load average per beat — and any single frame over 100ms gets an immediate
`slow-paint` line naming the layer. **A timing field with no samples prints
`-`, not `0.0`**: a percentile of nothing is not a fast measurement, and
`edMs` is the one field with no counter beside it to give the difference away
(`paints=0` explains an empty `paintMs`; nothing says whether the editor
rendered). Reading a real log as though `edMs p50=0.0` meant "the editor is
free" is how you conclude the frame's time is elsewhere in the tree when the
log never said so. BidiTui always runs the manual render
path (byte-equivalent to the vendor's, verified) so the three phases can be
timed separately. **`bidiMs` is not decoration**: reordering one 226x67 frame
costs ~8ms, so until it had its own number an RTL session's own mirror read as
8ms of terminal write on a tty whose `pend=0` said it was keeping up — and
`slow-paint` named the tty for work the tty had not done. The mirror is
**memoised per row** against the previous frame (line + pane runs + floating
boxes, all three, rebuilt whole each frame): a repaint changes a row or two, so
a steady-state RTL frame costs 0.55ms where it cost 2.96ms, and an all-miss
frame costs what it always did. The first painted frame also stamps a one-shot `boot` line
from `Support/BootTrace` (created in the console entrypoint, published as a
synthetic like IdeConfig): cumulative `prelude`/`container`/`plugins`/
`eager`/`wired`/`firstPaint` milliseconds, so a "starts slowly" report
carries its own phase numbers.

**Overlay quiescence invariant**: the floating-surface presenters re-deliver
their spans on every frame hook, so the three span setters in
`MenuOverlayHostTrait` early-return on identical spans. An unconditional
`invalidate()` there turns any visible toast/picker/modal into a
self-sustaining 60fps repaint loop (invalidate → revision bump →
`Tui::tick` re-requests → next frame → hook re-delivers). The flip side:
that loop used to repaint everything constantly, hiding widgets whose
Porta-level line memos (the file tree, the side tab bar) never saw a chrome
sweep — which is why `ChromeInvalidator` must `invalidate()` (bump
revisions), not merely `clearRenderCache()`, as it walks the tree.

**Pixels, when the terminal draws them** (`Ui/Graphics/`). `TerminalGraphics`
probes once, deferred into the loop so raw mode is on: a kitty graphics query,
`CSI 16 t` for the cell size, then DA1 — every terminal answers DA1, in order,
so DA1 with no `OK` before it is a clean "no", and a timeout covers silence. The
replies arrive as input; its listener sits above the perf counter and the
dispatcher (priority 2000) and swallows them, or they would dismiss tooltips as
keypresses. WezTerm, Konsole and tmux are refused without asking (they answer
but cannot draw a placeholder); `PORTA_GRAPHICS=kitty|off` overrides. Its
transmit/place/delete commands are cursor-neutral and `q=2`, so they are
written straight to the terminal between frames. Two things were learned the
hard way, both in [the pixel-graphics entry](decisions/history-07.md) of the decision history: a pane never paints real placeholders — the
combining marks make the vendor's prefix slicers measure a cell three columns
wide, which cut every overlay splice short — but one-column plane-16 stand-ins,
which `BidiTui` expands (`KittyPlaceholders::expand`) on the finished frame
just before the write; and Ghostty ignores a virtual placement's source
rectangle, so `ImageViewer\PixelPlacement` cuts the view out itself and sends
it, alternating two image ids so a new view is new cell TEXT the differential
writer has to rewrite. The cut maps the view's cells exactly: what lies off the
picture stays transparent rather than the picture stretching over it, so a
point sits on the cell the hit tests map it to. A selection is a
`PixelOutline` stroked into the cut, since glyphs over a placement blank the
cells they cover. The graph pane hit-tests its bitmap with
`GraphRenderer::nodeShownAt()`, by the cell's document span. `nodeAt()` is
for the cell drawing, whose boxes round outward.

The editor viewport **detaches from the caret on wheel scroll**
(`Editor/EditorScroll`): the caret may leave the screen and the selection
survives; any caret motion — version, line or column change — re-attaches via
the render-time `clampToCaret`, the one choke point every caret move already
crosses.

## Editor core

`Editor/Document` is the text buffer: a byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indent via `.editorconfig`.

**Indentation is read from the file** (`Editor/IndentModel::detect()`): tabs vs
spaces from the first line that starts with either, and the SIZE from the most
common step between consecutive lines' indents — `    public function` under
`{` says four. Ties take the smaller step, and a file that indents nothing
keeps `Document::TAB_SIZE`. `.editorconfig` overrides it, and an explicit
per-language setting overrides that; `auto` on both means the file. A constant
default is what made the guides lie — one per two columns down a four-space
file, so every block looked twice as deep as it was. The
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

## The editor slot and live previews

The editor body is breadcrumbs, the find bar, then ONE content slot, and
`Workbench/EditorSlot` fills it with the code editor, a pane (a viewer tab's,
or a preview shown alone), or `Layout/PreviewSplit`: the editor with a preview
pane beside it. `BufferService::showBuffer()` decides which from the buffer.
A buffer with a `paneId` is a viewer tab. A text buffer with a `previewId` is
arranged by its `PreviewMode`: Editor, Split or Preview.

- **A previewed buffer is a text buffer in every arrangement.** It is saved,
  synced with the language server and reloaded from disk like any other file.
  Preview detaches the editor the way a viewer tab does, so the editor-hosted
  modals (the pickers, the unsaved-changes list) switch the tab back to Split
  before they open (`BufferService::revealEditor()`).
- **Routing.** A preview claim (`PreviewRoutes`: plugins first, then core's
  markdown) is asked before the viewer claims, and only for a file under the
  2 MB text cap, so a viewer still takes a file too large to edit.
- **The feed.** `Editor/PreviewFeeder` hands the pane the document's text when
  the tab comes on screen, and again 0.15 s after the version last moved,
  watched from the frame hook.
- **Focus.** `EditorArea::widget()` answers with whichever half has the keys,
  `entry()` with where they land on a fresh open, `visiblePane()` with what the
  routers hit-test, and `members()` with the focus ring's stops.
- **The markdown page.** `Widget/MarkdownPageWidget` is the documentation's
  page — navigation bar, links, history, find — and both the help browser and
  the markdown preview extend it. They differ in their tree (the docs, the
  project) and in what a link to another page does, which is the handler's:
  `Help/HelpViewerActions` renders it in place, `Help/MarkdownPreviewActions`
  opens that file's tab. A click on the page's text, away from a link, is
  reported with the source line its block starts on (`RenderedDoc::$origins`,
  kept by the renderer per block) and the word under the pointer; the preview
  searches that block of the text for the word.
- **Click-to-source, for every preview**, goes through the pane's tab handler:
  `ViewerTabHandler::revealSource($line, $byteCol)`, answered by
  `Editor/SourceReveal` (the caret there, the text given the keys when it is
  on screen, nothing for a viewer tab). Each pane finds its own position: the
  markdown page in the text it holds, the graph pane where the DOT first
  names the clicked node (`GraphViewer\Graph\DotSource`), the image pane the
  tag of the topmost SVG shape whose box holds the point (`ImageViewer\SvgHitTest`).
  The way back is `EditorPaneWidget::followSource()`, which `PreviewFeeder`
  calls from its frame hook when the caret reaches another line: the markdown
  page scrolls to the block, the graph selects the node written nearest the
  caret (`DotSource::nodeAt()`).
- **Sizing.** The two sides are FLEX WEIGHTS, never a width: a `flex: 0` side
  is measured by rendering it, which for the editor means twice a frame. The
  share persists as `previewSplitPercent`; below 41 columns the preview steps
  aside.

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
factory runs after plugins commit, with the seven-member `ViewServices`; its
region is minted before the call, so it can fill both panes immediately. The
git view is the proof that this carries a real one — see
`docs/internals/decisions.md`.

**A contributed view arrives and leaves live.** `ViewRegistrar::registerFor()`
builds one plugin's views on enable and `forget()` retracts them on disable, so
the factory runs once per *activation* rather than once per process. Retraction
closes an open view first (the editor takes the workspace back, and the view
gets to cancel its own timers), then un-mints the region — the next enable
mints fresh containers, because reusing one would hand the new view the dead
one's children. `LayoutController::forgetView()` drops the cached
`SidebarResizeState` at the same moment, or a later drag would resize widgets
that are no longer on screen. The layout recompile after a live build mirrors
Ide's boot tail exactly: `applyDirection()` then `applyLayout()`, in that
order, because the arrangement has to mount the region before a persisted width
can be applied to it. See `docs/internals/plugin-system.md` for why that runs
from an event listener rather than a direct call.

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
- **`Presenter/`** — the eagerly-resolved per-frame span producer. All five
  share `OverlayDelivery`: pane-union bounds with the pre-first-frame retry, the
  per-pane slice loop for the span slots, and the all-panes clear sweep.
  Delivery targets current panes and clearing targets all of them, because a
  surface may close across a view swap.
- **`Region/`** — pure geometry and hit-test value objects.

Each presenter keeps its own gate, state source and clear-once guard. **That
guard is the invariant that lets five writers share the span slots without
clobbering each other — do not centralise it.**

The slots composite bottom to top: modal (with the backdrop dim), toast,
**tooltip**, menu. The tooltip is the fifth surface: `State/TooltipCenter`
(the dwell, keyed on the element and not the pointer), `Presenter/TooltipPresenter`
(no shadow — it sits flush against what it explains) and
`Region/TooltipPlacement` (below, above, right, left, first with room).
`Input/TooltipRouter` asks whatever owns the cell under the pointer, in
`HoverRouter`'s order, through `Ui/TooltipProvider`; an open view's region is
walked for providers, so a plugin pane answers without registering. Any
non-move input dismisses the card in `InputDispatcher`, and the frame after a
resize asks again at the pointer's last cell, since a resting pointer sends
no move.

**Every shadow falls bottom-right on screen, mirrored or not.** A surface
under a right-to-left locale is composed logically and then flipped across the
row, so `ModalShadow::spans()` casts its shadow to the LEFT when told the
surface is shown `Rtl`, and `DropdownRegion::shadowClamped()` claims those
cells for the flip. On the band's row the region claims the band alone
(`lastRow`). A rectangle there takes the cell under the box's far corner, and the
flip fills it with a pane glyph from across the row. `OverlayDelivery::shown()`
says how a box is shown. A verbatim box (Find in Files, `'ltr' => true`) is
never flipped and casts right. `IdeShadowTest` checks each surface in both
locales.

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

A picker behaves like a dialog with a combobox:
- **Clicks.** A click inside the box that lands on no item (a header, the field,
  the frame) is swallowed. Only the × and a click outside dismiss.
- **Fields.** Every field and prompt takes `TextField::editKey()`: ←/→,
  Home/End, Delete and Ctrl+W, the settings form's caret keys.
- **Lists.** PgUp/PgDn move a box's worth of rows and stop at the ends, and Tab
  and Shift+Tab walk Search Everywhere's categories.
- **Filter.** The list overlay's filter field shows from the start, with a
  placeholder.
- **Size.** Both boxes take their width from the whole list
  (`SearchModalState::$widest`, the unfiltered items). They are placed as if
  as tall as the whole list (`$tallest`), so the top stays put and the box
  shrinks from the bottom as the query narrows. Nothing re-centres, and no
  blank rows stand in for the missing ones.
- **Streaming.** Rows for the query already answered keep the selected hit
  wherever it moved (`rowsFor`), and a new query selects its best match.
- **Pending sources.** An async category not yet answered shows its header over
  a `◌ Searching…` note (`SearchRow::note`). Selection lands only on `isHit()`
  rows.
- **Shapes.** Every state has a shape that survives monochrome:
  - the selected row's `▌` in column 0;
  - a category as a legend set into the tee that opens its section,
    `├─ Files ──┤` (`ModalFrame::wrapSections`' `$legends`): each takes the row
    its header did, and one that opens the list names the query's own tee;
  - the query's matched characters bold and underlined (`MatchMarks` over
    `FuzzyMatcher::positions()`, computed at render time, so no source
    carries positions);
  - the grow row led by `…`.
- **Clipped lists.** A clipped list shows `▲`/`▼` in its frame's padding rows,
  and each cue pages the list when clicked (`OverlayHost::edgeCue`). The
  selected row's place, `3/40`, sits in the bottom border (`ListOverflow`,
  through `ModalFrame::wrap`).
- **RTL.** The picker regions are bidi (`ltr: false`), so title, legends and row
  layout mirror. Each row's label–hint gap is a right-to-left mark under RTL,
  which ends the label's Latin run. Without it a Latin label, its spaces and its
  hint resolve into one run that never mirrors.
- **Disabled commands.** A palette command whose `when` is false comes through
  `paletteCommands()` as `enabled: false`: it is drawn dim and struck through,
  and picking it does nothing.

The menu dropdowns and the context menu wear the tighter `PopupFrame`
border-only language, with item-run hit-testing where the border swallows and
outside dismisses.

The editor's caret popups are composited into the editor pane:
- **Completion list** (`EditorRenderer::completionPopup`). Heavy-framed, since
  it holds the keys. It returns a `CompletionBox` with its frame and docblock
  rectangles, so a click there is swallowed.
- **Signature hint, doc popup and completion note** (`CaretPopupRenderer`).
  Their state is `CaretPopups`, one holder because the widget pins
  `too-many-properties`, plus `EditorCompletion::note()`.

The completion list and the doc popup share one placement rule: the side that
fits, else the roomier side cut to its room, never the caret row. The
signature hint is drawn last and takes whichever side (`CaretSide`) the other
caret popups left free.

A gutter click goes through `Input/GutterClickRouter`: the fold column folds,
and a change mark runs `git_show_change` when a plugin provides it. Anything
else toggles a breakpoint.

Every title and item has an access
letter, worked out per render from the translated labels by
`Workbench/Menu/Mnemonics` (word-initials dealt first, then a matching for
whatever the deal left out) and underlined where it survived truncation. F10
arms the bar (`MenuBarWidget::arm()`): `isOpen()` is "the bar has the keys",
`isDropdownShown()` "a menu is showing", and the pointer routes and the
overlay read the latter. Hidden titles are reached through the `…`, which is a
title of its own. A select's list takes letters as type-ahead instead. An open
menu holds the keys, so
both menus wear the heavy frame, separators as `┣━┫` tees, and the open bar
title swaps its padding for the frame's rails (`┃File┃`, same width, in the
frame's tint). The dropdown's top border is left open under that title
(`PopupFrame::topOpenAt()`, fed by `MenuBarWidget::openTitle()`), so the rails
run on into the box — `┃    ┗━┓`, or `┏━┛    ┗━┓` where the screen edge pushed
the box left — and it closes only for a menu dropped from the `…`. The box
fits the screen both ways: a row too narrow for its label and its shortcut
drops the shortcut, never cutting the label first, and a menu taller than the
panes shows a window of its items that follows the selection
(`MenuBarWidget::dropdownScroll()`), `▲`/`▼` in the border where more run on;
`DropdownRegion::$first` maps a clicked row back to the item it shows. The
context menu and a select's list do the same, on whichever side of their
anchor fits — or, when neither does, the roomier one; both widgets ask
`MenuPanel::window()` for the next window. A
resize under an open menu lays it out again on the next frame tick
(`MenuOverlayController::followResize()`); that needs `PaneBounds::union()` to
ignore rects the renderer kept from a larger screen — a detached pane's, or a
zero-row edge rule's — which it does by skipping detached panes and clipping
the rest to the root's rect. A
disabled item is faint, dim and struck through on its label; neither it nor a
separator takes the row highlight, which is an underline under monochrome and
made a disabled item look hovered.

The rules for how controls, toasts, rules, tab strips, the status bar, list
selection and a floating box's width LOOK are on their own page:
[How Porta looks](ui-style.md).

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

**Bookmarks** (`F11` to toggle, `Alt+2` to list) is a tab like any other, and is
the one core tool whose rows outlive their buffers. `Editor\Bookmarks` owns the
marks — not the buffer, because closing a file must not silently discard them —
`History\BookmarkStore` persists them to `.porta/bookmarks.json`, and
`Editor\BookmarkRows` reads each row's text from the open buffer when there is
one and **from disk otherwise**: a list of bare line numbers would be no help at
all. Toggling refreshes a *standing* Bookmarks tab in place and never opens one,
so marking a line cannot throw a panel over what you are reading. The gutter is
painted only through `Editor\BookmarkDecorations::apply()`, the same applier the
buffer switch uses, so it can be painted one way only.

**Recent Locations** (`Alt+5`) lists `Editor\JumpHistory` — the places every
navigation verb pushed before moving the caret, most recent first, consecutive
repeats collapsed. Navigate-back pops that same stack; showing the list does
not, which is the point. Both it and Bookmarks read their row text through
`Editor\LineTextReader`, which answers from the open buffer (in either path
spelling) and falls back to disk.

**The Structure tab follows the caret** (`Editor\StructureFollowsCaret`, a frame
hook, so `#[AsEager]`): its selection moves to the symbol enclosing the caret as
you type. No language-server round trip — the tab's rows already carry each
symbol's line and arrive in document order, so the enclosing symbol is the last
row at or above the caret. It runs only while that tab is the one ON DISPLAY,
which is what `BottomDock::displayedRows()` is for: a parked tab's selection
lives in a frozen `ResultsViewState`, not in the shared widget, so following it
would write into whichever tool is actually visible.

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

**A language can have more than one server.** The map is
`languageId => [its own server, …the ones beside it]`, and which is which comes
from `registerLanguageServer()`'s `ServerRole` rather than from load order —
which was the directory glob, so alphabetical, which is how symfony-lsp lost
`php` to `phpantom` by name. Every session for a language receives the whole
document lifecycle (`didOpen`, `didChange`, `didSave`, `didClose`, and the
flush inside `ready()`); a feature request still goes to the first one only,
because most verbs pick a single answer and asking two would mean inventing a
tie-break for each. Entries with an identical launch signature collapse to one
session, both in `PluginManager` and again in the pool: telling one process a
document opened twice is not a harmless duplicate. `statusFor()` reads
`own +beside`, and names a failed one rather than hiding it behind the
survivor.

**The verbs whose answers are LISTS are merged.** `requestAll()` asks every
session for the active file whose OWN capabilities advertise the method, and
calls back once per answer as answers arrive — deliberately incremental,
because `request()` has no timeout and waiting for the last answer would let
one silent server hold the verb forever. Completion items, code actions and
code lenses merge that way; each carries the server that offered it
(`Lsp/ServerOwner`, a key inside the payload, taken off again before the
payload goes back on the wire), because `completionItem/resolve`,
`codeAction/resolve` and `workspace/executeCommand` are all second round trips
that have to reach the same server. `requestTo()` is that half; an empty owner
means "whoever serves the active file", and a named-but-gone one is a silent
no-op. Each merging controller holds a GENERATION, because several servers
answer one keystroke at their own speeds and a late answer to a superseded
request would put a list of the wrong things back on screen.

Everything else stays single-winner, deliberately: hover, rename,
prepareRename, formatting (two formatters' edits over one buffer overlap, and
`WorkspaceEditor` refuses overlapping edits), documentSymbol (two trees for one
file is a duplicated outline) and semantic tokens — whose legend is read out of
the same server's capability that decodes its response, so a merged capability
would decode with the wrong one.

**Published diagnostics are keyed by OWNER.** `DiagnosticsSink::applyDiagnostics()`
takes the server's name (bound per session, like the log and `showMessage`
hooks) or the publishing plugin's, and `Buffer::$diagnostics` is a derived
merge of every owner's slice. Before that it was one slot: a server publishing
on every keystroke and an analyser plugin publishing on every save wiped each
other, so phpstan beside a PHP server showed one set or the other depending on
which had spoken last. It cannot be fixed by ignoring empty publishes, because
LSP requires an empty list to mean "I have cleared mine" — only the owner tells
"nothing to say" from "nothing any more".

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

## Agents at work

An agent connected through the mcp plugin is SHOWN by core, told by events:
the plugin emits `AgentActivity` (who, which file, reading or editing, where,
how long to stay) from each tool, and `AgentLeft` when its client goes.
`Workbench/AgentPresence` keeps who is on which file, hands each agent a colour
slot (`Role::Agent1`–`Agent4`) and one fade timer per (agent, file) through the
`Scheduler`. It PUSHES each caret to the editor's decoration store as a report
moves it (`EditorDecorationSink::setRemoteCaret`, keyed by file and agent, not
cleared on a tab switch); in between, `DecorationStore::remapForEdit` shifts
them with the user's own edits by the rule the git gutter marks follow — and
along their line, by how much it grew, once a frame has measured it — and the tab provider and tree decorations PULL `in()`/`files()` when
they render. The editor paints the caret cell last in `LineSpans`, and the name
takes the trailing cell second after a debug value. A timer finds its entry by
id, so a rename that moved the entry still fades it.

An agent's edit never takes the user's screen: `OpenFocus::Background` opens
the file behind the active tab, and `DocumentReplacer::replaceRange` keeps the
buffer's caret and selection, shifted with their text.

## i18n and RTL

`I18n/Translator` wraps `symfony/translation`. **The English source string is
the message id**, so untranslated lookups fall back to English, with XLIFF
catalogs in `translations/` — and each plugin's own in
`plugins/<name>/translations/`, added by the loader through
`addCatalogDirectory()` (see `plugin-system.md`).

Adding a dir **rebuilds** the inner translator instead of adding a resource.
The parsed-catalog dump is named after symfony's `cacheVary` alone and
re-checks only the files it recorded, and the dump dir is shared by every
project on the machine — so a dir added to a live translator was served a dump
made without it. The dir set is part of `cacheVary` now, which gives each
plugin set its own dump. Resources are added plugins first and core last: a
later resource wins a shared id, and core's must.

RTL mirrors the layout (reversed pane order) and, via `Ui/BidiTui`, reorders
each composed row **per pane**: `Ui/PaneSegmenter` splits the row into each
pane's column run, and `I18n/PaneReorder` flips each run within its own span via
`I18n/Bidi`, a UAX#9 subset. The reorder never crosses a pane boundary. Code
panes marked `Ui/LtrPane` stay verbatim, and the set is pinned by a test — the
marker is empty, so nothing else would notice a pane leaving it. Mouse clicks
map visual → logical per pane before hit-testing.

Two limits worth knowing. The marker is whole-pane, so a verbatim pane's own
translated headings render left-to-right beside mirrored chrome; the results and
debug panels carry both. And `Bidi` implements W4 to W7 and N0 (bracket
pairs) but not W1-W3, and has no isolates — so a run whose edge sits between
opposite directions still needs a mark. To check it against the full algorithm,
feed the Hebrew catalog strings through `fribidi --rtl --nopad --nobreak` and
compare with `Bidi::reorder()`: on 2026-09-30 they agreed on 372 of 373 mixed
strings, the one difference being the arrows `Bidi` mirrors on purpose.

Where a run needs pinning, the **directional marks work**: U+200E and U+200F are
classified strong, measure no column, and `Bidi` drops each one on the way out.
A mark never stands in for a space: a gap beside one writes its own. The settings form puts
one either side of a row's CONTROL — a select's box, `[✓]`, a button are machine
data and would otherwise lose a delimiter to the paragraph direction. The detail
clause opens with a right-to-left mark for the same reason from the other side.
A label ending in other punctuation (`vendor/`) closes with a left-to-right
mark (`SettingsRowLayout::label()`); a parenthetical (`עיצוב בעת שמירה (LSP)`)
would need none, since N0 gives both brackets the side of what they hold. The detail
clause past the control is deliberately left OUTSIDE the marks: it is translated
prose and belongs mirrored, and wrapping it is what broke the scope row.

A two-column row needs a mark at BOTH boundaries: one to END the label's run and
one to begin the value's. A label whose own text ends in a Latin run — `שרת LSP`,
`הצג TODO` — is strong left-to-right just as the value beside it is, so without
the first mark the two merge and the value is drawn inside the label, taking the
column with it. `SettingsFormWidget`, `KeybindingsListWidget` and `MenuPanel`
each hand-roll such a row and each carries the pair. The chord in the last two
also gets a left-to-right mark AFTER it: a chord ending in punctuation (`Ctrl+,`)
otherwise loses the comma to the paragraph and reads `,+Ctrl`.
