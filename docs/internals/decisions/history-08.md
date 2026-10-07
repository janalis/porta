# Decision history, part 8

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## A mirrored frame's surfaces ride a layer of their own (2026-10-01)

A Hebrew audit of every screen went through 33 screens, each bare and then under
the File menu and a mid-bar menu, 226 frames in all. It checked row widths,
shadow placement, corner shapes, and every reordered row against fribidi (255
segments, all equal). It found one defect, on every screen: **an open surface
blanked a hole at its logical position.** With the File menu open, the editor's
left third was empty. Under a mid-bar menu, each line stopped short of the box.
Opposite a toast, the tree's top rows were gone.

`PaneReorder` blanked each surface's logical cells, reordered the panes, and
stamped the surface at its mirror across the row. That only lines up for a
mirrored pane spanning the whole row: part 5 already recorded that "the hidden
cells leave their gap at the MIRRORED span". A verbatim pane keeps the hole where
it was, and a narrow mirrored pane moves it within its own span. The text under
the surface's logical cells was overwritten at compose time, so no reorder could
bring it back.

**The fix composes the surfaces after the reorder.** Under an RTL locale,
`OverlayDelivery` and `MenuOverlayController` push spans to
`Overlay/State/FloatingLayer`, a blank screen-sized canvas, and the panes keep
only the backdrop dim. `PaneSegmenter::surfaces()` hands `BidiTui` the canvas
rows. `PaneReorder` no longer blanks anything (`blankRegions()` is gone) and
stamps each region from its canvas row. The LTR path is untouched.

Two follow-on edges came out of the audit re-run. A region claimed as a rectangle
takes cells no surface paints, and the canvas stamped them blank: the cell beside
the title row, and the one under the far corner. Regions now carry `firstRow` and
`lastRow`. A verbatim box moves as one block without flipping, so rows narrower
than the box were placed a column off; they now carry their `box`.

## Views open in a window, and every modal is one kind of window (2026-10-01)

Settings, Git and Review replaced the editor area, and while one was open the
menu greyed every item off the view's short list — the way to the other views
included. The round moved them into **a movable, resizable window over the
mounted editor**, opened centred at 80% of the workspace; made every floating
surface one kind of window; and moved the docs from an editor tab into a window
of their own.

**Hosting.** A view's region is a live widget tree, so three ways to float it
were weighed against the code:

| Way | Verdict | Why |
| --- | --- | --- |
| Off-tree, as Welcome renders its cards | rejected | `HitTester::rect()` answers null for a widget with no context, nothing joins the FocusManager, `resolveElement()` falls back to the default stylesheet (the theme is lost), and timers and render requests through the context go nowhere |
| A tree widget rendering the region in its own `render()` | rejected | a non-fill leaf renders in the vertical layout's measuring pass with position tracking off (`suppressStack`), so no rect is recorded; and it lands a frame late for panes already composited |
| Attached under a `hidden` host, rendered before each frame | **adopted** | the renderer drops hidden children before layout, so the frame never draws it; between frames the position stack rests at `[[0,0]]`, so `renderWidgetLines()` on a container padded to the window's origin records every rect at its screen cell — no router, divider drag or select list changed. A permanent spike (`ViewWindowPresenterTest`) fails if a vendor bump breaks either premise |

**The menu.** Every item stays enabled. `$viewSafe` survives with its meaning
turned round: not "what may run while the view is open" but "what runs over it";
any other command — from the menu, the palette or a key — closes the window
first, so its effect is seen (`ViewCommandGate`). The gate on keys needed a
list of the workbench's own commands (`ide_menu`, focus cycling, the palette…):
F10 closed the window it was pressed over until it had one.

**One window model.** `WindowFrame` replaced two copies of the drag state
machine (Welcome, Find in Files) and gave the pickers one: a title-bar move,
every edge and corner but the title bar's own edge as a resize handle, a click
beside a window closing it (Welcome used to swallow it). Pickers were
"fixed-centered, not draggable" only as a description; nothing settled it. They
keep the tallest-height rule — placed at their tallest, shrinking from the
bottom — and a resize sets that height.

**The close button is the platform's**: a red glyph leading the bar, title
centred, on macOS; a `☒` ending it elsewhere. The first macOS glyph was `ⓧ`,
which is a circled LATIN letter: strong left-to-right under bidi, and East
Asian **Ambiguous** — a terminal set to draw those wide pushed the whole title
bar a column out on the user's screen. `⊗` is Neutral and bidi-neutral;
`GlyphsWidthTest` pins both buttons to narrow. Under the pointer the button
darkens, with no fill: a red cell read as a hole cut in the frame.

**The docs** are a view window holding the page alone (the region's sidebar and
divider hidden), with a find bar of its own — the editor's sits behind the
window. No help tab exists any more, which also retired the tab list's and the
session's special cases for one.

## The two coverage passes run at once (2026-10-02)

`composer verify` ran its two coverage passes one after the other, though CI
already split them into two jobs because they share no state. Measured at the
defaults (8 processes, 12 shards) on a shared box at load 14–39, alternated
A/B/C/D then D/C/B/A:

| | run 1 | run 2 |
| --- | --- | --- |
| `coverage:src`, pcov over `.` (before) | 138.8s | 134.6s |
| `coverage:src`, pcov over `src` | 124.6s | 129.6s |
| `coverage:plugins` alone | 53.1s | 53.8s |
| both at once | 151.0s | 144.3s |

**Landed: `coverage:check` runs `coverage:parts` through `bin/keep-going
--concurrent`.** Every step starts at once, and each one's output is printed
whole, in chain order, once all have finished. Output stays deterministic, which
was the reason parallel static gates were declined. `VerifyGatesMatchCiTest`
follows a chain handed to `keep-going` the way it follows an `@` reference, and
fails if `coverage:plugins` is dropped from the chain.

**Landed: pcov watches only what each pass grades.** `coverage:src` used
`pcov.directory=.` (excluding `vendor` and `tests`), and the shards did too, so
each pass instrumented the other's code only to filter it out. With `src` and
`plugins`: `coverage:src` −10s (totals `28767/28767` both ways), and the plugin
pass 56.3 / 57.4s → 49.7 / 47.6s (`11811/11811` both ways). The bootstrap's
container-cache-off reason is untouched: it is about which files land in a
worker's data, not about pcov's window.

**The overlap found a race.** Two of six overlapped runs failed one shard,
`assist-hostfilese2e`, and the same shard both times. Run in a loop beside a
`coverage:src`, it failed 1 in 7. `HostFilesE2eTest` took the guest's first
`presence` message as Claude's, but the host's own caret is sent as presence too.
On a loaded box its first sample lands after the welcome and arrives first. The
test now skips the host's own caret, and 14 runs under the same load were green.
Five graded `composer verify` runs before the cache and test fixes: 172 / 135 / 149 / 145 (the red one above, before the fix) / 157s, against ~190s for the two passes alone before.

**Landed: `coverage:src` keeps the container cache on.** Measured at 8
processes, alternated: off 139.7s, on 92.6 / 85.3s (a second off run, 189s, was
taken at load 162 and is not counted). Both reports carried the same
`28767/28767` over all 897 `src` files. history-01 recorded two reasons for the
recompile. Neither has to hold for the gate now:

- *every `src` file lands in some worker's data*: `--require=src` fails any
  file absent from the report, and in a forced red, a copy of the report with
  `src/Console/Workspace.php` cut out passed without it (exit 0) and failed with
  it, naming the file.
- *every worker's PathReducer base collapses to `src`*, or the Merger
  (still `$first['basePath']` in php-code-coverage 14.3.2) drops a worker:
  dropped data can only remove coverage. A file that vanishes fails
  `--require`, and a line only that worker ran shows as uncovered. Either is red
  at 100%. A false green is what the old reason guarded against, and it can no
  longer happen.

**And `--require=src` fired.** In a graded run, `src/Widget/Support/DebugRowKind.php`
and `MatchJump.php` were absent from the report. The recompile had loaded every
class inside some test. With the cache on, a class first loaded outside
collection in every worker (by a data provider, say) never entered any worker's
pcov data. The gate was red rather than falsely green, but it was flaky.
`tests/Support/CoverageSweepExtension` brings back the first reason at the cost of one
compile per worker: under `PORTA_COVERAGE_SWEEP=1` (set by `coverage:src`
only, so `composer mutate`'s per-test map is not handed to one test), it loads
every `src` class in a coverage window of its own, opened at the first
`TestRunner\ExecutionStarted`. That event comes after coverage is initialised and
before any test opens a window. The first version swept inside the first test's
window, and review caught what that misses: PHPUnit discards a skipped, risky or
incomplete test's data, and a file compiled in a discarded window never enters
that worker's data again. A parallel coverage run over `tests/Unit/Help` with
the cache on reported 195 `src` files without the sweep and 897 with it. A run
whose only test skips reported 0 without it and 894 with it. The other 3 are the
classes `tests/plugin-autoload.php` loads before PHPUnit starts, and every real
run has them. A file whose path names no symbol stops the sweep, because
it would otherwise escape it silently.

The bootstrap's pcov sniff stays the default for scoped `composer coverage`
runs, which no gate grades.

**A coverage hole that was luck, not load.** Graded runs failed the plugin gate
on `assist/src/Net/Connection.php:238-239`, the drain branch of `linger()`, while
every test passed. `TransportTest` covered it by closing with a 600 KB message
"past what the socket takes at once". Over loopback TCP it is not: the buffers
autotune, and a fresh socket took between ~330 KB and all 600 KB at once (all of
it in 85 of 200 tries). Then nothing was pending and nothing drained. The first
fix, sending filler until a backlog existed, failed again in a graded run. Between
the last filler and `close()`, the kernel moves bytes to the peer's receive buffer
(an unread pair absorbs up to 3 MB). The flush inside `close()` then empties the
backlog, and the drain hook it fires is the constructor's, not `linger()`'s.
The test now uses a Unix socket pair, which holds a fixed 8192 bytes here in 200
of 200 tries (~200 KB on Linux), with a 1 MB message. It asserts the backlog
after `close()` as its precondition, and it reads from a loop watcher instead of
between `await` turns. The branch was covered in 10 of 10 runs at load 43–78.

**And its sibling in `TunnelTest`.** `testReadingWaitsWhileTheSessionIsBehind`
queued 3x `Tunnel::HIGH_WATER` toward a peer that does not read, then asserted
that reading was held back after `pump(0.2)`. At load ~200 it failed 1 in 26:
the pair absorbed enough during the pump to bring the backlog under the mark, so
reading rightly resumed. It now queues 6x, under the writer's 8 MiB limit, and
asserts the backlog is still past the mark after the pump, so a recurrence names
its cause. After the fix it passed 31 of 31 under the same load.

**Where it ended.** The final tree, graded as five consecutive `composer verify`
runs: all green, 182.8 / 205.1 / 197.5 / 155.0 / 137.9s, while another
project's test runs held the box at load 196–324 on 16 cores. HEAD's chain,
interleaved with new runs at comparable load (166–224): 302.6 / 339.6s,
against 147.8 / 164.6 / 170.1s for the new chain. Roughly half the wall clock,
and both reports were whole every time (`28767/28767`, `11811/11811`).

*Re-opens on:* a shard that fails only when the passes overlap and passes the
same loop alone. Then the overlap is costing correctness, and
`PORTA_SHARD_PROCESSES` is the knob to lower before the overlap goes.

## Opening a project no longer runs it (2026-10-02)

A security audit swept three surfaces: the network-facing code, opening an
untrusted project, and terminal and file handling. The network edges held.
Binds are loopback-only, every token check is `hash_equals`, assist pairs over
SPAKE2 with certificate pinning, secrets are stored 0600, and every
`proc_open` takes an argv array. Opening a clone did not hold:

- `.porta/plugins/*/plugin.php` was `require`d at boot;
- `.porta.json` named `plugins.git.path` and `lsp`, both spawned before any
  keystroke;
- a committed `vendor/bin/phpstan` ran on save;
- phpstan, psalm and php-cs-fixer execute project PHP through their own config
  files, whichever binary reads them.

The fix is the trust gate (decisions.md, Plugin API). The same round fixed what
the sweeps found beside it, one commit each:

- New File truncated a taken name.
- A malformed escape in run output froze the pane and grew memory without bound.
- `str_contains($host, 'gitlab')` handed `GITLAB_TOKEN` to any look-alike host.
- A PNG decompression bomb was decoded before its size was checked; libgd
  allocates outside `memory_limit`, so only the header can stop it.
- assist: a dangling symlink let a guest write outside the project, a read-only
  guest could fetch a gitignored `.env`, and a guest could set the host
  server's `initializationOptions` (symfony-lsp's name a PHP command).
- LSP `WorkspaceEdit` create, rename and delete reached anywhere on disk.
- `.porta/error.log` was written through a symlink.
- Local-history snapshots of 0600 files were left 0644.
- Downloads followed redirects to plain http.

The review of the round found the gate thinner than its claims, and each
claim was then made true:

- A text edit to a file nobody has open is now kept inside the project too, and
  a `..` path is refused before the disk is consulted. `new/..` used to judge
  as inside during validation, then delete the root once `new` existed.
- git reads no attributes for an untrusted repository (`GIT_ATTR_SOURCE` = the
  empty tree), because a `.gitattributes` filter's `clean` runs on a status
  that re-hashes a file.
- A guest may move, overwrite or delete only what the host offered. Renaming
  `.env` to a listed name was fetching it in one more step.
- The relay answers the server's `workspace/configuration` itself, and drops
  `didChangeWorkspaceFolders`.
- A pinned review `provider` picks the forge, never the host.
- mcp's `allow…` flags and any `…Dir` setting are runnable keys too.
- Trust/Restrict judge the session's `IdeConfig::$trusted`, not the file, and
  do not reopen under `PORTA_TRUSTED_FOLDERS`.
- An over-cap OSC is swallowed to its terminator, not printed.
- symfony-lsp's `runtime-indexing` stays off untrusted, even when on globally.

Weighed and left alone:

- Any guest may read debugger variables. That is documented and tested
  ("Any guest can look").
- The MCP lock file is briefly 0644 before its chmod, but `~/.claude` is 0700.
- The mcp socket pointer has no owner check; a test cannot make a socket owned
  by another user.
- An OSC 8 link's URI body passes `AnsiText::inert()`.
- Downloads are not signature-checked; that needs signing infrastructure.
- Trust is still read through `WorkspaceTrust::fromEnvironment()` in the
  config loader, the LSP locator, the legacy-key adopter, git and the tool
  base. They run before the container exists, or are plugin code nothing is
  injected into. Each reads it once per session (the tool base memoizes), so
  one session cannot disagree with itself.

**`TunnelTest`'s catch-up was never load.** `testReadingWaitsWhileTheSessionIsBehind`
kept aborting at the 10 s limit, 2 in 5 runs alone at load ~10. A trace showed
every read returning exactly 8192 bytes: a non-blocking `fread` hands back one
chunk however much it is asked for, and the await read once a turn — about
1000 turns for the ~8 MB queue the 6x fix had made. That fix had made the
abort likelier, not rarer. It now reads until the socket is empty each turn,
and runs in ~1.2 s. `TalksAssist::drain()` reads the same way and was left
alone: its inbox is shared by every peer a test swaps between, and draining
eagerly moved one guest's messages into another's
(`HostServersE2eTest` failed at once). Making that helper read eagerly needs a
per-peer inbox first.


## Problems and inlay hints the PhpStorm way (2026-10-07)

A round asked to make diagnostic messages and inlay hints behave as PhpStorm
does, checked against its documentation rather than memory:

- **PhpStorm writes no message after the code.** Inline problem text there is
  third-party plugins' (Inspection Lens, InlineProblems); JetBrains shelved the
  request. A problem is read from the card on its squiggle (message, `Fix:`
  with Alt+Shift+Enter, More actions with Alt+Enter), from Ctrl+F1, and above
  the docs in Shift+F1. So `diagnosticMessages` now defaults to `none`, and
  Porta has all three. `inlineDiagnostics: true` still maps to `inline`: it was
  an explicit ask for the message.
- **Hints are pills, kept apart by kind**, and a parameter's name shows only
  before a literal, as PhpStorm's default does. `kind` had been dropped by the
  mapper, so the two could not be told apart at all.

**The wrap rule for hints refines history-01's "measure the document line".**
Rows are still measured on the raw line and text never reflows for a hint.
What changed is where a hint may go: segment k shows exactly the raw columns
`[k·w, (k+1)·w)`, and hints are placed only into the cells a segment's text
leaves free, greedily, left to right (`Editor/InlayLine`). Only the last
segment of a wrapped line has free cells, so a hint on a full middle segment
is not drawn. Before, a hint pushed the segment's tail into the next row's
slice while the row count stayed the same, and the end of the line clipped
out of view.

**One column mapping.** Only the caret knew about hints. A click, a drag and a
hover mapped the raw screen column, so they landed off by a hint's width, and
the spans of a window scrolled past a hint were clipped by the screen column
instead of the character it shows. `InlayLine` is now the one placement and
mapping (`screenCol`, `rowStart`, `hit`), and `EditorMouse::pointAt()` is the
one entry every pointer path takes. A cell on a hint answers the character it
sits before.

**The card's fix is asked lazily.** `Lsp/QuickFixProbe` sends one
`textDocument/codeAction` for the problem under the pointer, on the next loop
turn (a pointer route can run inside a frame hook). It keeps the answer until
the file's problems change, and announces only the problem asked about last:
the pool has no `$/cancelRequest`, so a sweep across squiggles leaves earlier
answers cached and silent. `TooltipCenter::refresh()` updates a card in place
when its key still matches, and never brings back one that was dismissed.

## The gutter stops repeating problems; a problem read elsewhere opens in Problems (2026-10-07)

The line-number gutter coloured a problem line's number by severity, drew its
glyph in the marker column, underlined the number when a breakpoint or bookmark
took that column, and said the message on hover. The user asked for none of
it: a problem is the squiggle's, and its card is on the code. The marker column
stays reserved — breakpoints, bookmarks, comments and the stopped line use it.
history-01's bookmark-over-diagnostic precedence is moot.

A problem read in the status bar or as a row of the Alt+Enter list (`menu`
mode) opens in the Problems panel on its row. One command event,
`ProblemRevealRequested`, which `SearchActions` alone handles: show Problems,
then `BottomDock::selectInList` with the row's text, so the right one of
several problems on a line is selected. A note row in a list may now carry
`open`: a click on it closes the list and runs it; the selection still skips
notes, so Enter never does.
