# Decision history, part 1

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

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
widget exposes one `decorations` property (a getter until the 2026-09 accessor round), and a future decoration costs the
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
  **Corrected 2026-09-15: the floor was the SCHEDULE, not setup.** The step
  keys summed to 194s over 456 scenarios (mean last key 426ms), and steps chain
  by key delta, so every gap was wall time. Capped to 0: `composer test` 109 /
  116s → 86 / 89s, alternated. testing.md has the 53 tests it exposed.
- **Unit is the mirror image**: 157 cases (2.9%) are 67% of its time, clustered
  in ~10 classes that really sleep — 70 `sleep`/`usleep` call sites.
- **Re-checking `await()` on a backoff instead of a flat 0.02s turn** — measured
  and turned down. The reasoning that motivated it was sound and the arithmetic
  was not: 912 await sites × one wasted 20ms turn looked like ~18s of suite
  time, but `await()` checks its condition BEFORE its first turn, so the common
  case already costs zero turns, and a call that does wait is waiting on a
  subprocess spawn or a language-server round trip whose latency dwarfs 20ms.
  Four alternated serial runs of a (0, 1, 2, 5, 10, 20ms) backoff:
  `tests/Unit/Support` 22.8 / 22.3s → 22.1 / 22.3s, `IdeLspFeaturesTest`
  11.3 / 10.9s → 10.7 / 10.5s, `IdeTest` inside its own noise. At six processes
  it was WORSE, one E2e run 13.7s → 24.9s: `awaitPainted`'s condition forces a
  full synchronous render, so a tight re-check converts the spare wall time this
  suite has (3.5 of 16 cores) into CPU contention it does not. What DID land
  from the round is the honest budget — see the turn-counting row below.
  Re-opens if a profile shows a barrier whose condition is cheap dominating a
  suite, and then it is that barrier's floor, not the primitive.
- **`await()`'s budget was counted, not measured** — `$elapsed += 0.02` per turn,
  so under load, where a turn takes longer than its delay, "timed out after
  10.0s" could be a 30s wait. That is not a rounding error, it is the loss of
  the one diagnosis worth trusting: a test that burns its FULL budget lost an
  effect, it did not race, and a number that is not the number enforced cannot
  support that reading. Now `hrtime()`-measured. Measuring TIGHTENS every
  deadline in the suite, because the old count silently absorbed slow turns —
  most of all under pcov, where 500 turns of 40ms were 20s and not the 10 the
  message claimed — so the coverage allowance is stated (×3, on the
  `pcov.enabled` sniff bootstrap.php already uses) rather than inherited by
  accident. `pump()` is measured for a sharper reason: it stands in for a real
  clock (a double-click window, a debounce), so `pump(0.4)` must be 0.4s of wall
  time and not twenty turns of unknown length.
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
