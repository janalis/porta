# Decision history, part 6

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## Freeze the row instead of delegating to the terminal (2026-09-12)

The isolate did not work, and the screen said so precisely. The menu bar came
back as:

```
הרץ תוספים קוד ניווט עריכה קובץ │ Git │ עזרה תצוגה
```

Every Hebrew GROUP reversed correctly and no group moved. That is the signature
of a terminal reversing right-to-left runs IN PLACE and never reordering them —
so the isolate's embedding level was not honoured, whatever else it did with
`U+2067`.

Which settles the strategy: if the terminal will not reorder runs, Porta cannot
delegate layout to it at all. Porta keeps the layout it already computes, and
the row is FROZEN so the terminal cannot lay it out again.

`U+202D … U+202C` — a left-to-right OVERRIDE — is the strongest statement in the
algorithm: every character inside is treated as strong L, so nothing reverses
and nothing reorders. Unlike an isolate it asks the terminal for no embedding
arithmetic, only that it honour an override.

Verified by running a UAX#9 reorder (overrides, strong types, N1/N2, L2) over
the rows Porta actually emits, from the real-pty frame dump: all forty come back
IDENTICAL. And the check was audited rather than trusted — 67 of 67 rows carry
the override and zero rows containing Hebrew go unfrozen, so "unchanged" is not
the vacuous result of a row having no Hebrew in it.

### Three attempts, and why the failures were informative

1. **Marks at the pane edge.** Ends a run; says nothing about layout. The row
   stayed left-aligned.
2. **Logical order, terminal mirrors.** Made the Hebrew readable — a real step —
   and left the layout wrong in two different ways depending on whether the
   row's first strong character was Latin or Hebrew. Both were reported, in that
   order, which is how the rule became visible.
3. **Right-to-left isolates.** Correct in the specification, not honoured here.

Each was disproved by a screen, not by argument, and each narrowed what the
terminal actually does. Only the third could have been predicted from the
evidence available at the time, and it was not.

### A fixture that hid the case

The freeze SPENDS a padding cell at each end of a pane. `BidiTuiTest`'s shared
widget renders a bare word with no padding and no layout to pad it — the one
shape that cannot be frozen. Padding it broke a neighbouring click test, which
is the usual fixture trap; the freeze test now builds its own full-width row
instead. Every real pane has padding: measured, 67 of 67.

## The last frame is left in .porta/frame.txt (2026-09-12)

Five rounds of this were argued from photographs of Hebrew glyphs, and at least
three of my readings of those photographs were wrong. The instrument existed
behind `PORTA_FRAME_DUMP` and was never used, because it needed switching on
first.

Porta now REMEMBERS the last composed frame and writes it to
`.porta/frame.txt` at shutdown, beside `error.log`, `freeze.log` and
`perf.log`. Reproduce, quit, and the evidence is there with nothing to enable.
A screenshot says what the TERMINAL drew; that file says what Porta COMPOSED,
and a disagreement between them names which half is at fault.

The cost is one `array_map` per frame and one write at exit.
`PORTA_FRAME_DUMP=<path>` still writes every frame for watching a session live.

### Where the menu bar stands

The freeze is working: the glyphs on screen are in Porta's VISUAL form
(`צבוק`, not `קובץ`), so the terminal is no longer re-reversing characters.
What the reporter still sees is an item ORDER that does not match what
`Bidi::reorder()` produces for the same input — tested directly with the
reporter's own item set including `Git`, it returns
`קובץ | עריכה | ניווט | קוד | תוספים | הרץ | Git | תצוגה | עזרה` read
right-to-left, which is correct.

So either the row Porta composes there differs from that string, or something
downstream still reorders. That is exactly the question `.porta/frame.txt`
answers, and it cannot be settled from here: the git plugin does not load in the
scratch fixture, so the reporter's menu bar cannot be reproduced end to end.
Recorded as open rather than guessed at again.

## The freeze belongs to the ROW, not to the panes (2026-09-12)

`.porta/frame.txt` earned its keep on its first use. The reporter narrowed it to
"it's the dropdown that is broken", and a frame captured with the app menu open
showed exactly what that meant:

| row | overrides | box columns |
| --- | --- | --- |
| 1, 3, 5 | 0 | 189..225 |
| 2, 4, 6 | **3** (at 176, 199, 200) | 188..222 |

Alternate rows of one dropdown had their border a column apart, and three
override characters were sitting INSIDE the box.

The per-pane freeze was wrong twice over once a floating surface was on the row.
It ran AFTER the dropdown had been stamped on but spent cells by PANE geometry,
so it wrote overrides into the dropdown's own cells and shifted its border; and
on the rows where the dropdown reached the last column there was no trailing
padding, so the pane was skipped and those rows got no freeze at all.

A freeze has no business knowing about panes. One `U+202D` at column zero, and
no pop: an override runs to the end of the paragraph and a terminal treats a row
as a paragraph, so the whole composed row is frozen whatever is on it. It costs
ONE cell instead of two per pane, and it cannot disturb anything after column
zero.

Re-measured from the same capture: every row carries exactly one override, every
row the same width, and the box aligned at 188..224 on all of them.

### Two things the rewrite nearly lost

- The widgets' own direction marks were being spent by the old method. Dropping
  it silently cost every settings row a column; the test for it failed, which is
  the only reason it came back. It now happens in `freezeRow()`.
- `BidiTuiTest`'s fixture needed TRAILING padding, not leading: the freeze runs
  after the mirror, and mirroring moves a row's trailing padding to its front.
  The first attempt padded the wrong side and proved nothing.

### Not the dropdown

The stray `×` beside the box is the right-hand tab strip's close button, not
part of the dropdown: in the LTR reference frame it sits at column 224 while the
box occupies 0..35. Its RTL placement is a separate question and is not claimed
as fixed here.

## Sweeping the rest of the app for the same defect (2026-09-12)

With the dropdown fixed, the question was what else carried it. Rather than wait
for reports, every surface was driven in a real pty at 226x67 under a Hebrew
locale (actions bound to `alt+<letter>` through `.porta.json`'s keymap, since
most of their real chords are not encodable without the kitty protocol), and its
`.porta/frame.txt` graded on three invariants:

- every row exactly the terminal width,
- no row carrying Hebrew without an override — such a row is one the terminal
  will rearrange,
- never more than one override on a row.

Swept: editor, app menu, settings, git view, help viewer, find in project,
command palette, problems panel, terminal panel, notifications, structure, local
history, search everywhere, todo, context menu. Fifteen surfaces.

**One real defect**, in the help viewer: it renders prose against a left-hand
border, so column zero is never padding and those rows went out unfrozen.
`freezeRow()` now falls back to the first padding cell that still precedes the
row's first right-to-left character — an override only governs what FOLLOWS it,
so a later cell is only useful while the text has not started. A row whose
right-to-left text beats its padding is left alone and recorded as a known gap:
growing the row past its pane is this renderer's worst failure and is not worth
trading for.

### A metric that lied first

The first pass flagged the editor and the app menu as BAD on "uniform row
width". They were not: a frozen row measures one column less once the override
is stripped, so a frame with some rows frozen and some not looks ragged while
being correct. Counting the override as the cell it occupies, both were fine.
The invariant that matters is the second one — Hebrew without an override — and
it was the only one that ever pointed at a real defect.

### What guards it now

`IdeRtlLocaleTest` asserts the freeze across the editor, the settings view and
the help viewer. The gutter fallback is NOT guarded there — an injected "give up
if column zero is taken" survives that test, because at the E2e's width those
rows still begin with padding. It is guarded by the unit case, which the same
mutant fails. Recorded rather than left to look covered.

## Two open items closed: one was not a defect, one was (2026-09-12)

### The dropdown's column, retracted

Recorded twice as "the box ends one column past its title". Measured properly in
both directions:

| | box edge | nearest menu-item glyph | offset |
| --- | --- | --- | --- |
| left-to-right | left = 0 | 1 | −1 |
| right-to-left | right = 224 | 223 | +1 |

The box aligns with the menu item's CELL, which carries a leading space, not
with its glyphs — and the right-to-left case is the exact mirror of the
left-to-right one. There was never anything to fix. It was flagged from a single
measurement with nothing to compare against, which is how a correct behaviour
gets recorded as a bug for two rounds.

### The tab strip, real

Sweeping the surfaces turned up the editor itself. With `tabBarPosition: right`
under Hebrew, the layout came out `[editor][strip][tree]` — the strip stranded
between the editor and the file tree instead of at the far left.

`PaneLayout::applyDirection()` reverses the editor area's panes, but a SIDE tab
strip is not one of them: it is seated inside the editor pane by child order, in
`WorkspaceLayout::seatTabBar()`, which knew nothing about direction. Child order
is physical — first child, leftmost column — so a right-hand strip must become
the first child once the workspace mirrors.

`TabBarPosition::mirrored()` swaps the two sides and leaves top and bottom alone
(mirroring is horizontal), and `WorkspaceLayout::setMirrored()` re-seats. Wired
from `applyDirection()`, so it follows a live locale switch and not just boot.
Measured after: the strip at columns 0..25 with its close button at column 0 —
the exact mirror of 224 in the left-to-right frame — and the tree at 200.

Zero escaped mutants over the diff.

## The RTL sweep, widened (2026-09-12)

The first sweep was one geometry and one configuration. Widened, because a bug
that only appears at 226 columns with the tabs on the right is not a bug that has
been looked for.

| | overrides | rows exact width | Hebrew rows unfrozen |
| --- | --- | --- | --- |
| LTR 226x67, tabs right | **0** | yes | — |
| LTR 120x40, tabs top | **0** | yes | — |
| RTL 226x67, tabs right | 67 (one per row) | yes | none |
| RTL 120x40, tabs left | 40 | yes | none |
| RTL 80x24, tabs top | 24 | yes | none |

Plus the settings view, the app menu, the git view and the help viewer at 80x24
and 120x40 — the narrow widths, where clamping and the "no padding to spend"
path are reachable.

The LTR rows matter as much as the RTL ones: **zero** overrides means the
left-to-right path is untouched by any of this. `BidiTui::processRender()` skips
the whole mirrored branch when no reorder is installed, so the freeze cannot run
there — measured rather than assumed, since that is the path almost every user
is on.

Full suites after the change: 6024 unit, 401 E2e, 40 integration, plugins at
100%. Zero escaped mutants over the diff.

### The sweep finished: nineteen surfaces

The remaining ones — run panel, debug panel, welcome modal and the settings
keybindings page — are clean too: exact row widths, one override per row, no
Hebrew row left unfrozen.

One harness lesson. The run panel first reported NO FRAME, which reads as a
crash; it was the sweep's own 25-second timeout cutting the session off before
shutdown could write the file. Re-run with room, it was fine and its error log
was empty. A missing artifact is not evidence of a failure until the harness has
been cleared of producing it.

### Nothing else in the logs

The round began where this project says to begin — the user's own diagnostics —
and neither points anywhere:

- `error.log`: two entries, both 2026-09-09, a `ClockInterface` locator miss
  that no longer reproduces.
- `perf.log`: 371 entries today, paint p50 between 0.4ms and 3.1ms. Healthy.
- `freeze.log`: 121 loop stalls, up to 12.68s — and **120 of them inside a
  single hour**, 2026-09-11 15:00, which is exactly when `composer verify` runs
  had this machine at load 32. Every backtrace is a `stat` on the event loop
  (`is_file` in `Buffer::diskStampNow()`, `is_dir` in
  `FileTreeWidget::refreshIfChanged()`), including one inside the watchdog's own
  `DiagnosticLog::append()`. A twelve-second `stat` on a local SSD is not
  filesystem latency, it is a process that was not scheduled. Attributed to
  contention rather than turned into a fix — see [[e2e-flake-under-machine-load]].

## External file edits were never detected (2026-09-12)

Found by following `.porta/freeze.log` rather than by looking for it. The stall
backtraces all land on `is_file()` in `Buffer::diskStampNow()` and `filemtime()`
in `FileTreeWidget::dirChangedSince()` — which turned out not to be the defect
(see below), but reading that code closely turned up a worse one.

**PHP caches stat results per path for the life of the process, and clears them
only for writes IT performs.** Neither poll cleared the cache, so:

- a buffer's disk stamp answered with whatever it saw the first time, and
- the tree, which trusts an unchanged directory mtime older than its two-second
  grace window without a `scandir`, trusted every directory forever.

Measured before fixing, with a separate writer process: three seconds of polling
and no detection, on both paths. An edit by another editor, a formatter run, a
`git checkout` — none of it was seen.

### Why the suite never caught it

Every existing test writes through `TempProject::write()` →
`Filesystem::dumpFile()`, which is IN-PROCESS and clears the cache as a side
effect. The feature therefore worked perfectly in tests and not at all in life.
[[porta-fixtures-overstate]] again, in a new shape: this time the fixture was not
a mock but the WRITE ITSELF.

Both new tests drive an external writer through `proc_open()`, which measurably
leaves the cache intact. Removing either `clearstatcache()` fails them.

### A benchmark that stopped a regression

`diskStampNow()` makes three calls — `is_file`, `filemtime`, `filesize` — and
collapsing them into one `stat()` looked like an obvious saving. Measured, A/B/A/B:

| | 3 calls | one `stat()` |
| --- | --- | --- |
| cache cleared | 115.7 / 116.3ms | 121.0 / 120.8ms |
| cache warm | **2.6ms** | 8.4ms |

The second and third calls are cache hits; `stat()`'s array construction costs
more than they do. Three calls stay three calls, and the reason is now in the
docblock so it is not "optimised" again.

### The stalls themselves are not a defect

121 loop stalls, but 120 inside one hour — 2026-09-11 15:00, when `composer
verify` runs had this machine at load 32 — and the traces show a `stat` on the
event loop, which is a process that was not scheduled rather than a slow disk.
The slicing added earlier is working: the traces come through
`pollFilesystemSlice()`, which bounds how MANY stats a tick makes, and cannot
bound how long one takes. Attributed to contention.

### Correction: that entry over-claimed (2026-09-12)

The entry above says external edits "were never detected". **That is false in
practice, and the claim was published before it was checked end to end.**

PHP's stat cache is effectively SINGLE-ENTRY: stat-ing any other path evicts the
previous one. Measured:

| | result |
| --- | --- |
| path A after an external change, nothing else stat-ed | **stale** |
| path A after stat-ing one other path | fresh |

The running IDE stats many paths every tick — every polled tree directory, the
logs — so a buffer's entry is always evicted before the next poll. Driven end to
end in a real pty, with an external process editing the open file six seconds in,
the reload happens **with or without** the `clearstatcache()` calls. The unit
tests fail without them only because they stat one path and nothing else.

So this fixed a LATENT hazard, not a live bug. Keeping it, for one reason that
is not "defensive code is nice": the reconciler promises to notice external
edits, and that promise currently rests on the accident that something else
happens to stat in between. Nothing in `pollSlice()` guarantees it. The cost is
5.8µs per stamp against 0.13µs — about 93µs per second at four buffers and 4Hz.

The two tests now say what they actually demonstrate rather than describing a
user-facing failure that does not occur. The lesson is the one this codebase
keeps teaching: a failing unit test proves the unit fails, not that the product
does. The end-to-end check was cheap and I ran it only after writing the claim.

## The real SIGWINCH path, verified (2026-09-12)

`IdeRtlLocaleTest::testRtlSurvivesATerminalResize` resizes through
`VirtualTerminal::simulateResize()`, which sets the size directly. Nothing
exercised the path a user actually takes: the kernel delivering SIGWINCH because
the terminal window changed.

Driven for real — a pty whose MASTER this side owns, resized with `TIOCSWINSZ`
six seconds in, Hebrew locale, frame graded from `.porta/frame.txt`:

| | rows | widths | Hebrew rows unfrozen |
| --- | --- | --- | --- |
| 140x34 → 96x28 | 28 | all 96 | none |
| 80x24 → 200x50 | 50 | all 200 | none |
| 200x50 → 60x20 | 20 | all 60 | none |
| 120x30 → 40x12 | 12 | all 40 | none |

Including the shrink to 40x12, where the sidebar collapses and a side tab strip
is rescued to the top. No defect: the resize path, the exact-width contract and
the right-to-left freeze all hold through a real resize.

### Two harness traps, both of which faked a bug

Worth recording because each produced a convincing false positive, and I nearly
reported the second as a defect.

1. **`stty` from a background subshell does nothing.** It exits silently — no
   error, empty `stty size` — and the terminal keeps its old dimensions. The
   first run "showed" Porta ignoring a resize when no resize had happened.
2. **A child in a new session gets no SIGWINCH.** `posix_setsid()` (Python's
   `os.setsid`) detaches from the controlling terminal, so the kernel has
   nothing to signal. `TIOCSWINSZ` on the master then changes the size and the
   child never hears. The fix is `TIOCSCTTY` on the slave in the child, after
   `setsid`: with it the same run resized correctly.

Both look exactly like "the application ignores resizes". Neither is.

## A save could silently discard another writer's work (2026-09-12)

Reproduced end to end before it was written down. A second process rewrote an
open file while the buffer held unsaved edits; Porta flashed "changed on disk"
and did NOT reload, which is right. Two seconds later, Ctrl+S:

```
file on disk after the save:
   contains the colleague's work : False
   contains my edit              : True
```

Their line was gone, with no warning at save time. The IDE knew — it had said so
— and the knowledge did not survive the two seconds to the keystroke that
mattered.

`FilesystemReconciler::pollBuffer()` records the new stamp when it notices a
change ("so we react once"), so by save time the buffer's stamp MATCHES disk and
`saveBuffer()` has nothing to compare against. The fact has to be carried
deliberately: `Buffer::$diskChangedUnderEdit`, set beside that flash, cleared by
a reload (the buffer then IS the disk) and by a successful save.

`saveBuffer()` now refuses the first Ctrl+S on a file someone else wrote to and
says so, naming the file; a second press within three seconds goes through. That
is the shape `WorkbenchActions::quit()` already uses for unsaved buffers — a
flash and a confirming second press — rather than a modal nothing else in this
app has. Verified end to end: one press keeps their work, two presses write mine.

### What the tests had to be taught

- The refusal must SAY so. A save that silently does nothing is its own data
  loss, so the toast text and the file name are both asserted.
- The confirmation is a WINDOW, not a latch: a press arriving long after the
  warning has scrolled by warns again instead of overwriting on the strength of
  something the user has forgotten.
- A reload must SPEND the warning, or an ordinary Ctrl+S refuses once for no
  reason and teaches people to press twice out of habit — which defeats the
  guard entirely.

That last test failed first for a reason worth keeping: the stamp is
`mtime:size`, and `// v1` → `// v2` is the same length in the same second, so
nothing was detected at all. Two writes a test means to distinguish must differ
in LENGTH, not just content.

Two mutants survive on the three-second window (`<=` → `<`, and the literal).
Both need a controllable clock, and `BufferService`'s is a readonly promoted
property that reflection cannot replace after construction. Recorded, not
claimed.

## The copy that ignored the answer it had just asked for (2026-09-13)

Same sweep as the save guard above — "check everywhere else" — and the same
shape of defect: a permission the code asks for and then does not honour.

Dropping files onto a folder whose name is already taken raises the overwrite
prompt. `TreeOperations` carries that answer as `$overwrite` all the way down to
`moveOne()`, where the MOVE branch honours it. The COPY branch read it, passed it
to nothing, and always called

```php
$this->fs->copy($source, $target, overwriteNewerFiles: true);
```

so the one path that reaches the copy without an answer destroyed a file
silently. That path is narrow, which is why it survived: choose `Rename…`,
and type a name that is ALSO taken. The prompt has been answered — about the
FIRST collision — and nothing asks again about the second. A move refuses there
by accident, because `rename()` throws onto an existing path; a copy overwrites,
because `copy()` is defined to.

The fix is one guard at the top of the copy closure, throwing `IOException` so it
lands in the `withFs()` wrapper already there and reports through the same
`Cannot copy %file%` toast as every other copy failure. No new message, no new
prompt: the user already answered a question about a name, and the honest
response to a name they did not answer about is to refuse and say why.

### Why the second half of the first draft came back out

The draft also threaded the flag into `copy()` itself
(`overwriteNewerFiles: $overwrite`). That reads careful and is unkillable: past
the new guard either the target does not exist — nothing to overwrite — or the
answer was yes, and then a target NEWER than its source is precisely the one that
must still be written. Two spellings of one decision, one of which no test can
ever distinguish. The flag went back to `true` and the comment says why, so the
guard is the whole permission.

### Grading it

`composer mutate` generates ZERO mutants for this diff: the case is an E2e test
and infection runs `--testsuite=Unit`. So the guard was graded by hand instead —
`!$overwrite &&` → `false &&`, and the test fails on the assertion it should,
`'Copied other.php'` where `Cannot copy` was required.

That assertion is the test's second draft. The first passed with the guard
reverted, because `overwriteNewerFiles: true` on its own already spared a target
with an equal mtime; it was asserting the filesystem, not the refusal. A
data-loss test has to assert BOTH halves: the neighbour file still holds its own
bytes, and the IDE said out loud that it would not write.

## perf.log blamed the terminal for Porta's own mirror (2026-09-13)

This round started by reading the user's own `.porta/perf.log` and nearly ended
with the wrong subsystem. 433 of its ~1020 beat lines carry `writeMs p50` at 5ms
or worse, peaking at **38.2ms**, in contiguous ten-second stretches — and
several of the worst have `renderMs p50=0.0` beside them. A frame whose widget
tree cost nothing and whose terminal write cost 35ms reads as one thing only:
the tty stopped draining.

It had not. `pend=0` on those same lines, and the real answer is that `writeMs`
was never only the write.

### What the number actually contained

`BidiTui` stamps `$t0` before `Renderer::render()`, `$t1` after it, and `$t2`
after `ScreenWriter::writeLines()`. When the RTL work landed in that method, it
landed BETWEEN `$t1` and `$t2` — `segment()`, `segmentRegions()`, and a
`reorderRow()`/`freezeRow()` pair per row. All of it was reported as terminal
write time.

Measured, at the user's own 226×67 with their own frame as input
(`.porta/frame.txt`, padded to width, three pane runs), A/B/A/B:

| stage | ms per frame |
| --- | --- |
| `reorderRow` × 67 rows | 7.9 |
| the same plus `freezeRow` | 8.2 |
| of which `AnsiUtils::sliceByColumn` (201 slices) | 4.1 |
| of which `Bidi::reorder` | 2.2 |
| the borrowed-caret `strpos` scan | 0.005 |

So ~8ms of every "terminal write" in a Hebrew session was Porta mirroring the
frame, and `slow-paint render=Xms write=Yms` — whose whole purpose is to name
the layer — named the tty for work the tty had not done. The comment above that
line said "write-heavy = the tty". It was wrong in exactly the subsystem that
has now cost five rounds.

`bidiMs p50/p95` is now its own pair in the beat line, `bidi=%.0fms` its own
field in `slow-paint`, and the frame total is the sum of three stages.

### What it does NOT excuse

The 8ms is real, and an RTL frame therefore costs ~13ms against a 16.7ms cap —
half the LTR headroom. That is a separate finding and is deliberately left as
one: it wants a benchmark and its own commit, not a speculative edit inside the
round that discovered it.

### Two candidates measured and dropped

- **The always-on frame sink.** It runs `array_map(stripAnsiCodes, $lines)` on
  every frame to keep one copy for shutdown, which looked like exactly the kind
  of self-inflicted per-frame cost worth reverting. Measured on 67 SGR-dense
  226-column rows: **0.061ms**. Not a cost. It stays, and the timer boundary
  puts it on the write side of the split with a comment saying why.
- **A third boundary around the sink.** Refused for the same number.

### The guards that could not fire

Splitting the window added a fourth timestamp, and mutation put the old shape on
trial: SEVEN escaped mutants, every one on a
`null !== $tN && null !== $tM ? … : 0.0` pair. They escaped because a mixed
state does not exist — all four stamps are read under one `null !== $clock`,
so either all are set or none are. Dead guards, and the pairwise form hid that
until a mutant asked.

They are gone: an unclocked rig stamps `0.0` four times, every difference is
`0.0` on its own, and the three splits are asserted individually — the total
alone was satisfied by one stage a second long and the next a second short,
which is precisely what the surviving `OneZeroFloat` mutants did.

## The RTL mirror is memoised per row (2026-09-13)

The finding the entry above left open, closed with a number. `bidiMs` existing is
what made it measurable: booting a real Ide over an `he` project at 226×67 and
driving 30 forced frames, the mirror is **2.96ms a frame** against 0.17ms of
widget tree and 0.10ms of terminal write. In a right-to-left session the mirror
IS the frame — 95% of a steady-state repaint.

Nothing about that work is per-frame. `reorderRow()`/`freezeRow()` are pure
functions of (line, pane runs, floating boxes), and a repaint changes a row or
two: the differential writer one stage downstream is built on exactly that
observation. So `BidiTui` now keeps the previous frame's mirror row by row and
reuses a row whose three inputs are all unchanged.

Measured A/B/A/B in one process, the memo disabled by clearing it before each
frame:

| | pass 1 | pass 2 |
| --- | --- | --- |
| no memo | 2.96ms | 2.96ms |
| every row reused | 0.56ms | 0.55ms |
| full cache, every row missing at the LAST byte | 2.93ms | 2.94ms |

**5.4× on the steady state, and the pathological case pays nothing measurable.**
The residual 0.55ms is `segment()` + `segmentRegions()`, which still run every
frame; memoising those means deciding when a layout has changed, which is a
harder question than string equality and is NOT attempted here.

### What the tests had to pin

A cache that serves a stale row is a rendering defect, so each input is a test:
the row's text, its pane runs (the same text under a resized pane mirrors
elsewhere — a run reorders within its own span) and its floating boxes (a
dropdown opening over a row whose text did not change). Each is proved by
replacing what the previous frame's mirror produced with a marker and asking
whether the marker reaches the screen: shown, the row was reused; absent, it was
recomputed. That also proves the reuse itself, which no timing assertion could.

The cache is REBUILT whole each frame rather than written through, so a
shrinking terminal leaves no row behind to be served to a later, taller frame —
pinned by planting a row 99 and watching it go. And both setters that change how
a row would be mirrored (`setTerminalBidi()`, `setPaneReorder()`) drop it:
the freeze is part of what each remembered row carries.

### A coverage phantom the change exposed

`$out[] = array_key_exists(...) ? $this->arrange(...) : $line;` was one covered
line hiding an untested branch. Split into a statement, its fallback showed up as
the only two uncovered lines in `src/`. It is not dead code: the vendor renderer
does **not** clip an over-tall widget — asked for two rows it returns five —
so rows past the terminal's last have no panes to reorder within and go out in
logical order. Now tested, with TWO such rows, because that is what separates
`continue` from `break`: `break` drops every row after the first unmapped one
from the frame, and the escaped mutant said so.

## A slice bound does not bound a blocking stat (2026-09-13)

`.porta/freeze.log` from the user's own sessions holds 163 `loop stalled` lines
and **41 full backtraces of the loop blocked five seconds or more**. Grouped by
their top frame, the answer is not spread around:

| frames blocked in | count |
| --- | --- |
| `Buffer::diskStampNow()` → `is_file()` | **35** |
| `DiagnosticLog` (`file_put_contents`/`is_file`/`is_dir`/`filesize`) | 5 |
| `FileTreeWidget::dirChangedSince()` → `is_dir()` | 1 |

Every one of the 35 is the reconciliation poll, and the longest stalls beside
them are 8.85s and 12.12s. The poll was ALREADY sliced — four buffers and eight
directories a tick — because the full pass had been the proven stall. The slicing
is not wrong; it is answering a different question. A bound on the NUMBER of
stats says nothing about a single stat that blocks for twelve seconds, and at
4Hz the same blocking stat is asked for again a quarter of a second later.

`Editor/PollBackoff` times each slice and stands the poll down when one overruns
50ms: one tick, then two, four, eight, up to 120 (30s at the poll's own rate),
back to every tick the moment a slice is quick again. Over the five minutes the
log covers that turns ~163 stalls into ~18. The freeze itself is not made
shorter — nothing in PHP can make a blocking `stat(2)` return — and the cost is
slower DETECTION of external edits, which the reconciler's own docblock already
names as the acceptable trade.

Doubling from one rather than jumping to the cap: a single slow slice is as
likely to be a busy moment as a sick disk, and standing down for thirty seconds
over one of those hides an external edit for no reason.

### Why it is its own class

A stopwatch with a memory is not what reconciling buffers is about, and there is
a testing reason that decided it: `FilesystemReconciler` is an inlined service,
so no test can hand ITS clock a `MockClock` after the container has built it —
`private readonly` defeats reflection, which is exactly what left two mutants
alive on `BufferService`'s save window earlier. A small class with a clock can be
constructed directly, and the reconciler's own test then proves only the wiring:
that a stood-down tick reaches no filesystem, and that a slice which really does
overrun arms the next gap. The second half is driven by making the slice
genuinely slow through the one collaborator a test can lengthen from outside — a
listener on the reload the slice performs.

### Two mutants that changed the design

- `<=` → `<` on the budget **escaped**. The boundary test slept exactly the
  budget, and float seconds cannot land there: the epoch lives in the mantissa,
  so `now - started` is a hair either side of 0.05 and both spellings agree by
  accident. Durations are compared in whole microseconds now
  (`Clocks::microseconds()`, beside the existing `seconds()` so the cast stays in
  one place), and the boundary is exact — so the mutant dies.
- `$this->gap = 0` → `-1` **escaped**, because `min(MAX, max(1, 2 * $gap))`
  papers over every non-positive value. The counter is now "what the NEXT
  overrun costs", starting and resetting at 1, and the `max()` is gone: the
  reset is a value with meaning, and mutating it kills the ladder's first rung.

## The freeze watchdog was lengthening the freezes it reported (2026-09-13)

The other six of the 41 blocked-loop backtraces, read properly. Five are inside
`DiagnosticLog::append()` — and every one of those five was reached from
`FreezeWatchdog`, at its own lines 76 and 95. The instrument writing "loop
stalled for 4.71s" was itself blocked on the filesystem, inside the stall it had
just measured, and the watchdog beats every 0.25s: up to four of those a second
while the disk is slow.

An append cost four filesystem calls, any of which can block:

```
is_dir(dir)  [+ is_dir(parent)]     — the directory check, every line
is_file(path) + filesize(path)      — the size cap, every line
file_put_contents(path, …, APPEND)  — the write
```

They are not cached away, either: this process's own `file_put_contents`
invalidates PHP's stat cache for that path, so the next line re-stats for real.
The freeze.log evidence is spread across exactly those calls — `is_dir` once,
`is_file` once, `filesize` once, `file_put_contents` twice. **Three of those five
freezes were in calls that did not need to happen.**

Now: the directory is checked once per session, the size is COUNTED (seeded from
one `filesize()` on the first line, then advanced by each write's own return
value), and an append is one call — the write.

### What it costs

- **The cap holds per writer, not absolutely.** A second session appending to the
  same log is invisible to this one's counter, so the bound is
  `sessions × maxBytes` rather than `maxBytes`. Pinned by a test rather than
  hidden: the promise that matters — one session cannot grow the file without
  limit — is unchanged.
- **A directory removed mid-session costs the line that discovers it.** A failed
  write clears the memo, so the NEXT line recreates the directory. Asking before
  every line would have kept that one line, at three syscalls for every line
  ever written.

### What it does not buy

**Nothing measurable when the disk is healthy.** A/B/A/B over 4000 appends:
26.05µs vs 26.66µs, then 27.05µs vs 23.93µs — noise, because the write dominates
and a warm stat is cheap. The change is not a speedup and must not be described
as one: it removes three chances per line to block for seconds, which is a
property of the bad case only. Had the benchmark not been run, this entry would
have claimed a 4× saving that does not exist.

### Mutants that found real gaps

Six escaped on the first pass, three of them on lines this round only MOVED
(they had never been in a diff before, so nothing had ever graded them):

- `is_file($p) ? (int) filesize($p) : 0` → the cast and the `: 0` both survived,
  being unreachable spellings of the same thing. Collapsed to
  `(int) filesize($p)`: a missing file warns, the handler already installed
  swallows it, `false` casts to the 0 the guard produced — one syscall fewer,
  one branch fewer, and the cast is now load-bearing (`false` into `?int` is a
  TypeError, which this method may not throw), so the mutant dies.
- The trim's `recentTail().$line` survived being reversed, because the tests
  asserted CONTAINS. A log whose newest line is in the middle is not a log; the
  assertion is `assertStringEndsWith` now.
- `!is_dir($dir) && is_dir(dirname($dir))` survived being turned into `||`,
  which makes `mkdir(recursive: true)` mint a whole missing tree. Pinned: a log
  path under a directory that is not there creates nothing.
- `mkdir(0o755)` survived being changed to 0o756 and 0o754. Now asserted — with
  the umask pinned inside the test, because the mode asked for is masked and a
  test that let the machine choose would pass or fail on the machine.

## A frame taller than the screen scrolls it, once, for good (2026-09-13)

`.porta/error.log` was the evidence this time, and it took two readings.

Its two DATED entries are a `ServiceNotFoundException` for
`Psr\Clock\ClockInterface` out of the `porta.ide` locator, from
`Ide::run()` on 2026-09-09. They are **not a live defect**:
`git log -S 'service(ClockInterface::class)' -- src/Ide.php` returns nothing, and
neither the version current that day nor today's mentions the class at all. The
user ran the IDE while a working tree was mid-round, and the crash log dutifully
recorded code that never existed in a commit. Worth knowing: a dated crash is
evidence of a RUN, not of a release.

Above them sit ~860 undated lines of one repeating trace —
`RenderException: Widget "CodeEditorWidget" rendered line 0 with width 8,
exceeding the available 1 columns`. Undated, so unplaceable in time, but they say
where to look: a terminal one column wide. Rendered the real workbench across six
geometries from 1×1 up, and the width exception is gone. What is not gone is the
other axis:

| terminal | rows the frame had | rows the writer emitted |
| --- | --- | --- |
| 1 × 1 | 3 | 4 |
| 5 × 3 | 4 | 4 |
| 10 × 4 | 4 | 4 |
| 80 × 24 | 24 | 24 |

The menu bar, the main area and the status bar are three rows of fixed height,
and the vendor layout gives a `flex:0` child its intrinsic height whatever the
terminal has. The renderer does not clip, and `ScreenWriter` writes what it is
given — so a one-row terminal was sent three rows and a three-row terminal four.

**One row past the last scrolls the alternate screen.** After the first frame the
writer addresses rows with RELATIVE cursor moves (`CSI nA`/`CSI nB`) and emits no
`CSI H` and no `CSI 2J` ever again, so every repaint for the rest of the session
lands one row out. That is the same permanent-garble mechanism this project has
chased from the other end twice, arriving by a different road.

`BidiTui` clips the frame to the terminal's rows. At one row the menu bar is all
there is to see, which is the honest answer for a one-row terminal; what must not
happen is a screen that never comes back.

### It also removed a branch

The per-row guard `array_key_exists($row, $this->lastRuns)` — the fallback that
wrote an unmapped row unmirrored, tested only last commit and for good reason
then — is now unreachable: the segmenter maps `0..rows-1` and the frame is
clipped to `$rows`. Gone, with an `assert()` in its place, which is what this
codebase does with a guard that can no longer fire. Its test is now the clip's
test, which is a stronger statement about the same rows.

Pinned across four geometries, and both halves are asserted separately: what
Porta COMPOSED (the frame kept for `.porta/frame.txt`) and what the writer
EMITTED (counted by its row separators). The first is the bug's cause and the
second is its consequence, and a clip in the wrong place would satisfy one
without the other. Verified red first: widening the clip to 99 rows fails four of
the eight cases.

## The override that pinned a key to its own default (2026-09-13)

Evidence: the author's own `.porta.json`, which carries

```json
"keymap": { "cursor_left": ["left"] }
```

`left` IS `cursor_left`'s shipped default. `SettingsKeymapController`'s own
docblock already named the mechanism when `resetBinding()` was added — "pressing
Enter on a row and then any key wrote an override, **even one identical to the
default**, which is how a `.porta.json` ends up carrying `cursor_left: ["left"]`"
— and gave the user Del to take one back. The writing half was left alone.

It is not cosmetic, for the reason that same docblock gives: an override is not
only a binding, it **pins** the action against every future change to the default,
silently and for good. Change `cursor_left`'s default in a release and this
config keeps the old one, with nothing on screen to say why.

So a rebind onto the key an action ALREADY fires on now writes nothing and says
`%action% already uses %key%`. "Already fires on" is the effective binding across
all three tiers — editor, then panes, then the IDE map — which `rows()` was
computing inline and `rebind()` now shares as `effectiveBinding()`.

### The half deliberately not done

Rebinding an action that this file ALREADY overrides still writes, even when the
new key is the shipped default. Removing the override there is Del's job and says
so on screen; doing it silently inside a rebind would mean this method deciding
that an override the user placed is no longer wanted. Pinned by a test so the
asymmetry is a decision rather than an oversight.

### A lint rule that reads names

`$token === $this->effectiveBinding($action)` is `error[no-insecure-comparison]`:
mago matches the NAME, and `$token` here is a key chord (`f12`, `ctrl+d`).
Suppressed with the reason rather than renamed — renaming a variable used
throughout the method to dodge a heuristic leaves the next reader with no idea
why it is called something else.

## A flake scan that deleted the answer (2026-09-13)

`bin/porta-dev flake:scan --testsuite=E2e --runs=6` found one:

```
  2/6   intermittent   ChromaPluginTest::testChromaColorsTheBufferAfterTheSubprocessAnswers
```

and the failing runs were the SLOW ones — 27s and 29s against 19–21s for the
four green — so the shape is load, not a regression. Then the trail went cold:
eight isolated runs of that test, four full E2e suites and one deliberate
sixteen-`yes` load experiment (at load average 19) all came back green.

The reason was in the JUnit log, and the scan had **deleted it**. It read the log
for the NAMES of failed cases, `unlink()`ed it, and reported a rate. Half an
instrument: a rate says where to look and nothing about what happened, which is
precisely the question the next hour is spent on.

It now keeps the first reason each failing test gave — one trimmed line, printed
under the test in the same cell — and the frames stay out, because they belong to
whoever reproduces it.

### Where the mutants sent the tests

Seven escaped, every one on the reporting, and chasing them taught the actual
lesson:

**The rendered table cannot be asserted on the cell's shape.** `SymfonyStyle`
wraps a long cell at the terminal width. Take the newline out of
`$test."\n  ".$reason` and the cell comes back wrapped at very nearly the same
place — measured: a regex for "name, newline, two spaces, reason" stayed green
against the mutant, and so did an assertion that the reason's line does not also
carry the name. Tabs vanish the same way, so "the message was trimmed" could not
be asserted from the display either.

So the composition became `testCell()` and the extraction is the point: the shape
is a contract asserted with `assertSame()`, and the wrap is a coincidence nothing
asks about. `reason()` is asserted the same way, against a `SimpleXMLElement`.

**And one mutant survived because an edit never landed.** The script that added
the header assertions aborted on a later `assert` and wrote nothing, so the file
still held the version before it — green, and silent about the change I believed
I had made. The mutant is what noticed. Verified by hand afterwards: with
`'failed'` dropped, `shape` sits over the rate column and the table still renders
perfectly well.

### The flake stays open

Recorded rather than guessed at: `ChromaPluginTest` fails ~2/6 at the composer
default process count, on the slow runs. `await()` counts TURNS (0.02s nominal
each), not wall time, so a loaded box gets a longer budget rather than a shorter
one — which argues against a plain timeout and for something lost. The next scan
that catches it will say which, and that is the whole point of this commit.
*(Since a7e899e3, 2026-09-21, the budget is measured in real seconds — see
testing.md. Ten real seconds for an in-process answer still means lost.)*

## A failed spawn kept the slot it never used (2026-09-13)

The flake from the entry above, chased to the end, and it was a real defect in
`src/` — not a test problem.

### Getting the reason

Once `flake:scan` kept reasons, the scan said it:

```
timed out after 10.0s waiting for chroma keyword coloring to land
```

`await()` counts TURNS (0.02s nominal each), not wall time, so a loaded box gets
a LONGER budget, not a shorter one — ten seconds of loop time with nothing
arriving means the answer was lost, not late. *(Since a7e899e3 the budget is real
seconds — see testing.md; the conclusion stands.)* What failed to reproduce is as
telling as what did: **24 serial runs under sixteen CPU burners, green. A whole
E2e suite run serially in one process, green. Eight parallel suite runs, green.**
Not load, not in-process contamination — concurrent PROCESSES.

The first reason line the new code printed was itself wrong, and that is recorded
in the commit: skipping every line containing `::` skipped the message (which
named a closure) and reported the first stack frame instead. The rule is anchored
now — a line that IS a test id, not a line that mentions one.

### The defect

`AsyncProcess::start()` returns **false** when the child cannot be spawned at
all: the array form resolves the program itself, so a missing interpreter, a
deleted binary or a fork-starved machine never reaches the exit callback. Three
call sites took a latch before calling it and released it only inside that
callback, ignoring the answer:

| site | what leaked | what the user sees |
| --- | --- | --- |
| `AbstractSubprocessHighlighter::run()` | one of two `MAX_CONCURRENT` slots | two failed spawns and syntax highlighting for that language is dead for the session |
| `AbstractToolPlugin::runAndApply()` | the single-flight slot | every later run answers `already running` — format-on-save simply stops |
| `AbstractAnalyzerPlugin::analyseWith()` | the same slot | the analyzer refuses for the rest of the session |

Each is permanent, silent, and about a run that never started.
`ToolInstaller` and `PluginInstaller` already check the return — the pattern was
in the tree, and these three had drifted from it.

### Two different answers, on purpose

The highlighter releases the slot and says **nothing**: a paint is best-effort,
the next keystroke retries, and a flash per debounce on a fork-starved machine
would be its own denial of service. The two tool paths release **and flash** —
they are user-initiated actions, and silence there is the bug wearing a smaller
hat.

### Reproducing a failed spawn in a test

`#!/nonexistent/interpreter` with the executable bit: resolvable, executable, and
unspawnable — the same answer `posix_spawn` gives a machine out of processes. No
mocking, and it exercises the real `proc_open` return.

## The ceiling turned a keystroke's colours away for good (2026-09-13)

Found while hunting the chroma flake, reproduced deterministically, and **not**
that flake's cause — the scan still says 2/12 after it. Recorded that way round
because the temptation to call a plausible fix the answer is exactly what the
last three rounds have been about.

Two guards in `AbstractSubprocessHighlighter` meet in `run()`, and the meeting had
never been graded:

- **latest-wins** — every dispatch carries a sequence, and an answer for anything
  but the newest is dropped;
- **the concurrency ceiling** — a dispatch is declined outright when
  `MAX_CONCURRENT` (2) processes are already out.

Decline the NEWEST dispatch and its sequence is already recorded as the latest, so
the two answers that do arrive are both discarded as superseded — by a run that
never happened. Nothing is painted at all, and the buffer keeps its plain text
until the next keystroke. Which, on a machine slow enough to have two runs out at
once, is precisely when the colours were wanted.

A declined dispatch is now HELD — one per language, replaced rather than queued,
for the same reason the debounce replaces — and released by the next exit, so
every freed slot dispatches exactly one. The ceiling still holds at two, which is
what the old test should have been asserting: it claimed "the third run never
started", which is the implementation, where the invariant is "never more than two
processes at once". It now samples `running` across the drain and asserts the peak,
plus that all three texts land.

The unit test that reproduces the loss fails with the same sentence as the E2e
flake — `timed out … waiting for the backend to deliver` — which is how one was
mistaken for the other. The E2e case makes ONE dispatch, so the ceiling cannot be
its cause; that hunt continues.

### A test I deleted without noticing

Rewriting the ceiling test with a slice-and-splice edit removed two committed
tests from the same region. The suite went green with 12 tests where it had had
14, and nothing said so — the COVERAGE gate did, two lines uncovered in the
branch those tests were the only cover for. Restored from `git show HEAD:`. A
count is worth asserting when an edit rewrites a region rather than a line.

### The flake's own account, for whoever catches it next

The chroma E2e case is still open, and the state of the hunt is worth more than a
guess. Rates at the scan's own process count (paratest's default, all cores — NOT
`composer test`'s half, which is why a `composer test` loop never reproduces it):

| | runs | failures |
| --- | --- | --- |
| before the ceiling fix | 28 | 4 (~14%) |
| after it | 46 | 2 (~4%) |
| after the affordances round (2026-09-14) | 25 | 0 |

**The 25 clean runs are NOT evidence the flake is gone**, and the row is here so
nobody reads them that way. At a true 4% rate, 25 runs come back clean 36% of
the time — a coin-flip dressed as a result. Pooled with the 46, the estimate is
2 in 71 (~3%), which is the same number with a smaller error bar. Same command
as the rows above (`flake:scan --testsuite=E2e`, no `--processes`, so paratest's
own default), because a rate measured at a different worker count is not a
comparison. Wall time was 18–22s per run.

Ruled out, each by measurement rather than argument:

- **Load.** 24 serial runs of that test alone under sixteen CPU burners: green.
- **In-process contamination.** The whole E2e suite run serially in one process,
  randomised: green.
- **A plain timeout.** `await()` counts TURNS at 0.02s nominal, so a busy box gets
  a LONGER budget, not a shorter one. Ten seconds of loop time elapsed. *(Since
  a7e899e3 the budget is real seconds — see testing.md.)*
- **The concurrency ceiling.** Real, fixed above, and not this: the E2e case makes
  a single dispatch.
- **Fork starvation.** `ulimit -n` is 1,048,576 and `maxproc` 8,000 on this
  machine; a spawn refusal is not plausible at eight workers.

What remains: the subprocess answered and the paint was lost, or it never ran at
all. The test now SAYS WHICH. Its stub drops a sentinel beside the project and the
timeout appends `— the stub DID run, so the answer or the repaint was lost` or
`— the stub never ran, so the dispatch or the spawn was lost`. That message rides
into `flake:scan`'s reason column, so the next catch — anyone's, in CI — names the
half without this afternoon's archaeology.

This is also the documented rule the test was breaking: *awaits must be
terminal-state, not success-only*. It awaited success and reported a bare timeout.

## The gutter marks move, and two rows that were one column too wide (2026-09-13)

An improvements-led round: the UI treated as sound, the time spent on polish
and affordances, and only the defects that blocked those fixed on the way.

### Picking the work

`.porta/` again, the way the find-surfaces round did. It holds **five saved
search scopes** (one pinned), a search history and a `scope: module` pref — and
**no `bookmarks.json` at all**, with two local-history snapshots. So bookmark
mnemonics stayed unbuilt for the second round running, on the same evidence.

The crash logs were read first and are **exhausted**: `error.log`'s twenty-one
undated traces and its two dated ones were both settled by the previous
commit, and `freeze.log`'s bad day predates the poll and diagnostic-write
fixes. `perf.log` since is healthy — paintMs p50 1–3 ms, no slow-paint lines.

### Two rows a column too wide, which is a crash

Both found by sweeping for one defect class — *a widget emitting a line whose
visible width can exceed the region it was handed* — and both reproduced before
being touched.

**`AnsiText::caretWindow()`.** `AnsiUtils::sliceByColumn()` defaults to
NON-strict: `$fits = !$strict || ($currentCol + $w <= $endCol)`, so a grapheme
that starts inside the range and ends past it is kept whole. One double-width
character landing on the window's last column therefore came back one column
over. Nothing downstream caught it, because `scrollLineToCaret()` finishes with
`padRight()` — a floor that never truncates. That helper is behind every
single-line input in the IDE: the find bar, the settings field being edited, the
Search Everywhere query row, `TextField`, `MultilineInput`. One CJK or emoji
character in any of them ended the session.

Measured after the fix over 888 cases — CJK, emoji, combining marks, RTL and
tabs, every caret position against every width 1..12 — zero over-wide. The case
that pins it scrolls the window so the wide glyph lands ON the cut; the existing
wide-character case has the caret at the END of the text, which is exactly where
nothing straddles anything.

**`TabBarWidget::indicatorRow()`.** `renderVertical()` computes
`min(VERTICAL_WIDTH, columns)` and says in a comment why — a widget may not emit
a line wider than its pane. The two ▲/▼ rows then padded to the CONSTANT anyway,
and registered a click zone of that width too. Reproduced at every width from 1
to 23: one row of 24 among rows of 16. The blank fallbacks beside both call
sites had used `AnsiText::blank($width)` all along; the indicators were the two
lines that never asked.

### Silence is not a refusal

`AbstractLspController::ready()` returns null when no initialized server serves
the file, and every user-invoked site answered with a bare `return;`. In a
Markdown, JSON or JavaScript buffer — or any buffer before a PHP server
finishes starting — twelve verbs did nothing at all, with no message. Rename was
the one that already flashed, which is what made the other twelve look
deliberate.

Guarded from both ends, because neither half is enough. `LspRefusalTest` is
STRUCTURAL: no `ready()` call site in `src/Lsp` may refuse in silence unless the
allowlist names it with a reason. That protects the verb someone adds next month
behind a sixth request helper, which a per-verb test cannot. It also cannot see
whether the helper actually flashes, so `AbstractLspControllerTest` asserts that
behaviourally, with a ready server as the control.

Completion and signature help stay silent and now say so: Ctrl+Space and the
`->`/`::` trigger reach the same method, as do Ctrl+Shift+P and `(`/`,`, so a
flash there fires on ordinary keystrokes.

**And `when` was only half a gate.** `CommandRegistry`'s docblock described the
hole rather than closing it: "the palette and menus still run the handler, which
must self-guard". `MenuModel::item()` composed in a PLUGIN item's `when` and
never a core command's, so the Run menu shipped seven debugger verbs enabled
with no session — four of them `$this->session?->step…()`, doing nothing in
silence, two rows from `runToCursor()` and `restart()`, which both flash.
`evaluatePrompt()` was worse than silent: Find Action lists a command whether or
not its `when` holds, the guard sat inside the prompt's callback, and the
expression the user typed was dropped without a word. It refuses before
prompting now, and the test asserts the prompt never opens — a guard that still
prompts and then discards passes a flash-only check.

### A key the keymap page offers is a key that dispatches

`open_as_text` (Ctrl+Alt+X) and `ide_notifications` (Alt+9) carried default
chords in `ActionCatalog` and were registered without `keyBound:
KeyDispatch::Bound`, so `keyBoundIds()` — the only list `InputDispatcher` walks
— omitted both. Settings ▸ Keymap displayed the chord and let the user rebind
it; neither the default nor the rebinding did anything.

`ActionCatalogTest` could not see this: it asks whether an entry is
default-BOUND, which is a different question from whether the binding is
REACHABLE. Every test of those two verbs drove them through
`CommandRegistry::run()`, the one path that cannot notice.
`KeyDispatchReachabilityTest` asks the reachability question against a booted
IDE's real registry, and named exactly these two.

### The gutter marks follow the edit

The round's feature. Change marks describe the file ON DISK and were recomputed
only on a save, a reload or a buffer switch; between those they sat still while
the lines under them moved. Three lines added at the top of a changed file left
every mark below pointing three lines wrong, in the marker column and the stripe
together, for as long as the editing went on. Both `README.md` and `git.md`
documented it as a limitation.

**It is core's, and nothing grew an API.** `notifyChange()` is the one place
core hears about every edit and the mark map is core's own, so the whole feature
is `DecorationStore` plus one line in the widget. The plugin API has no per-edit
hook — `onFileOpened`, `onFileSaved`, `onFileReloaded`, `onBufferSwitched` is
the entire list — and adding one, or widening the buffer layer's roles to carry
an edit position through twenty-three `notifyChange()` call sites, would both
have been real surface for a feature that needs neither.

**Both numbers are derived**, because nothing reports either: the delta from the
document's line count against the last one the store saw, and the position from
the caret less that delta. Insert three lines and the caret sits three below the
line the edit began on; delete a selection and the caret is left at its start,
which is that line already. Exact for a single caret, which is every ordinary
edit.

**The baseline travels with the marks.** `setVcsMarks($marks, $lineCount)` —
they are one fact, "this map describes a document of this many lines". That is
what closes the RELOAD hole: the reconciler replaces a clean buffer's text with
`Document::replaceAll()` and no `showDocument()` to rebase through, so the
producer's fresh marks landed against a pre-reload count and the next keystroke
read the whole difference as its own edit. A caller merely CLEARING the marks
passes no count and must not rebase, or switching the gutter off would make the
next keystroke paint the entire file.

Deliberately an approximation, and a self-correcting one: it tracks the active
caret, and every save, reload and switch replaces all of it with git's answer.
What it buys is that the marks are never silently stale in the state a user
spends most of their time in.

### Two findings that were not findings

Worth recording so neither is re-planned.

**The editor gutter's mouse affordances already ship.** A sweep reported them
missing on the evidence that `EditorMouse::positionAt()` collapses every click
left of the text to column 0 and the file has no breakpoint or fold branch — all
true. The routing is one layer up, in `MouseRouter::dispatchEditorPress()`,
which tests `$rel['col'] < $editor->gutterWidth()` and calls `gutterClick()`;
both halves have real-SGR E2e cases. A file-scoped read is evidence about that
file and never about a feature: grep the dispatcher and `tests/E2e/` before
recording a gap.

**The status bar's vanishing click spans are correct.** They are dropped while a
flash is showing and while a viewer pane owns the right side. Both are
deliberate with the reason in the code — a flash replaces the left and truncates
the right, so every offset is wrong, and a pane's segment is not core's to
route. Only the third report was real: the `ide_lsp_log` span covered the whole
legend, so clicking "Ctrl+S save" opened the server log. The existing case
asserted `assertStringStartsWith('LSP:', …)` on the span's own slice — just as
true of the whole legend, which is how a span four times too wide passed a test
written to catch exactly this.

### What the mutation checks cost, again

Nine on the marks feature, and two lied first. One was the old anchor trap:
`composer format` had added parentheses, the replace matched nothing, and the
mutant reported SURVIVED. The other was new — the control called `setVcsMarks`
again AFTER the call it was testing, putting the baseline back before the
assertion, so a control has to END on the mutated state rather than pass
through it. And the deletion fixture needed its doomed mark to be a DIFFERENT
kind from the one below it: with two alike, dropping a mark and shifting it onto
its neighbour both write the same value to the same line. That is this file's
own "the fixture has all its elements alike" rule, arrived at from the mutation
side.

## A file too large to edit is still a file you can look at (2026-09-13)

Over `Files::MAX_TEXT_BYTES` the IDE flashed "File too large (> 2 MB)" and did
nothing else. A composer.lock that had grown, a log, a database dump, a bundled
asset — the answer was to leave the IDE. It opens read-only now: a scrollable
preview of the first cap's worth, cut at a line break, with a banner naming the
file's real size.

### A pane, not a read-only buffer

The decision worth recording, because the other option looks cheaper and is not.

"Read-only" would be a new state that every mutation path in the editor has to
respect — the document's ten mutators, the save path, autosave, the LSP
`didOpen` — and one missed path silently lets you edit a file that cannot be
written back, which is worse than refusing to open it. A PANE buffer already
carries a permanently-empty document that is never dirty with its cursor pinned
at 0:0, so the guarantee comes from the SHAPE rather than from a flag somebody
has to remember to check. The test asserts that shape rather than a flag.

It registers through `PaneRegistrar`, the same extension point a plugin's
`registerPane()` reaches — the second pane core owns, beside the help browser.
`BufferService`'s size branch stopped flashing and calls `openInPane()`, ahead
of the `OpenAs::Text` bypass on purpose: "open as text" on a file this size has
nowhere else to go.

### The prefix is bounded, which is the whole point

Reading all of it would reintroduce one layer down the exact problem the cap
exists to avoid — a dump is bounded by nothing, and `file_get_contents` on it
takes the session. One cap's worth is read and the banner says so.

Cut at the last NEWLINE inside the cap, not at the byte: a prefix ending
mid-line shows a half-line the file does not contain, and cutting mid-CHARACTER
fails the UTF-8 check over bytes that are fine in the whole file. A cap's worth
with no newline at all — a minified bundle is megabytes on one line — keeps the
raw prefix, because there is nothing better to cut at and the alternative is an
empty pane for a file the user asked to see.

A huge file that is not UTF-8 is still refused, by the loader throwing: the
preview is a way to LOOK at a large file, not a way to render one as mojibake.

### It binds core's verbs rather than minting its own

The help viewer mints `help_*` ids because it has verbs of its own — links,
history, find. This has nothing but scrolling, so it binds `cursor_up`,
`page_down`, `document_end` and the rest. No new catalog entries, no new label
translations, and rebinding "page down" moves it here too, which is what a user
rebinding "page down" means.

### Three of this repo's own gates fired on it

Worth listing, because the round's value is as much in these as in the feature.

`NarrowPaneRenderTest`'s honesty gate refused to let a new `#[AsIdeService]`
widget exist unswept — and the moment it was added to the sweep, the sweep found
an uninitialised `$styles` at every width: the constructor had not called
`initStyles()`, which is a fatal on the first frame.

`KeybindingsDocTest` caught `ctrl+home`/`ctrl+end` as undocumented. Dropped
rather than documented, matching the help viewer's plain Home/End.

`CatalogCoverageTest` caught the now-orphaned "File too large (> 2 MB)" unit
left behind in all seven catalogs.

### A note on the gate itself — RETRACTED, see below

> **This section was wrong and is kept only so the correction has something to
> point at.** The gate was not deadlocking; it was catching a regression this
> change introduced, and the timeline says so plainly: `coverage:check`
> completed cleanly TWICE before the preview feature existed and stalled THREE
> times after it, every time at 99%. What stalls there is
> `IdeFilesystemSessionTest::testUnopenableFiles`, an E2e case that walks the
> file tree opening five files — a file over the cap used to be REFUSED, so
> focus stayed in the tree, and once it started opening read-only the pane took
> focus and every later keystroke went to the wrong widget. Fixed in the commit
> after this one.
>
> The lesson is not about paratest. It is that the feature commit ran Unit and
> Plugins and NOT E2e, and E2e is the only suite that covers the path the
> feature changed. Then three separate mechanisms were proposed for the
> "hang" — a coverage merge, a lost terminal, a stretched turn budget — and all
> three were wrong, because each started from "the tooling is broken" rather
> than from "what did I just change". A gate that starts failing right after a
> change is evidence about the change.

`composer coverage:check` DEADLOCKED twice on this change — 27 minutes elapsed
on 50 seconds of CPU, 0% utilisation, stuck at 99% in paratest's coverage merge.
It completed three times earlier the same afternoon at the same settings, so it
is intermittent rather than anything about the diff, and the documented
`PORTA_TEST_PROCESSES=4` mitigation did not prevent it.

What unblocked it is worth keeping: the question was never "is the tree at
100%", it was "is any NEW line unexercised". A clover report over just the
affected test classes answers that in seconds —

    php -d pcov.enabled=1 vendor/bin/phpunit --testsuite=Unit \
      --filter '<the classes that touch it>' --coverage-clover /tmp/c.xml

then read the `count="0"` statement lines out of it. That named six uncovered
lines (the four tab-management arms, the `default` arm, and the zero-size early
return), each of which got a case. `--coverage-filter` on the command line does
NOT narrow the report — `phpunit.xml.dist`'s own coverage config wins — so grep
the classes out of the XML rather than trying to scope the run.

### And a bug only a mutation found

`document_end` asks to scroll to `PHP_INT_MAX`, the idiom the help viewer uses.
Adding that to a non-zero scroll overflows the int to a float, and the clamp
takes an `int` — a TypeError. It is invisible if a frame is drawn between the
two keys, because the render re-clamps, and the first test did exactly that. End
followed by Down is an ordinary burst from ONE read of stdin. `scrollTo()` is
split from `scrollBy()` so a jump clamps without an addition, and the case that
pins it deliberately skips the frame.

## Two orphaned IDEs that were not an IDE defect (2026-09-13)

Two `bin/porta` processes were found alive after **1 day 11 hours**, left by an
RTL sweep script of the form

    ( sleep 4; printf "$keys"; … ) | timeout 25 script -q /dev/null \
      /bin/sh -c "stty cols 226 rows 67; cd $S && exec bin/porta"

The first reading was that Porta had not noticed its terminal go away: the
`timeout` had long since fired, so the pty's owner should have been gone, and a
process polling the filesystem at 4 Hz into a dead tty forever is exactly the
"session that never comes back" shape this round had been fixing elsewhere.

**That reading was wrong, and the evidence is unambiguous in both directions.**

*It exits when the pty owner dies.* Reproduced five ways — the bare shape, the
piped-keystroke shape the sweep actually used, and four concurrent runs, every
one with a `timeout` shorter than the session. Survivors each time: zero.

*It handles the signal and unwinds properly.* The orphans' own
`.porta/session.json` carries the timestamp of the `SIGTERM` that ended them,
which only the ordinary teardown writes. `Ide::run()`'s
`EventLoop::onSignal(SIGTERM|SIGINT|SIGHUP)` did its job 35 hours after boot.

*And they were not frozen.* `.porta/freeze.log` shows "loop stalled … recovered"
lines right up to the last hour, the loop alive throughout, and `perf.log` grew
to 197 KB.

So Porta kept running because **it had not been orphaned**: nothing signalled
it, and its pty was still open. The leak is the harness's — that afternoon's
`timeout 25 script …` did not take the tree down, and a wrapper that fails to
kill what it wraps leaves a perfectly healthy program running.

**The mechanism by which the harness failed is NOT established**, and it is
deliberately not guessed at here. `script` outliving the signal is plausible and
so are three other stories; this file's own rule is that a plausible mechanism
is not a diagnosis. What IS established is where the fault is not.

*If it matters later:* the fix belongs in the harness — kill the process GROUP,
or `timeout -k`, rather than trusting a single `SIGTERM` to a `script` that has
a child of its own. And capture a backtrace BEFORE killing a long-lived orphan;
the processes here were killed for hygiene before anyone thought to ask them
what they were doing, and the on-disk logs are the only reason the question
could be answered at all.

## One guard, three ways past it (2026-09-14)

A second review round over the same request as the last one, and it found what
the last one left: the IDE has no autosave, `Ide::run()`'s teardown has never
written buffer contents, and exactly ONE path checked before ending a session
with unsaved edits in it.

Quitting asked twice. Switching project — added the previous round — called
`$this->tui->stop()` directly and asked nothing. An LSP rename closed each moved
buffer through `BufferManager::close()`, which has no dirty check. An LSP delete
closed a dirty buffer and removed the file without a word.

Three of the round's five defects were introduced by the round before it, which
is the uncomfortable part: the switcher shipped a silent data loss and a dead
key, the two exact classes that round spent itself removing. It also shipped
three docblocks asserting the teardown "saved buffers", which is what a reviewer
reads instead of the teardown.

### The guard is per-VERB, and that is not cosmetic

`WorkbenchActions` held one `lastQuitPress`. Extracting it as-is would have made
a `Ctrl+Q` that warned arm a project switch — the second press confirming
something the user was never asked about. Keyed by verb, with a case pinning it.

### Where each fix had to go

- The switch confirms in `ProjectSwitcher::requestOpen()`, not beside the
  `stop()`: refuse before the event, so nothing has begun tearing down when the
  user is told.
- The LSP rename CARRIES the unsaved text instead of saving first the way the
  tree does. It cannot save: by the time `BufferRepointRequested` is dispatched
  the file has already moved, the old path is gone, and `saveBuffer()` writes to
  the buffer's own path — saving then would recreate the file the refactoring
  just moved.
- The LSP delete warns and proceeds. Refusing would leave the workspace edit
  HALF APPLIED, which this file has called the worse outcome since the
  `RenameFile` entry.
- The empty-replacement confirm lives beside the code that deletes, not in the
  modal that asks. In the modal it needed a new property (mago refused — the
  class is at its limit), a new callback and a new dependency; none of that
  survives the move.

### A replace that rewrote what the filter excluded

`LanguageFilter` keeps a line when AT LEAST ONE occurrence falls in the
requested token class; the replace rewrote the whole line. `$foo = 'foo';` under
"in strings" came back as `$bar = 'bar';` — the variable renamed by the filter
that existed to protect it.

Two things could not be reused, and both were tried first. The results' own
`ranges` are offsets into the TRIMMED preview text, not the file's line. And
`matchOffsets()` gives no LENGTH, which a splice needs — under `regex` a match
is not `strlen($query)` long. Hence `matchSpans()`, with `matchOffsets()`
delegating to it.

### What E2e caught that 6162 unit tests could not

Twice, and the same shape both times: a new guard placed one step too early.

The project switcher's placement was right by design. The empty-replacement
confirm was not — it ran BEFORE the "Nothing to replace" check, so Alt+A with no
query and no results offered to confirm deleting nothing. And the previous
round's regression was found the same way. The rule earned twice now: run the
suite that COVERS the path you changed, not the quickest one.

### Tests that stepped over their own subject

Three, all of which passed throughout the defects they covered.

`SettingsViewControllerTest` hand-set `setFocused(true)` around each `1`/`2`
press and asserted the page changed — true throughout, while every keystroke
after the swap went to an unmounted widget where Enter WRITES CONFIG. The two
repoint cases assert the TAB follows a rename and never the TEXT. And
`FileCommandsTest`'s first version of the dead-key case asserted `pickerOpen()`,
which reads the overlay host's field whether or not the widget holding it is on
screen — it passed with the guard removed, and the fix was to assert the EFFECT
(the editor is back in the slot) instead.

### Driven, at last — and what that showed

Both rounds had shipped on tests alone, which `testing.md:375` asks nobody to
do. A real pty (`script -q`, `stty cols 120 rows 32`, `PORTA_FRAME_DUMP`)
against a scratch project settled four things a double could not:

- **Live git marks are right on screen.** `┆` on the one line differing from
  HEAD, and on the line just typed into, with no save.
- **The split unsaved-changes string renders as one sentence.** It was
  refactored into prefix + `%repeat%` across seven catalogs, and only a frame
  can say the join reads correctly.
- **The project switch really does reboot in-process.** Clean buffer, typed
  path, Enter: the tree becomes the new project and Welcome opens on its first
  visit. Dirty buffer: it stays put. A second ask inside the window goes
  through. That is the whole contract of the round's headline fix, confirmed
  where it matters.
- **The dead key is dead no more:** `Ctrl+Shift+O` from a viewer tab hops back
  to the text tab and opens the picker, or says "Not available in this view"
  when there is no text tab to hop to.

Two things the driving found that reading had not:

**The gutter read 1, 1, 2, 3, 4 and looked off by one.** It is not: this
machine's `~/.porta/config.json` sets `lineNumbers: relative`, so the caret's
line is absolute and the rest are distances. The third "looks wrong, is
correct" of the round — check the config before the code.

**A file barely over the cap reads oddly.** 2,097,216 bytes formats as "2.0 MB"
and so does the 2,097,152-byte prefix, so the banner says *"is 2.0 MB, showing
the first 2.0 MB"*. True and useless. Cosmetic, at a boundary, and left
unfixed — recorded so the next reader knows it was seen.

**And a caution for the next driver:** a real run writes to the REAL global
state. This one put two scratch roots into `~/.porta/recent-projects.json` and
they had to be cleaned out by hand. Point `PORTA_HOME` at a temp directory
first.

## PHP 8.5, Symfony-style lint, Rector as a gate (2026-09-14)

**The floor is PHP 8.5.** Nothing in the code needed changing for it: `mago
analyze` and `mago lint` were already clean at `php-version = "8.5"`, and the
suite already ran on 8.5 with `failOnAllIssues`, so the bump is manifests, CI,
the two `bin/` guards and prose.

**mago has no Symfony preset; the formatter was already one, so the linter is
the change.** mago 1.47's presets are default, psr-12, pint, tempest, hack and
drupal. What @Symfony adds that mago can grade: yoda comparisons, fully
qualified native functions and constants, and `$this->assert*()`. The native
call rule cannot be scoped to php-cs-fixer's `@compiler_optimized` list, so it
covers every native function — 8,496 sites, inserted at mago's reported byte
offsets so every changed line stayed one line. All four rules sit at `warning`
explicitly: at their default `help`, `--minimum-fail-level=note` never fails.

**Rector is a gate, `composer rector:check`, and its skip list is the
decision record.** Every skipped rule carries its reason in `rector.php`;
what follows is the pattern behind them, measured by applying each set and
reading the diff grouped by rule before trusting it.

- **By-reference captures defeat phpstan's inference.** Three rules read
  `use (&$x)` as the value `$x` starts with: always-true ifs, unreachable
  statements, and wide-union return narrowing. The first two gutted
  `AsyncHighlightTest`'s intermittent-failure branch in the diff; the third
  passed `composer test` and failed two `MenuModelTest` cases under
  `coverage:check` — `?string` narrowed to `string` on a closure that returns
  null once the captured flag flips.
- **mago is the analyser here, not phpstan.** `@var mixed` tags that
  acknowledge a mixed assignment, inline `@var` on returns, and casts over
  `string|false` natives all look useless to Rector and are what mago narrows
  from. Two first-class-callable rewrites (`\is_string(...)` in a filter, typed
  array constants without a shape) lost mago inference outright.
- **Some rewrites are behaviour.** `\dirname(__DIR__)` → `__DIR__.'/..'` is a
  different path string; sorting named arguments reorders evaluation;
  constructor promotion renames parameters out from under named-argument
  callers; dropping an unused public parameter unwires a listener, which the
  dispatcher matches by parameter type; flipping assert arguments swapped a
  deliberate needle into the haystack.
- **Some fight the house style.** `?:` (mago's no-shorthand-ternary), null
  checks as `instanceof` (not Symfony's spelling, not yoda, and one import —
  one arch edge candidate — each), private static helpers made instance methods
  (static closures call them).

Rector imports the class names it adds, classes only and only in files it
already changes; importing functions would undo the qualified calls.

**One metric floor moved:** cyclomatic-complexity 147 → 145 (`mago.toml` has
the note). The pin had already drifted to 146 on `main`.

**PHP 8.5 idioms adopted by hand, and one not.** `clone($this, [...])` replaced
the hand-rebuilt withers (`MouseEvent::withColumn` re-listed eleven constructor
arguments to change one), still refused from outside the class by the readonly
properties' implicit `protected(set)`. The key is a string, so a typo is a
runtime Error and not a mago finding — each wither is guarded by a test that
checks the changed field AND an untouched one. **The pipe operator was not
adopted:** every nested chain in the tree starts at a multi-argument call
(`\explode(',', $raw)`, `\substr($s, 0, $n)`), and without partial function
application (not in 8.5) each stage becomes its own arrow function — longer
than the nesting it replaces. Revisit when partial application ships.

## Modern idioms: match, enums, property accessors (2026-09-14)

**`match` was already the idiom.** 147 uses before the round and no
if/elseif chains; one conversion landed (`EditorConfigGlob`). What stayed, and
why, is the rule for the next one: `PatchBuilder`'s and `UseScanner`'s switches
are state machines mutating several locals per case; a guard run that does
nothing on an unknown value must not become a `match` without `default`, which
throws `UnhandledMatchError` instead.

**Ten enum conversions, one shape.** A pure enum when the value lives in
memory; a backed one when it is an array key, stored, compared by a plugin, or
round-trips through a string-valued widget (a list overlay, a settings row) —
there it comes back through `from()`/`tryFrom()` at that boundary and nowhere
else. Lookups move onto the enum (`StagedState::checkbox()`,
`LineEnding::bytes()`, `DiagnosticSeverity::glyph()`), and every `match` over
one is exhaustive, with no `default` to swallow a typo.

`DiagnosticSeverity` is the one that departs from a neighbour, deliberately:
`DiagnosticTag` keeps unknown wire values as ints because an unknown tag means
"draw normally"; an unknown severity has always been drawn as a hint, so it is
converted once at ingestion (`fromWire()`) and every reader stops re-deriving
the clamp, the `min()` and the 0 sentinel.

**Accessors became properties where the method only returned or assigned a
field** — `public readonly` for collaborators, `public private(set)` for state
the class alone mutates (mago reports an outside write as
`invalid-property-write`; PHP refuses an indirect one), a plain public property
for a setter that did nothing else, a `set` hook where it did one thing
(`GitSettings::$refreshInterval`'s floor), and `{ get; }` interface properties
for `LspClientInterface`, `FindTarget` and `SyntaxHighlightProvider::$id`.
Symfony factories that named a getter share `Container\PropertyFactory::read()`.

What stayed a method, each for a reason that will recur:

- **Anything plugin-facing**: `ContributedView`, `PortaPlugin::name()`,
  `PluginApi`, `PluginBuffer`, `ViewRegion` (reached through
  `ViewServices::$region`), and vendor contracts.
- **Setters that report or refer**: `EditorDisplayOptions` setters return
  whether anything changed and write through a by-reference `apply()`; a hook
  can do neither. `EditorDecorationSink`'s setters invalidate and two take
  several arguments — a half-property interface reads worse than none.
- **Registrars**: `onNotification()`, `onApplyEdit()`, `onClose()` take a key or
  a `callable`, which no property can be typed as.
- **Constants on readonly classes**: `SearchSource::category()` — four of five
  implementations are readonly classes, which cannot carry a hook.
- **A name used across unrelated classes**: `OverlayWidget::isOpen()` stays,
  because seven other classes (the plugin-facing `ContributedView` among them)
  keep `isOpen()`, and one concept spelled two ways is worse than a method.

The call sites were rewritten by a throwaway, type-aware Rector rule (receivers
typed as the owner only); anything it could not type — `getById()` in tests —
was fixed by hand and caught by the suite as an undefined-method fatal. Where a
name collides with a vendor method (`symfony/tui`'s `EditorDocument` also has
`getLines()` / `getCursorLine()`), the hand pass matched the receiver, never the
name. No mago metric floor moved.

## Symfony 8.2 ahead of its release (2026-09-28)

Porta requires `^8.2` of every Symfony component and resolves `8.2.x-dev`, under
`"minimum-stability": "dev"` with `"prefer-stable": true`. Per-package `@dev`
flags were tried first and cannot work: 8.2 pulls in `event-dispatcher-contracts
^3.8` and `polyfill-intl-grapheme ^1.42.1`, which exist only as `dev-main` and
`1.x-dev`, and a root stability flag does not reach a transitive package.
`prefer-stable` means that once 8.2.0 ships, a plain `composer update` moves every
package back to a tagged release. At that point, restore `minimum-stability: stable`.

`symfony/tui` 8.2 changed four APIs Porta uses. `Renderer::render()` became
`renderFrame()`, `ScreenWriter::writeLines()` became `writeFrame()`, and
`AbstractWidget::setRenderCache()` now takes a `LineBufferInterface` instead of a
`list<string>`. `TerminalInterface` also gained `getEventDispatcher()`, and
`Tui::__construct()` rejects a terminal whose dispatcher is not its own.
`NonBlockingTerminal` therefore delegates the dispatcher to the terminal it wraps.
`BidiTui::processRender()` still mirrors the vendor's `processRender()` step for
step (clear the flag, `renderFrame`, `writeFrame`). It clips the frame with
`LineBufferInterface::slice()` and hands the mirrored rows back as an `ArrayLineBuffer`.
Two of the new 8.2 APIs were adopted after the upgrade:
`AnsiUtils::sliceToWidth()` cuts the editor window and the code-lens rows
(`EditorRenderer::window()`, fast slice kept where it cannot be wrong, 29 vs
7 us per highlighted row measured) and composes around every overlay cut
(`OverlayCompositor`, `SpanSlicer`, the graph selection frame, the image focus
rails — `TerminalBuffer` keeps `AnsiText::columnsFrom()`, since the vendor slice
pads); and `walkCells()` finds a column's byte in
`HelpViewerWidget::byteAtColumn()`. `postRender()` now composites the floating
layers (below). Not adopted: `attachChild()`, the tab, collapsible and key-binding
widgets. Each needs its own measured case.

## Overlays composite in `postRender()`, and the key bar is not built (2026-09-28)

**`postRender()` — adopted.** `MenuOverlayHostTrait` composites the menu, modal
and toast spans in the vendor's `postRender()` hook, so 25 `withMenuOverlay()`
calls left the hosts' `render()` bodies. The width is the catch: compositing at
the context's columns pads a row out past a host that draws narrower, which
starves the pane beside it. Eight hosts draw narrower and say so in
`overlayWidth()` — the six `PanelWidth` side panels (`panelColumns()`), the
one-column `SeparatorWidget`, and the side `TabBarWidget`. Each override is
guarded by a test that renders wider than the host with a dropdown reaching past
it; no test guarded those widths before. A bare `render()` no longer shows
overlays, so widget tests that assert one render through
`RendersWidget::painted()` (a real `Renderer`).

Measured with the real IDE, alternating A/B (4 rounds each, 140×40, a File
dropdown open and moved 240 times, then 240 caret moves): renderMs p50 0.53 ms
before vs 0.50 ms after, p95 1.08 vs 1.05 ms, over 64 one-second windows each.
No cost.

**`KeyBindingWidget` key bar — declined.** An optional row of the focused pane's
keys was scoped and not built. Porta's panes each carry their own bindings (the
editor's keymap alone is dozens), so a one-row bar needs a chosen subset per
pane; the pane-local actions (`collapse`, `move_up`…) have no translated labels,
so each needs new ids in all seven catalogs; and the vendor widget wraps onto
several rows and keeps its item lists private, so a one-row, overlay-aware bar
overrides nearly all of it.

*Re-opens on:* a chosen per-pane action list, which turns the bar into a
translation-and-layout task.

## Text fields are boxed, and the box's shape is its state (2026-09-23)

Every input used to be ` Label: value` on one row, each site picking its own
background, with focus shown only by the caret — and under `--no-ansi` an idle
field and a focused one were identical. `FieldBox` now draws them all.

**The label is a legend in the top border, not a row above the box.** A label
row makes a field four rows; the find bar and Find in Files could not afford
it, and a legend keeps the label on screen at all times, so the placeholder
never has to stand in for it. Re-open if a surface needs a label longer than
its box.

**Heavy for focus, double for danger, rounded at rest.** The glyph is the cue,
so the monochrome theme maps no border role at all (`FieldBorder` is a tint
only); `FieldPlaceholder` is the one new role monochrome styles — dim + italic,
because `Dim` is plain there and a placeholder must not read as a typed value.
`FieldFocusBg` went with `SearchFieldRenderer`, its only consumer; net +1
`Role` case (mago's cap 61 → 62).

**Settings text rows stay one row** (`compact()`): the form's selection, paging
and pointer hit-test all assume one row per item, and a three-row box for the
edited row only would put the row→index mapping on every one of them. Re-open
with a form that pages by rendered rows.

**Find in Files goes compact below 30 terminal rows**: three boxes cost six
preview rows, and at 24 rows that left five. **No `Disabled` state yet**: no
input is read-only today; the dashed set (`╭┄╮ ┆ ╰┄╯`) is the recorded choice
for the first field that is, not a case with no caller.

**The find bar's hints pack whole into the strip beside the boxes**, above and
below the tags — a cut hint names half a key, so what does not fit is left to
the keybindings page. The match position rides the Find box's bottom border,
so it lasts as long as the box — beside the tags it was the first thing a
narrow pane cut. Boxes cap at 32 columns, a browser input's size rather than
the pane's. Compact, Find in Files pads its labels and its hints to one width
each, so the three fields' rails stand in two columns.

**A selected panel row re-arms its foreground after every inner
default-colour reset** (`PanelRowStyle::shadeRow`): `Style::apply()` re-arms
only a background, so the value after a field's rail drew in the terminal's
default colour instead of the selection's.
