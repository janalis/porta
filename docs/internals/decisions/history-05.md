# Decision history, part 5

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## src/Search's first mutation review (2026-09-11)

1,283 mutants, 1,088 killed, **152 escaped**, covered MSI 87% — far too many to
read one by one, so this round took the two shapes that had already produced
real defects today rather than working through the list.

**A loop's skip branch,** for the third time. `ActionsSource` skips a command
whose fuzzy score is null, and as a `break` every test in the file stayed green:
each registered its MATCHING command first, so the hit was collected before the
loop gave up. Register it second — which is the ordinary case for any query —
and the palette empties. That is now three areas in a row where the only real
gaps were a loop's `continue`, all invisible for the same reason: a fixture with
the interesting element first.

**A line carrying a cluster.** Seven survivors sat on one expression in
`TextSource` — `$result['path'].':'.($line + 1)`: the concat, both operands, and
every way of shifting the `+ 1`. A cluster on one line is a reliable sign that
nothing asserts what the line PRODUCES, and here it is the detail a reader sees
beside every Search Everywhere result. Lines are held 0-based and shown 1-based,
so an off-by-one there is wrong on every row at once. It has an exact assertion
now.

*Left unread, deliberately:* the remaining ~145, most of them in
`SearchEverywhereController`, whose behaviour is driven through the overlay and
therefore lives in E2e — the same harness limit the `src/Tree` entry above
describes. Reading them would mean grading Unit-suite mutants against tests that
are not in the Unit suite.

**The heuristic worth keeping from three reviews in one day:** go to the loop
skips and the lines with a cluster of survivors first. Both were real every time;
the concat operands, the `array_values` calls and the early-exit `break` were
equivalent every time.

## src/Lsp, and the heuristic paying for itself (2026-09-11)

2,220 mutants, 1,852 killed, **350 escaped**, covered MSI 84%. Reading 350 is not
a review, it is a second job — so this used the rule the three earlier runs
produced: **go to the loop skips first.** Four in the whole namespace, and the
filter took seconds.

Two were real, and both are the same defect wearing LSP clothes: a loop that
SKIPS a malformed entry, where stopping instead truncates everything behind it.

- `LspMapper::collectScopes()` drops a symbol that is not an array — servers do
  send the odd null in a symbol list. As a `break`, one bad entry early in a real
  response silently shortens the breadcrumb trail from there on, which reads
  exactly like a file with no more scopes.
- `WorkspaceEditor::refuseUnhonourable()` skips a change with no `kind`, which is
  every plain text edit. As a `break`, the FIRST text edit ends the validation
  and a resource operation behind it is applied unchecked — a refusal that exists
  to stop the editor deleting or overwriting something it should not. Every
  existing case in that data provider leads with the resource operation, so all
  of them stayed green. The spec allows edits before operations and servers do
  send them that way.

`NavigationController`'s call-hierarchy skip is the same shape and is left: its
tests drive the controller through a live session and do not live in Unit.
`LspClient`'s 96 escapes are the harness artifact `infection.json5` already
documents by name — `tests/Integration/LspClientTest` asserts that payload in
full.

**Four areas reviewed in a day, and every real gap in all four was a loop's skip
branch.** Not one was a wrong calculation or a bad boundary. The fixtures were
always well-formed and always had the interesting element first, which is what a
fixture written to demonstrate a feature looks like — and precisely not what a
language server, a working tree, or a user's palette query looks like.

## Sweeping the whole tree for one shape (2026-09-11)

Four area reviews produced the same answer every time, so this stopped reviewing
areas and swept `src/` for the shape alone: `@default: false` with only
`Continue_` and `Break_` enabled. **434 mutants, 84 escaped** — a complete census
of unexercised loop skips in the codebase, in one run, where the four area
reviews together had covered a fraction of the tree.

That census is the artefact worth having. It is not a to-do list — many are
equivalent, and many sit in code whose tests live outside the Unit suite — but it
is the whole population, and the shape is the one that has been real every time
it was real at all.

**Fixed from it:** `PluginLoader` walks every manifest and acts only on the
skipped ones, so it skips the plugins that LOADED. As a `break` that means "stop
at the first healthy plugin", and the skipped ones behind it get no warning —
the user is never told which plugin did not load, or why. Every existing case in
that file has *all* its plugins skipped, so there is no healthy one in front to
stop at; a real install is the opposite, several that work and one that does not.

**Two false starts, both fixture problems, both caught by re-running the mutant
rather than trusting the green:** the first ordered the plugins wrongly for
discovery, and the second skipped its plugin for a PLATFORM reason, which is
warned about somewhere else entirely and never reaches this loop. A test that
passes is not evidence that it tests anything.

**Judged and left, with the reason:** `EditorScroll`'s four are convergence-loop
controls, where `continue` and `break` differ only in whether the loop re-checks
a state it has already settled — the "term on both sides" case `infection.json5`
warns about. `PluginLoader:171` iterates a two-element array whose only nullable
entry is last, so stopping and skipping are the same thing. The rest of the 84
are unreviewed and now written down, which is more than was true this morning.

## Working the census: Save All, and indent detection (2026-09-11)

Two more off the loop-skip census, both chosen for blast radius rather than for
being interesting.

**`BufferService::saveAllBuffers()` skips a buffer with nothing to write.** As a
`break` that is "stop at the first CLEAN buffer" — so a dirty buffer behind a
clean one is never written, and the flash still reports success. The existing
Save All case dirties BOTH its buffers, so there is no clean one in front to
stop at. Open a file, read it, open another and edit that — the ordinary way
anyone works — and Ctrl+Alt+S would have written nothing.

**`IndentModel::detect()` skips a blank line**, which indents nothing and says
nothing. As a `break` the scan ends at the first one. Nearly every source file
has a blank line near the top, so the indent would come from the one or two
lines above it, or from the default — a file written with two spaces edited as
though it used four, silently, on most files in most projects.

Neither is exotic. Both are the plainest possible use of the feature, and both
were invisible for the same reason as every other one today: the fixture has all
its elements alike, and the interesting one first.

*Judged and left from the same batch:* `IdeConfig`'s template-map skip and
`EditorConfigGlob`'s `**` branch are the same shape and genuinely real, but each
needs a fixture built around an oddity (an invalid template entry; a `**` not
followed by a slash) rather than around ordinary use — worth doing, not worth
doing badly at the end of a long pass. They stay on the census.

## The two I said I would come back to (2026-09-11)

Both closed, and both were fixed by REORDERING an existing fixture rather than
by writing a new test — which is the clearest statement yet of what the census
is actually finding.

**`IdeConfig::templateMap()`** skips a template whose body is not a string. The
existing case already fed it two rejects; they were both at the END of the map,
where stopping and skipping produce the same result. Moved between the keepers,
with one more keeper behind them, and the difference appears: a single typo in a
`templates` block would have silently dropped every template after it.

**`EditorConfigGlob`**'s `**` branch, for a `**` that is neither followed by a
slash nor at the end of the pattern. Every existing case has it in one of those
two places — `**/foo.js`, `a/**/z.txt`, a bare `**`, a trailing `src**` — and in
both of those a translator that stopped after emitting `.*` answers the same as
one that carries on. `**.js` against `a/b/foo.txt` is what tells them apart: a
truncated pattern is a bare `.*`, and matches everything.

That second one is worth noting for what it says about coverage of a
translator: eighteen glob cases, every one of them exercising `**`, and none of
them in the position where the loop's control flow mattered.

**Five real defects off the census now**, and not one needed a new test file.
Four needed an existing fixture to stop having all its elements alike.

## The census has false positives, and tab names had the bug (2026-09-11)

Working further down the loop-skip census turned up something about the census
itself, which matters more than the entry it came from.

**Two of its 84 entries were already guarded.** `MenuModel`'s adoptable-id skip
and its separator skip are both reported as escaped, and both are killed outright
by `MenuModelTest` — which is in the Unit suite the run was judged against.
Infection runs only the tests its coverage map attributes to the mutated line, so
an imprecise attribution reports a mutant as escaped that the suite does in fact
catch.

So the census is a list of CANDIDATES, not of gaps. Four others spot-checked by
hand (`PaneSegmenter`, `TabBarWidget`, `LineDecorator`, `TabListProvider`) all
genuinely survive, so the false-positive rate is low — but every entry still has
to be mutated by hand before a test is written for it. Writing one for
`MenuModel` would have added a test that proved nothing and claimed a fix that
had happened years ago.

**Fixed from the confirmed ones:** `TabListProvider` resolves tab-name
collisions by walking titles and skipping any held by a single buffer. As a
`break` that stops at the first UNIQUE name, leaving every collision behind it
ambiguous. The existing test opens the colliding pair first and the unique name
last, where skipping and stopping are the same — reordered, it fails. Open a
file with a unique name before two that share one, which is most sessions, and
the two would have stayed indistinguishable.

*Still confirmed and unfixed on the census:* `PaneSegmenter:99/108`,
`TabBarWidget:189/209/287`, `LineDecorator:104/130`. Named so the next pass
starts from a verified list rather than the raw one.

> **CLOSED 2026-09-14, by running it rather than reading it.** All three
> entries are EQUIVALENT mutants, and the census can drop them.
>
> `infection --filter` over the three files: 343 mutants killed, 71 escaped —
> 36 in `TabBarWidget`, 20 in `PaneSegmenter`, 15 in `LineDecorator`. Of those,
> exactly three are the loop-skip class the entry names: one `Continue_`
> (`LineDecorator:130`) and two `Break_` (`TabBarWidget:189` and `:287`).
> `PaneSegmenter` has neither, which is the half of the note below that was
> right.
>
> **`TabBarWidget:189/287`** — a tab is active by IDENTITY against the one
> active buffer (`$b === $this->buffers->active()`), so two actives is
> unreachable and "first" and "last" name the same tab.
>
> **`LineDecorator:130`** — `$bounds` is deduplicated through an array KEY and
> sorted, so it is strictly increasing; only the final iteration can reach the
> guard, where continuing and breaking are the same act. Measured, not argued:
> 21,000 span configurations over seven lines, mutant and original
> byte-identical.
>
> The reasoning is in the code at all three sites now, which is what this file
> does with an equivalent mutant — a census entry with no note beside the line
> gets re-confirmed by hand every round, which is what happened here three
> times.
>
> The other 68 escapes are mostly `IncrementInteger`/`DecrementInteger` and
> comparator mutants, which `infection.json5` already says are almost always
> equivalent. They are UNEXAMINED, and saying so is the point: this closes the
> three entries it measured and claims nothing about the rest.
>
> ---
>
> *Superseded, kept for the correction it contains —* **stale as of
> 2026-09-13.** `PaneSegmenter` is closed —
> `PaneSegmenterTest::testARegionThatMissesTheRowDoesNotStopTheWalk` puts a
> non-covering region BEFORE a covering one, which is exactly the ordering the
> mutant needs. The other two were re-read rather than re-run and the line
> numbers no longer point where they did, so treat all three as a list to
> re-derive with `vendor/bin/infection --filter=<file>` before writing anything
> against it. A census entry is a claim with a date on it, and this one has
> outlived two of its three subjects.

## A highlight that bled to the end of the line (2026-09-11)

`LineDecorator::applyAll()` paints overlapping spans — a search highlight under
a selection under a diagnostic — and closes them by walking the open ones in
REVERSE application order, skipping any that do not end at this column. As a
`break`, everything below the first non-matching span stays open.

The case that shows it has to be built deliberately: the span that ENDS here
must sit at a lower index than one that continues, so the reverse walk meets the
survivor first. Every existing multi-span case has them the other way round,
where skipping and stopping agree.

Measured rather than described, because the output is a string of escapes:

```
correct: <A>ab<B>cd</A><B>efgh</B>
mutant : <A>ab<B>cdefgh</B><A>
```

The first decoration never closes and paints everything after it — a selection
or a search hit smeared across the rest of the line — plus a stray re-open at
the end.

Its two siblings in the same method, both on the census:

- The OPENING loop's skip is killed by the same test, which is a fair result
  rather than luck: the case exercises both walks.
- The `$next <= $col` skip is **equivalent**, and this time checked by running
  it over five span layouts rather than by arguing from the bound invariants —
  the output is byte-identical in every one. Bounds are ascending and unique and
  no span survives clamping past the line width, so that branch can only be the
  final bound, where stopping and skipping are the same thing.

That is now the third equivalent mutant confirmed by running and the second real
one confirmed by running, in a day where reasoning got it wrong in both
directions. The rule is holding: construct the discriminating case and RUN it.

## Three mutants in the tab strip, and a fixture that hid all three (2026-09-11)

`TabBarWidget` carried three census entries. All three survived for the same
reason, and the reason is a fixture, not a bound.

**Two of them are the active-tab scan**, once for the horizontal strip and once
for the side strip — the same six lines written twice:

```php
foreach ($parts as $i => $candidate) {
    if (!$candidate['active']) {
        continue;      // → break
    }
    $activeIndex = $i;
    break;
}
```

Turned into `break`, the walk stops at the first tab and leaves `$activeIndex`
at its initialiser, 0. The overflow window then follows tab 0 forever: open a
dozen files and the strip shows the first few while you are editing the last,
with no way to scroll to what you are typing in.

Nothing caught it because the test that covers the line is called
`testTrailingTabsDroppedWhenActiveIsFirst`, and its name is the whole problem —
with the active tab first, `$activeIndex` is 0 whether the loop walked or
stopped. Both new tests put the active tab LAST and assert the first tab has
scrolled off; each kills its own copy.

**The third is the packing loop's exit**:

```php
if (($used + $part['width']) > ($overflow ? $columns - 1 : $columns)) {
    break;             // → continue
}
```

This one looked equivalent and is not. Tabs are drawn left to right in list
order, so stopping and skipping differ the moment the widths differ: `continue`
walks past the tab that overflows and draws a later, NARROWER one in the gap it
left. Measured rather than argued, three tabs at 40 columns:

```
correct: ‹ long-name-000000.php × │
mutant : ‹ long-name-000000.php × │ a.php × │
```

The strip is now out of order — a tab appears between two that are adjacent
nowhere — and `$last` reaches the end of the list, so the `›` more-tabs
indicator goes blank while tabs are still hidden. The row measures exactly the
width it claims either way, so `assertFitsEveryWidth` has nothing to say about
it, and every fixture in the file gives its tabs equal widths, where the skipped
tab could never fit either.

That is the same finding for the third time today from a different direction:
**a fixture whose elements are all alike cannot grade a loop's skip.** The three
tests added here differ from the existing ones only in where the interesting
element sits and whether the elements differ in size.

Census position after this round: `TabBarWidget` closed (3 entries),
`PaneSegmenter:99/108` still to review, and roughly eighty entries unread.

## The RTL segmenter's two skips, and what the E2e suite does not see (2026-09-11)

`PaneSegmenter::runsForRow()` carried the last two verified-surviving entries on
the census. Both are the row filter, once for leaves and once for dropdown
regions:

```php
foreach ($leaves as $index => $leaf) {
    if ($row < $rect->row || $row >= ($rect->row + $rect->rows)) {
        continue;      // → break
    }
```

Two tests already cover those lines — `testARowOutsideAPaneIsNotOwnedByIt` and
`testARegionOutsideTheRowIsIgnored` — and neither can grade them, because each
hands the walk a SINGLE element. With one element, skipping it and abandoning
the walk end in the same place.

The docblock for the fix started out claiming the menu bar is the first leaf, so
the walk would abandon on every row below it. That is the kind of claim that
reads like a verified one, so it was measured instead — a probe in
`runsForRow()` under a running IDE at 160x24:

```
leaf 0  row 0   1 row   cols 0..159   RTL   (menu bar)
leaf 1  row 1   1 row   cols 0..128   RTL   (tab strip)
leaf 2  row 2  21 rows  cols 0..128   LTR   (code)
leaf 3  row 1  22 rows  col  129      RTL   (splitter)
leaf 4  row 1  22 rows  cols 130..159 RTL   (sidebar)
leaf 5  row 23  1 row   cols 0..159   RTL   (status bar)
```

The claim holds, and it is worse than described. Leaf 0 misses every row but the
first, so `break` leaves rows 1..23 owned by nothing at all — one uncovered
verbatim run apiece. Row 2 is the sharpest: the two runs it should produce (LTR
code to column 128, RTL sidebar from 129) collapse into a single verbatim run
and the sidebar stops mirroring. Under an RTL locale that is the entire UI below
the menu bar rendered the wrong way round.

**And the RTL E2e suite does not notice.** Ten cases, 102 assertions, all green
with the leaf walk abandoned after leaf 0. That is worth more than the fix: the
suite that exists specifically to prove mirroring works passes with mirroring
switched off for every row but one. It asserts the pieces — that a string comes
back mirrored, that a region is RTL — and never that the SCREEN was segmented.
Consistent with the standing rule that the defects live at the joins, and a
reminder that an E2e suite can be an expensive way to test a unit.

The two new unit cases each put a non-covering element FIRST and a covering one
after it, and each kills its own mutant. Census: `PaneSegmenter` closed, and
with `TabBarWidget` closed earlier today there are no verified-surviving entries
left — roughly eighty unread ones remain, which is a population, not a backlog.

## Reading the mutation log wrong: timeouts are not escapes (2026-09-11)

The census this session has been working from was built by grepping numbered
entries out of `coverage/infection.log`. That log has **two** sections, and
Infection numbers them independently:

```
Escaped mutants:      (7 entries)
Timed Out mutants:    (21 entries)
```

A grep for `^[0-9]+\) /Users` collects all 28 and loses which section each came
from. Every `Document.php` entry in the census was a TIMEOUT, and hand-mutating
all seven — BOM strip, the ternary, three match arms, both `str_replace`
variants — killed every one against `tests/Unit/Editor`. The reason is visible
in the coverage map: line 1420 is attributed **1,997 covering tests**, and the
config's per-mutant budget is 45 seconds. A heavily-covered line cannot finish,
so it times out, and a timeout says nothing whatever about a gap.

Two theories were checked and discarded before the right one: a stale coverage
map (`coverage-xml/Editor/Document.php.xml` is 03:19, fresh) and a test not
attributed to the line (`DocumentAdoptTest` appears six times against 1420).
Both would have been comfortable explanations. Neither was true.

So the standing "~80 unreviewed census entries" is not a backlog of gaps; it is
an unknown mixture of gaps and lines too well covered to mutate in 45 seconds.
Future rounds read the sections separately.

### What the seven real escapes were

- **`ProjectSearchController:509`** — already closed at 03:28 by
  `test(search): the too-complex flash needs a floor`, eight minutes after the
  log was written. The log was stale for this one.
- **`BufferManager:132`** — `is_file($path) ? stat($path) : false` mutated to
  `: true`. `is_array(true)` is false, so the method returns null either way.
  Equivalent, and no test can be written that says otherwise.
- **`BufferManager:134` ×4** — the `dev.':'.ino` separator moved, removed, or
  the device dropped. The separator guards against digit-run ambiguity across
  devices: dev 1/ino 23 and dev 12/ino 3 both spell `123` without it. Measured
  on this machine, every path a test can reach reports the SAME device —
  16777232 for the checkout, the temp dir, `/usr/bin`, and the read-only system
  volume alike, because APFS firmlinks present one device. With `dev` constant
  the concatenation is injective on `ino` alone, so all four mutants are
  equivalent for every input a portable test can construct. The separator stays
  because it is right for a mounted second volume, not because a test holds it.
- **`PortaJsonWriter:410`** — real, and fixed below.

## A swallowed warning that would have printed on the screen (2026-09-11)

`setAside()` moves an unreadable `.porta.json` out of the way before overwriting
it, and swallows a failed rename on purpose — the courtesy must not stop the
setting from saving. The swallowing is `set_error_handler(static fn (): bool => true)`,
and mutating that `true` to `false` survived.

The two existing tests both rename SUCCESSFULLY, so the handler never has an
error to swallow and its return value cannot matter to them.

The new case makes `.porta.json.invalid` a non-empty DIRECTORY, which is a
rename POSIX refuses — no permission trick, so a suite running as root sees the
same failure.

The interesting part is what to assert. The obvious assertion — that the test
runner notices — does not work, and the first attempt at this test failed for
that reason: **a handler returning false hands the warning to PHP's BUILT-IN
handler, not to whichever handler was installed before it.** PHPUnit never sees
it and the suite stays green. Measured instead:

```
return true    output=0 bytes     error_get_last=null
return false   output=205 bytes   error_get_last=SET: rename(...)
```

Those 205 bytes are `Warning: rename(...)` written straight to the terminal
Porta is drawing on — the over-wide-row garble family, arriving through a
settings write. The test asserts on the output and on `error_get_last()`.

Found alongside it: `edit()`'s docblock had been stranded on `setAside()` when
that method was inserted between it and its method, so a block documenting
`@param \Closure $mutate` sat on a method taking `string $file`, and `edit()`
had none. Moved back. No gate reads prose, which is the whole reason it sat
there unnoticed through a full green run.

## Drag and drop, round 2: reach, feedback, and a keyboard twin (2026-09-11)

The tree drag landed complete on 2026-09-10/11 — move, copy, collision prompts,
refusals, Escape, 42 tests. Reading the implementation rather than the plan
turned up three limits, and the third is the one that matters most.

### Reach: it could only land on what was already on screen

There is no auto-scroll anywhere in this codebase. Every other drag CLAMPS the
pointer into its rect (`MouseRouter::clampedRelative`, plus two inlined copies),
which is right for a text selection — the document scrolls with the caret — and
useless for a tree, where the target is a row. A drag could not reach a folder
below the fold, and could not reach into a collapsed one at all. On a fresh
session most of a project is both.

Two timers on `TreeDragRouter`, both cancelled by the existing `disarm()` so
release, Escape and the fresh-press sweep already reach them. The repeat is a
CLOCK rather than a motion event because a pointer resting on the edge sends
nothing — the terminal reports motion, not position — so without one the scroll
would stop exactly when the user is waiting for it.

`ListSelection::scrollWindow()` is the new seam, and it has to cooperate with
`follow()`, which runs every render and pulls the window back to CONTAIN the
selection. A window moved past the selection would snap straight back. So the
selection is dragged along, but only when it would otherwise fall outside — the
smallest change that leaves `follow()` with nothing to undo.

The spring-load dwell survives as long as the TARGET is unchanged, not as long
as the pointer is. A pointer is never perfectly still; restarting on each jitter
means the folder never opens, which looks exactly like the feature not existing.

### Feedback: the drop target was the hover tint

`FileTreeWidget` painted the drop target `merge(RowHighlightBg, TabAccent)` and
the hovered directory — four lines below, in the same `match(true)` — with the
same two roles in the same order. Byte-identical. "Your files are going here"
and "the pointer is here" were the same row.

The original justification was sound and beside the point: a button-held motion
is `isLeftDrag()`, never `isMove()`, so the hover router is dormant during a
drag and there is no competing highlight. True, and it does not make the two
distinguishable to a human. That docblock had also survived the change that made
it wrong, which is the usual way — no gate reads prose.

`Role::DropTargetRow` costs four theme maps. Monochrome is why it could not stay
a merge: there it must be a mark distinct from both the hover's underline and
the selection's reverse, because a drag can rest on a row that is already both.
The lint pin for enum size moves 60 → 61, re-bisected to confirm 60 still fails.

The row also carries a count, which is the only thing that answers "what" — on a
multi-selection drag the rows travelling may be scrolled off screen. It is a
bound WITH a floor: under eight columns for the name the label goes entirely,
because a row whose visible width disagrees with its declared width throws out of
the render loop and takes the session with it. `NarrowPaneRenderTest` now sweeps
a mid-drag tree; it had only ever seen a bare idle one, so that row had never
been width-checked at all.

The status-bar hint is **withdrawn at the release**, and the full suite is what
found the need: it covered the filename that the drop-on-editor case puts in the
status bar, which is that gesture's only confirmation. Withdrawal is guarded by
the text, so a message posted since is never swallowed.

### The third limit: it was the only way

Moving a file across directories required a pointer. `ActionCatalog` had two
tree entries, neither touching a file; New File, Rename, Delete and the drag
were all mouse-only closures. Cut/Copy/Paste now sit on the menu and on
ctrl+x/c/v, and the paste dispatches the SAME `TreeDropRequested` — so the
prompts, the refusals, the buffer re-pointing, `FileRenamed`, the LSP didClose
and the single `WorkingTreeChanged` are inherited, not reimplemented.

The chords collide with the editor's cut and paste deliberately: separated by
FOCUS, not by chord, so the same key means the same verb on whichever thing you
are looking at. `EditorKeymap` owns them for text and never reaches the command
registry; these are gated on tree focus, and the dispatcher checks commands
before falling through to the focused widget. They are hidden from the palette,
since opening it takes the focus they depend on.

### What the mutation sweeps cost, and what they bought

Thirty-odd mutants across five commits. Two real defects and six real test gaps,
and the gaps are the same shape over and over — **a positive with no floor**:

- The pane's header row is not an entry, so counting it left the scroll window a
  row too tall and stopped one row early. The row it could never reach was the
  last in the project — the one the feature exists to reach.
- The dwell's memory of which folder it was counting for was not dropped when
  the drag ended, so a second drag onto the same folder never armed one. A
  feature that works once per folder per session.
- Nothing asserted the payload label reached the ROW. The widget and the router
  were each tested alone, so passing no label at all left both green.
- Nothing asserted the hint is posted ONCE; re-posting on every motion event
  would wipe anything else the status bar said for the whole drag.
- Nothing asserted a cut is spent by its paste, that a copy is not, that Paste
  is ABSENT with an empty clipboard, or that the chords are inert in the editor.
- The file-row paste asserted a file the previous step had already created, so
  it could not tell the two targets apart.

And the spring-load row lookup was one more instance of the loop-skip shape that
has turned up all session: every test sprang `src/`, the first row, where
skipping and stopping agree.

Two process notes worth keeping. A python edit script that asserts before its
single write leaves the file untouched when a later step misses — hit again
here, fixed again by writing after each edit. And `exit=124` is a TIMEOUT, not a
kill: three mutants reported it, and running them alone showed one failing
cleanly in nine seconds. A mutation sweep over E2e needs a per-run cap near the
clean runtime, or a hang and a kill are indistinguishable.

## Mutating the drag round, read by section this time (2026-09-11)

`composer mutate` over the whole six-commit diff, judged against Unit: 159
killed, 23 escaped, 8 timed out, MSI 87%. The two lists were read SEPARATELY,
which is the correction from earlier today — every `DarkTheme` entry was a
timeout and says nothing, and reading them as escapes would have sent the round
chasing colour constants.

Of the 23 escapes, six were real gaps, two are equivalent, one is a harness
artifact, and one was a false positive.

### The one that mattered: a label on every row

`dropTrailing()`'s guard is `$node->path !== $this->dropTarget || '' === $this->dropLabel`,
and BOTH arms escaped — `!==` inverted to `===`, and `||` to `&&`. Either paints
the payload label down the entire tree.

It survived because every test read the pane as ONE string:
`assertStringContainsString('3 files', $rows)` is satisfied by the label being on
the right row, on the wrong row, or on all of them. That is the aggregate form of
the same mistake this session keeps finding — an assertion that cannot fail for
the reason it was written. The new case renders to an array and asserts the
label is on row 0 and on no other row, which kills both arms and the spacing
mutant beside them.

### Two off-by-ones on the same subtraction, from opposite sides

- `edgeDelta()`'s bottom bound, `rows - 1` → `rows - 2`: a two-row edge zone
  starts scrolling while the pointer is still over a row the user is aiming at,
  and the tree slides out from under the drop. The existing cases drag ON the
  edge and in the MIDDLE, which between them leave the zone free to be any depth.
- `scrollDuringDrag()`'s window, `lastRows - 1` → `lastRows - 2`: a window too
  SHORT sails past the end into filler rows. The last-row case added during the
  original round pins the other side — a window too TALL stops short of the final
  row. Both bounds are now nailed, from both directions.

### Also closed

- The high-contrast drop-target shade. `ThemeLockTest` pins Dark and Light
  exactly and deliberately leaves HC's table free to be tuned — but the drop
  target is accessibility-critical in the same way the selection is, so it joins
  the spot-locks, with assertions that it equals neither the selection nor the
  hover tint in that theme.
- The router's label FORMAT (`← 1 file`), which four Concat mutants could
  reorder or strip while every assertion about the count still passed.
- `StatusBarWidget::withdrawFlash()`'s `invalidate()`. Clearing without redrawing
  leaves the stale line on screen until something else happens to invalidate —
  precisely the case nobody is watching, since the withdrawal exists to get out
  of the way of the next message.

### Left alone, with reasons

- **Equivalent, both confirmed by running rather than argued.** The row lookup's
  `break` — paths are unique, so the walk finds the same node either way and only
  the cost differs. And `$width - visibleWidth($marks)` → `+`: the drop target is
  always a directory, directories carry no marks, so that term is zero on the
  only row where the value is used.
- **A harness artifact**, verified by the procedure `infection.json5` prescribes
  rather than assumed: deleting the `tree_cut` registration leaves the Unit suite
  green and turns the E2e red. Its coverage lives outside the judged suite.
- **`$row <= 1` → `<= 0` was a FALSE POSITIVE** — hand-mutating it fails two
  existing tests. Infection reported an escape for a mutant the suite kills,
  which is the third time this session the census has been wrong in that
  direction. Every entry still has to be mutated by hand before it is believed.
- Three `requestRender()` removals inside the drag timers. They change when a
  frame is asked for, not what is in it, and this repo has no seam that observes
  a render request without a real terminal; noted rather than pinned.

## Fixing RTL: two functions, one slicer, and four claims (2026-09-11)

The bidi stack was sound in its parts and wrong at its edges. `I18n/Bidi` is a
real UAX#9 subset with 34 tests; the defects were all in what it was told about
the characters it was given, and in what the suite around it never asked.

### classify() defaulted to "this is Latin text"

The function is an allow-list of neutral ranges with `return 'L'` at the bottom,
so every codepoint nobody had thought about was declared strong Latin. **Forty
of Glyphs' seventy-six constants were** — ▸ ▾ → ← × ✓ ● ◆ █ ▎ and the rest.

A glyph classified that way glues itself into the adjacent Latin run instead of
taking the direction of the text around it:

```
before:  " ▸ src/      "  ->  "      /▸ src "
after:   " ▸ src/      "  ->  "      /src ▸ "
```

The sharper symptom was that it depended on the CONTENT. A Hebrew folder name
already came out right, so in a single list the disclosure marker sat on a
different side depending on the name beside it. That is the tell for a
classification bug rather than a layout one, and it is why the existing tests —
which use Hebrew strings — could not see it.

**Two derived gates, because the list is still a list.** Every string constant on
`Glyphs` must not classify as `L`; every key in the `MIRROR` table must be able
to REACH the mirror, since L4 only applies inside a right-to-left run. The second
caught `«` and `»`, which had entries they could never reach — dead code that
reads like a working feature. Proved load-bearing by adding a supplemental arrow
outside every range and watching the sweep name it. Third hand-written list this
session to be wrong by omission.

### No W4, so the caret indicator read backwards

`12:5` rendered `5:12`. UAX#9 W4 makes a single separator between two European
numbers part of the number; without it the colon resolved to the surrounding
right-to-left direction, split the two digit runs and swapped them. Dates,
decimals and thousands groups went the same way. It is the most-looked-at number
in the editor.

What this deliberately does NOT fix, because it is not a bug: `src/Foo.php:42`
still renders `42:src/Foo.php`. There the colon sits between Latin and a number,
and N1 has numbers act as right-to-left for neutral resolution, so it takes the
paragraph direction. That is what the standard prescribes; pinning such a
reference needs an isolate at the call site.

### A raw escape on the screen, every frame

`sliceByColumn(strict: true)` opens each slice by re-emitting the escape state
active at that column, and it counts the zero-width APC caret marker as state.
So every run to the RIGHT of the caret opened with a copy of a cursor belonging
to another pane; `ScreenWriter` strips only the first, and the survivor lost its
`\x07` terminator and swallowed the text after it. Only this path re-slices an
already-composed row, which is why LTR never saw it. `SpanSlicer` already
carried the same guard against the same vendored behaviour.

### The suite could not see mirroring stop

`IdeRtlLocaleTest` checked segmentation on row 0 and nowhere else — and row 0 is
the menu bar, the FIRST leaf, the one row that survives the segmenter losing its
leaf walk. The width invariant beside it is satisfied exactly by the degenerate
one-verbatim-run-per-row output that bug produces. Hence ten cases and 102
assertions passing with mirroring off for every row but one.

The new case asserts a row BELOW the menu bar carries both a verbatim run and a
mirrored one. Verified the way it had to be: with the leaf walk re-broken it
fails, while the original ten stay green.

`LtrPane`'s membership is now pinned too. It is an empty marker that the
segmenter reads as the whole mirror/verbatim decision, and removing it from
`CodeEditorWidget` failed nothing — source code would have been bidi-reordered
on the next frame.

### Four claims that asserted the opposite of the measured behaviour

- `Bidi`'s own scope note said digits are "reordered as left-to-right". True
  only inside one digit run.
- `WheelRouter`: "the strip renders mirrored under RTL". **Measured: a strip of
  LATIN tab names is not mirrored, only right-anchored** — order preserved. Tab
  order reverses only when the names are Hebrew, which for a code editor is the
  rare case. So the unconditional flip matches the screen for Hebrew filenames
  and opposes it for Latin ones, and one boolean cannot serve both. Left as it
  stands: `IdeTabScrollTest` pins the current choice, and changing it is a
  behaviour decision rather than a comment fix. Recorded here so it is a known
  open question instead of a confident comment.
- `LiveSettingsApplier`: the tab strip is "outside the RTL pane mirroring". It is
  outside the LAYOUT mirroring; `TabBarWidget` carries no `LtrPane` and is
  mirrored like any other chrome. The comment conflated the two mechanisms —
  the same conflation that let the column map go unguarded.
- `LtrPane` itself listed "search-results snippets" among code panes. The results
  and debug panels carry translated headings too, and those render in logical
  order beside mirrored chrome. Decided with the user to leave the behaviour and
  correct the prose, in the interface docblock and in three user-facing docs
  that claimed Hebrew "runs fully right-to-left".

## Mutating the RTL round: nineteen escapes, three shapes (2026-09-11)

`composer mutate` over the six-commit RTL diff, judged against Unit and read by
section: 135 killed, 19 escaped, MSI 87%. All nineteen were in code written that
same day, and they fell into three shapes worth naming.

**Separators nobody tested.** W4 accepts six separators; the cases exercised four
(`: . , -`, via a line:column, a decimal, a thousands group and a date). `+` and
`/` were added to the list and never asserted. Both were correct — `1+2` and
`1/2` hold together — but a list of six with four tested is a list of four.

**Range ends no glyph occupies.** The derived glyph sweep only sees codepoints
the interface actually draws, so a neutral range whose EDGE no glyph happens to
sit on can be off by one and nothing notices. `0x21FF`, `0x257F`, `0x259F`,
`0x25FF` and `0x27BF` were all free to move. Pinned now by a boundary provider
in the file's existing idiom — the same treatment the R ranges already had, which
is the tell that this was an omission rather than a judgement.

**The ends of the walk.** `joinNumberSeparators()` reads its separator's
neighbours, and a separator in the first or last cell has only one. Every case
put it safely in the middle, so `$i > 0`, `$i < $last` and the `- 1` in
`$last` were all unpinned — and two of those mutants index past the array.
`:12` and `12:` pin them: the separator is an ordinary neutral there and moves
to the far side, which is both the correct answer and the discriminating one.

### The one that needed a different fixture

`substr($line, offset: 0, …)` in the caret guard survived, and the reason is
worth keeping. Shifting the offset by one drops the row's first character and
picks up the first byte of the escape that follows — and in an ASCII fixture a
lone `\x1b` measures exactly the one column the dropped character would have.
The two errors cancel, and every existing case was ASCII.

A row carrying a WIDE character does not cancel: dropping `日` loses two columns
and the stray byte returns one, so the measured width differs by one and the
caret is judged to belong to the pane before its own. The new case uses a CJK
prefix, which is also the realistic setting — a CJK filename or an emoji in a
row that holds the caret.

That is the second time this session a mutant survived because the fixture was
all-ASCII, and the general form is the familiar one: **a fixture whose parts are
uniform cannot grade arithmetic that only differs when they are not.**

## The settings screen in Hebrew, and two fixtures that lied (2026-09-11)

Reported as "the settings UI is broken in Hebrew". It was, in two places — and
the round is most useful for how nearly it went wrong.

### The marks were inert

U+200E and U+200F were classified NEUTRAL. They are strong `L` and `R` by
definition; giving the neutral text beside them a direction is the whole reason
they exist. So the one tool Unicode provides for this problem did nothing, and
the Hebrew catalog already ships **nine strings using them**.

They also measured one column and drew none, so a row carrying one rendered
narrower than every width function believed — a toast padded to 30 columns drew
29 — and the RTL width invariant could not see it, measuring with the same
function that was wrong.

Rather than teach every measurer about zero-width characters, a mark is now
SPENT: it does its work in `levels()` and leaves a space behind. The arithmetic
never changes and the screen agrees with it.

### What was actually broken on screen

With real translated labels, two rows:

```
Theme        < dark > · light   ->   dark > · light >
Indent size  < 4 > · 1–8        ->   8–1 · < 4 >
```

The stepper's opening `<` had strong R on one side and strong L on the other,
took the paragraph direction, left for the far side of the group and mirrored on
the way — two `>`, and the current value no longer bracketed. The range reversed
because an en dash is an ordinary neutral, so W4 never joins the numbers either
side of it; a hyphen is a separator and does.

### Two fixtures that lied, in opposite directions

**The first overstated the damage.** An early read had the checkbox breaking too
and the keybindings chord column flipping sides per row. Both came from labels
invented for the fixture: all 191 action labels and every settings label are
genuinely translated, and with real content those rows were always fine. Half an
hour spent on defects that did not exist would have been the cheaper mistake.

**The second understated it, twice.** Marking only the front of the control
fixed the stepper and broke the checkbox — `[x]` became `[ [x`, because its
closing bracket still had the row's padding on its right. Marking the whole
value region fixed that and broke the SCOPE row, whose detail clause is Hebrew
where the theme row's is a Latin option name: prose wrapped in a left-to-right
region stops being prose. Only bracketing the control — machine data — while
leaving the detail outside holds every shape.

Each wrong answer passed the test written for the row in front of it. The
general form is the one this session keeps producing: **a fixture with one
example of a thing cannot grade a rule about the thing.** The provider now
carries a choice, a bool, an action, an int, and a choice whose detail is
Hebrew.

And the second of those was only caught by driving the real settings screen
through the segmenter and the per-pane reorder. The widget renders in logical
order and looks perfect there; every one of these defects exists only after the
mirror.

### The known limit

The int row still reads `1-8 · < 4 >` rather than `< 4 > · 1-8`. Its digits are
`EN` rather than `L`, and no mark can make an all-numeric group a left-to-right
unit — only an isolate can, and there is no isolate support. Both halves are
legible; the order inside the block is wrong. **This is the case that would
re-open isolates**, and the first concrete one.

### A defect that had nothing to do with Hebrew

`SettingsNavWidget::rowIndexAt()` counted from the top of the pane, while
`render()` reserves its first line for the ▲ indicator as soon as the entries
outnumber the pane — and reserves it even when nothing is scrolled, so the line
is blank rather than absent. Measured at 24×6: screen row 1 showed "General"
while the hit test answered "Interface". Clicking a settings category selected
the one below it, in every locale, and hovering tinted the wrong row.

Found by reading the same screen for a different reason, which is the argument
for looking at a real one.

## Mutating the settings round: two escapes, one real (2026-09-11)

`composer mutate` over the diff, judged against Unit and read by section: 35
killed, 2 escaped, MSI 94%. Both were in `SettingsFormWidget::gap()`, the two
columns between a label and its control that now spend one on a mark.

**Equivalent:** swapping the operands, so the mark precedes the space rather
than following it. Measured — the gap is two columns either way and the stepper
binds either way, because the neutral space between the mark and the control
resolves left-to-right from both sides. Nothing can tell them apart.

**Real:** dropping the space and keeping only the mark. The stepper still binds,
so every case written for it passed — but the gap becomes ONE column in Hebrew
and two in English, and the form quietly lays out differently per locale.

That the mark is paid for out of the existing gap rather than added beside it is
the reason the label column keeps its alignment, and it was asserted in a
docblock and by nothing else — `claims-are-not-guards` again, in prose I wrote
the same day. The new case renders the same row under both locales and requires
the control to begin at the same column.

## W7, and two "limits" that were mine rather than the standard's (2026-09-11)

Hunting the shape this session keeps producing — a Latin token inside Hebrew
prose — against the shipped catalog rather than a fixture. Every maximal Latin
run in all 757 Hebrew strings, checked for surviving the mirror:

```
before:  3 strings shatter a Latin token   (UTF-8 ×2, autoload.psr-4)
after:   0
```

All three were one shape: a hyphen between a letter and a digit. That is
**UAX#9 W7** — search back from each European number to the first strong type,
and an `L` there makes the number `L` too. Without it the digit stays a number,
numbers act as right-to-left for neutral resolution, and the separator in front
takes the paragraph direction and splits the token.

### The part worth recording is what W7 retired

Two things this session had written down as limits turn out to have been
mistakes of mine, not properties of the standard:

- **`src/Foo.php:42` → `42:src/Foo.php`.** Recorded twice — in `Bidi`'s docblock
  and in the commit that added W4 — as "what UAX#9 prescribes", fixable only by
  an isolate at the call site. That reading applied N1 without W7, which runs
  first: the line number is preceded by Latin, so it IS Latin by the time N1
  looks, and the colon has `L` on both sides. The standard gives the intact
  answer. It renders `src/Foo.php:42` now.
- **The settings int row's block order.** Recorded a few hours ago as needing
  isolates because "digits are `EN` rather than `L` and no mark can change
  that". A mark cannot, but W7 can — the mark in front of the control is strong
  `L`, so every number after it reads as Latin. The row now renders
  `< 4 > · 1-8` in logical order, matching the choice row beside it.

Both claims were argued from the spec and both were wrong in the same way:
quoting the rule that supported the conclusion and not the one that ran before
it. `claims-are-not-guards` is usually about prose outliving code; this is the
other failure, prose that was never right.

The hyphen introduced for the range stays, and is no longer what fixes the
settings row — measured: a range in bare Hebrew prose still needs it
(`1–8` → `8–1`), a range after a mark no longer does.

### Rule order is observably equivalent here

Running W7 before W4 rather than after survived mutation, and rather than
contrive a case, it was brute-forced: 400,000 random class sequences, of which
**3,804 produce different intermediate classes and 0 produce different embedding
levels**. A separator W4 declines to fold resolves to the same direction through
N1 that it would have reached as a number, so the difference is absorbed before
anything observable. The rules are applied in the numbered order because that is
the spec's order, not because this subset can tell.

## The other half of a settings row (2026-09-11)

The settings screen was reported broken in Hebrew a second time, and rightly:
the previous round fixed the VALUE side of a row and left the LABEL side broken.

A row is `label` + gap + `control`. When the label's own text ends in a Latin
run — 29 of the 757 Hebrew strings do — that tail is strong left-to-right, the
control beside it is too, and the two merge into one run. The control is then
drawn INSIDE the label:

```
שרת LSP                ->  LSP                [x]  תרש
עיצוב בעת שמירה (LSP)  ->  LSP)  [x] ) הרימש תעב בוציע
חיפוש בקובצי vendor/   ->  vendor/   [x]  יצבוקב שופיח
```

and the column goes with it. Across five real labels the checkbox landed at
columns **28, 33, 37, 46 and 28**. That is what the report meant.

The mark added last round is what binds the control; the label's tail joins the
same run. So the previous fix was a necessary half, and the gap now spends both
its columns: one mark to END the label's run, one to begin the control's.

### Why the first round missed it

Every fixture in it had a pure-Hebrew label. The round before found the same
thing (a fixture with one example of a row shape), and the round before that
(one example of a detail's direction). Three rounds, one shape:

> A fixture with one example of a thing cannot grade a rule about the thing.

The lesson that generalises is in the ASSERTION, not the fixture. "The label
survived" passes on a ragged form; **"every row's second column starts at the
same position"** does not. All three new cases assert the column.

### Three sites, because a defect here always has a second one

`SettingsFormWidget`, `KeybindingsListWidget` and `MenuPanel` each hand-roll a
two-column row, and all three tore. The menu needed the direction pushed in — it
is static, and neither menu widget carries a `Translator` — so both widgets got
a setter, the shape `BidiTui::setPaneReorder()` already uses, leaving thirteen
construction sites untouched.

**One wiring point, not two.** It was first wired in `WidgetHooks` for boot and
in `MenuLayoutRefreshListener` for the live switch; a mutation showed the boot
half was dead. `Ide`'s startup calls `SettingsController::applyAll()`, which
applies the persisted locale through the same setting a user switch does, so the
listener already fires at boot. Removing it gave back a constructor argument on
a class near the parameter ceiling.

### A guard that had nothing to do with direction

One surviving menu mutant added a gap column for the mark instead of spending
one. Nothing noticed — and the result is a segment one column wider than the
panel it is drawn into, which in this renderer garbles the rows beneath it.
Menu segments are now width-checked at four widths in both directions. The
over-wide row is this codebase's worst failure and the menu panel had no
assertion about it at all.

### Known and not fixed

`חיפוש בקובצי vendor/` renders `/vendor`: the trailing slash is neutral at the
string's end and takes the paragraph direction. Ending the label's run does not
reach inside it — that needs a mark within the catalog string. Recorded rather
than chased.

## Mutating the three-site round: four real, three artifacts, two equivalent (2026-09-11)

39 killed, 8 escaped, MSI 82%, read by section.

**Three are harness artifacts**, verified by the procedure `infection.json5`
prescribes rather than assumed: inverting the direction in
`MenuLayoutRefreshListener`, and removing either of its two setter calls, leaves
the Unit suite green and turns the E2e red. Their coverage lives outside the
judged suite.

**Two are equivalent, and the pair is guarded.** `KeybindingsListWidget` ends its
row with both a trailing space and `pad: true`, and either alone fills the pane —
so each mutant individually changes nothing. Removing BOTH fails all eight width
cases. Worth knowing that the redundancy is real rather than one of them being
untested.

**Two were real and both were about redraw**, not direction: setting the writing
direction on either menu widget did not mark it for repaint, so a locale switch
would have changed the layout and left the old one on screen until something
unrelated invalidated. The same class as `StatusBarWidget::withdrawFlash()`
earlier today.

**One was a width hole at a second site.** `KeybindingsListWidget` had no
assertion that a row fills its pane — the same hole found in `MenuPanel` during
the round itself, one file over. Its own comment already worried about the case
("a pane narrower than the shortcut got a row wider than itself — 8 columns into
1") and nothing checked it. Now checked at four widths in both directions.

Three rounds running, the thing that escaped was not the feature but the
invariant beside it: a repaint, a width, a column. The feature is what the test
was written for; the invariant is what nobody was looking at.

## The Hebrew settings screen: composition was never the defect (2026-09-11)

Reported four times. Three rounds had fixed real things in the bidi engine —
classification, W4 and W7, the mirror table, the caret duplication, the settings
label and value sides, a nav off-by-one — and the screen was still wrong. That
pattern is evidence in itself: what was being fixed was not what was broken.

So this round measured before it touched anything.

### What was established

A throwaway project (session scratchpad, nothing in the repo touched) booted the
real widget tree into a `VirtualTerminal` and graded every row of the composed
frame against an invariant with teeth: the row's `visibleWidth` equals the
terminal width, and — since reordering only ever PERMUTES glyphs inside a run —
no glyph may leave its pane run. `Bidi::SPENT` marks are normalised to a space
on both sides first, or every marked row reads as a false positive, which on the
first attempt they did.

Graded across 12 geometries × 11 sidebar widths × 4 tab-bar positions, including
the reporter's exact configuration read from their own files: **226 × 67**
(`stty size`), settings sidebar **8%** (`.porta/layout.json` — a value well
outside the range first guessed at), `tabBarPosition: right`, `lineNumbers:
absolute`, `language: he`. At that configuration the frame is correct:
`badRows=0`, every row exactly 226 columns, every glyph inside its own run, and
the content matching the screenshot line for line.

**Frame composition is not the defect.** `PaneSegmenter`, `PaneReorder` and
`Bidi` produce a correct frame at the exact settings that render wrongly on
screen.

Two candidate explanations were tested and dropped rather than argued:

- *The terminal re-mirrors the already-mirrored frame.* The reporter runs cmux,
  which renders through a bundled Ghostty; that binary carries no bidi
  implementation (every `bidi` string in it belongs to QUIC). This was a
  satisfying theory and it was wrong — it was nearly published as the diagnosis.
- *An overlay crossing the boundary.* A floating region legitimately becomes its
  own run in `PaneSegmenter::runsForRow()`. The boundary-crossing runs seen
  earlier were correct segmentation.

### The hole the round actually found

Every test in the suite grades a COMPOSED frame. Nothing graded the bytes
`ScreenWriter` writes — and those differ: after the first frame it switches to a
differential repaint that rewrites only the changed row range, positioning
itself with RELATIVE cursor moves (`CSI nA`/`CSI nB`) from its own row
bookkeeping. A writer like that is correct by construction on the first frame,
which is the only frame a full-render test ever exercises.

`tests/Support/TerminalReplay.php` closes that: it replays written bytes into a
cell grid, including the behaviour that matters — an over-wide row WRAPS, and
every row after it is one off. `DifferentialRepaintTest` drives several frames
and asserts the invariant the path owes: **a screen arrived at incrementally is
the screen a single render of the final content produces.** Stated that way it
needs no mirror arithmetic of its own; the full-render path is the oracle.

Run against the real settings screen over six incremental steps, the written
bytes match the intended frame exactly. **The write path is correct too.**

### The fragility that is real

`Glyphs` claimed in prose that its vocabulary was East Asian Width *Narrow*. It
never was. Measured against the Unicode table, **39 of the ~50 non-ASCII glyphs
are *Ambiguous*** — the box-drawing set, the arrows, the geometric shapes, and
the pane separator itself — and Ambiguous is one column only by convention. Feed
the same correct bytes to a terminal that spends two columns on them and **66 of
67 rows corrupt**, content splitting across rows exactly as reported, because
the wrap desynchronises the differential writer's row bookkeeping.

That severity is also why it is not being claimed as the reporter's bug: their
screen shows a handful of bad rows, not a total cascade. The claim is narrower
and verified — the assumption existed, was undocumented, was stated falsely, and
had no test. `GlyphsWidthTest` now pins one column per character; it kills a CJK
glyph and an emoji added to the vocabulary. (A first probe, U+23CE ⏎, was
assumed Wide and is Neutral — the test was right to pass, and the classification
has to come from the Unicode table, not from memory.)

`composer mutate` generates nothing for this diff: the only `src` change is a
comment. The mutation evidence is manual and recorded above, plus the one that
matters — an injected off-by-one in `differentialRender`'s cursor math fails 5
of the 7 new cases. That is the bug class the screenshot looks like, and it is
now caught.

### Still open

The reporter's screen is not yet explained. What is now excluded, with
measurements rather than argument: frame composition, the mirroring, the
segmentation, the differential write path, and the terminal's own bidi. Three
rounds were spent inside the first two of those.

## The Hebrew settings screen, found: a foreign write spliced into a frame (2026-09-11)

Four rounds inside the bidi engine, then a round proving the bidi engine was
innocent, and then this. The defect was never in what Porta composed — it was in
what reached the tty.

### The evidence

`script -q /tmp/porta.raw ./bin/porta` on the reporter's own machine, 357KB of a
real session. Three facts fell out of it immediately:

1. Of 977 row writes, **930 measure 227 columns and 47 measure 226** — and the
   226s are all the status bar. Rows should all be exactly the terminal width.
2. The capture contains **no `CSI 2J` and no `CSI H`. Anywhere.** After the first
   append, every frame is positioned with RELATIVE cursor moves against
   `ScreenWriter`'s own `hardwareCursorRow`. Nothing ever repositions
   absolutely, so a cursor that desyncs once stays wrong for the whole session.
3. Three escape sequences in the stream do not parse. One of them is the whole
   bug, at offset 4146, inside the first frame:

```
ESC[38;   ESC[>7u   5;240m 11
```

An SGR — the line-number colour — cut in half by `CSI >7u`, the kitty keyboard
enable. The tty aborts the incomplete CSI, consumes the kitty sequence, and then
prints `5;240m` as **six literal characters**. The row is six columns too wide,
it wraps, and by (2) every differential repaint after it lands on the wrong row
for the rest of the session.

### Why it could not be reproduced

`Terminal::write()` is `fwrite(\STDOUT, …)` — direct and unqueued — and it is
called from the *stdin data handler*, when the host answers the `CSI ?u` query.
`NonBlockingTerminal` queues frame bytes and flushes them as the tty accepts
them, so the reply can arrive mid-flush and splice itself into a partial frame.

None of that happens under a `VirtualTerminal`: there is no host, no query, no
reply. Every reproduction attempt — 12 geometries, 11 sidebar widths, 4 tab-bar
positions, the reporter's exact `layout.json` — was therefore guaranteed to come
out clean, and did.

`NonBlockingTerminal`'s own docblock described this write as a "one-shot 7-byte
kitty enable [that] still writes directly **at boot**". It is not at boot. It is
whenever the host replies, long after the stream went non-blocking. The comment
was the reason nobody looked at it — [[stale-justifications-survive-moves]] again,
and this time the stale reason hid a real bug for four rounds.

### The fix

The bytes cannot be intercepted: the inner terminal is final and writes to
STDOUT itself. So the damage is REPAIRED at the one moment it can occur.
`NonBlockingTerminal` wraps the kitty callback (the inner writes `CSI >7u`
immediately *before* invoking it) and exposes `onKittyActivated()`; `Ide` wires
it to `BidiTui::resyncScreen()`, which forces a full render — clear, absolute
home, every row rewritten.

`resyncScreen()` is one line. It was three: a mutation proved that resetting the
screen writer by reflection changed no test, because `Tui::requestRender(true)`
already resets it. The redundant lines came out.

Two mutants had to be chased down rather than assumed dead:

- `requestRender(true)` → `requestRender()` survived at first because the
  redundant reset was still masking it. With the method reduced to its one real
  line, it fails the case.
- Removing the wiring in `Ide.php` changed no test at all — the feature would
  have been dead with every gate green ([[porta-self-registering-services]] in
  another costume). `IdeHelpersTest` now asserts the hook is installed, and that
  mutant dies too.

9 of 9 mutants killed, MSI 100%.

### What this says about the render path

The deeper fragility is (2): frames are positioned relatively and nothing ever
resyncs. Any stray byte from any source — a plugin, a signal handler, a library
writing to stdout — corrupts the screen permanently rather than for one frame.
The kitty enable is the instance that was found; it is unlikely to be the only
possible one. A periodic or suspicion-driven absolute repaint is worth
considering, and is deliberately NOT done here: this round fixes the defect with
evidence for it, and speculating a second mechanism into existence is what the
previous four rounds did.

## The Hebrew settings screen, actually found: a toast truncates the pane under it (2026-09-12)

Five reports. The previous round proved composition correct and the write path
correct, and closed with the defect unexplained. It was explained by a second
screenshot — of the General section, where the rule finally showed:

- rows whose nav entry is HEBREW (`הדגשה`, `קיצורי מקשים`, `תוספות`) were swapped
  with the form label beside them;
- rows whose nav entry is LATIN (`chroma`, `git`) were correct, matching the
  harness character for character.

A rule, not an impression — and the lengths corroborated it: the label column
held a 5-character word where an 11-character one belonged, and vice versa.

### What it is

`runsForRow()` assigned every column an owner, and a floating region overrode
the pane beneath it. So a toast sitting over the right of the screen TRUNCATED
the settings form's run from `0..206` to `0..179`, and `PaneReorder` then
mirrored that shortened span as if it were the whole pane — re-right-aligning
every glyph in the row about 28 columns left. RTL only: in LTR nothing is
reordered, so nothing moved.

Measured on the real settings screen, raising one toast changed **four rows
across columns 127..224**. It now changes four rows inside the toast's own box,
`180..224`, which is what a floating surface is supposed to do.

### The fix

Two layers instead of one. `segment()` returns the panes, whole;
`segmentRegions()` returns the surfaces. `reorderRow()` blanks the surfaces'
columns, reorders each pane over its FULL span against that hole, and stamps the
surfaces back at their own columns.

Both halves are load-bearing and both were mutation-checked: without the
blanking the pane pass carries the toast's glyphs along and paints a second copy
of the box elsewhere in the row (observed, not theorised); without the full-span
reorder the pane slides.

The invariant the tests pin is positional, and deliberately NOT "nothing outside
the region changes" — reordering maps logical column `c` to visual
`span_end - 1 - c`, so the hidden cells leave their gap at the MIRRORED span,
not at the surface's own. What must hold is that no pane glyph moves. Writing
the stronger claim first is what caught this: the test failed, and the failure
was the test's, not the code's.

### What the round cost, and why

This signal was in hand early: a toast producing runs that cross the pane
boundary was observed, written down, and then explicitly dismissed in a plan as
"correct segmentation, not a bug". It was the bug. Three later rounds went into
the bidi engine, which was never at fault.

Two further self-inflicted detours are worth recording because both produced
confident, wrong conclusions:

- Reading the captured session with Python's TEXT mode silently converted every
  `\r\n` to `\n`. The emulator then never saw a carriage return, invented 1046
  wraps, and yielded "930 rows at 227 columns" — from which a terminal width of
  227 was inferred and reported. Read captures with `newline=''`.
- Terminal bidi was ruled out twice on bad evidence (a grep for "bidi", and a
  naive whole-line-reversal model that predicted blank right-hand columns), then
  re-opened, then finally settled properly: Ghostty 1.3.2 ships no bidi
  implementation and no bidi config key, while its config keys ARE present in
  the binary as exact strings, so their absence is real evidence.

### Dead code found by mutation

`blankRegions()` carried `max(0, …)` on both the blank width and the tail
length. Every mutation of either survived, and removing both changed no test:
`regionRunsForRow()` never yields an empty surface and `sliceByColumn()`
tolerates a tail past the row. They were defensive code nothing could reach, and
they are gone; the width invariant is an `assert` instead, which is also what
satisfies mago.

Some mutants remain alive on the two `sliceByColumn(strict: true)` flags and the
prefix concatenation. They are not claimed as killed: asserting on stripped ANSI
cannot see `strict`, and the one test that asserts on raw SGR passes either way.
Recorded rather than papered over.

## A frame dump, because five rounds were argued from photographs (2026-09-12)

The toast fix was real and is not the whole story: the screen is still reported
wrong. Continuing to reason from screenshots is what made the previous rounds
expensive, so this adds the instrument that ends the argument.

`PORTA_FRAME_DUMP=<path>` makes `Ide` hand `BidiTui` a frame sink, and every
frame's FINAL lines — after the per-pane reorder, exactly as they go to the
terminal — are written there with ANSI stripped. A screenshot then says what the
terminal drew; the file says what Porta composed. Whichever half is at fault
stops being a matter of reading Hebrew glyphs off a photograph.

It belongs in the same family as `.porta/error.log`, `freeze.log` and
`perf.log`, and `getenv()` in `src` already has precedent (`PortaHome`,
`ServerLocator`, `PluginLoader`).

### A correction to the previous entry

That entry said the reporter's capture proved Porta writes the row unswapped,
and inferred that the corruption therefore happens after Porta's bytes. That
inference rested on ONE row: the settings title appears exactly once in the
whole 357KB, and it is left of the separator. A single sample from a session
that may simply not have reproduced the bug is not proof of anything, and it was
reported as if it were.

What the capture does support, and no more: in that session, in that frame,
Porta composed the row correctly.

The other leads from that round were each run down properly before being
dropped, and are recorded so they are not re-opened for free:

- **Terminal bidi is out.** Ghostty 1.3.2 carries no bidi implementation and no
  bidi config key, while its config keys ARE present in the binary as exact
  strings — so their absence is evidence, not silence. cmux adds no CoreText
  line layout (`CTLineCreateWithAttributedString`, `CTTypesetter*`,
  `kCTWritingDirectionAttributeName`: all absent) and no ICU (`ubidi_*`: absent),
  so it is not reordering through the platform's text stack either.
- **The bidi engine is out**, measured across 12 geometries, 11 sidebar widths
  and 4 tab-bar positions at the reporter's exact configuration.
- **The differential write path is out**, graded byte-for-byte by
  `TerminalReplay` over six incremental frames.

## The Hebrew settings screen: the reordering is CoreText, in the terminal (2026-09-12)

Porta's output is correct. The second reordering pass is cmux's renderer.

### The evidence, both halves measured in one run

Porta was driven in a REAL pty from the harness — `script` around `bin/porta`,
`stty cols 226 rows 67`, the settings view opened by binding `ide_settings` to
`alt+s` through `.porta.json`'s keymap (ctrl+, is not encodable without the
kitty protocol). `PORTA_FRAME_DUMP` captured what Porta composed and `script`
captured the bytes:

- composed frame: `... תורדגה 5 — יללכ │ יללכ` — title left of the separator,
  nav entry right. Correct.
- replaying the captured bytes: the same, and **wraps=0** (the kitty splice fix
  holding).

So Porta composes correctly and writes correctly, in the real terminal path,
with the real `NonBlockingTerminal` and the real negotiation.

### What the terminal does with it

cmux links CoreText and imports `_CTTypesetterCreateWithAttributedStringAndOptions`,
`_CTTypesetterCreateLine`, `_CTLineGetGlyphRuns`, `_CTRunGetStatus`. A
CTTypesetter applies UAX#9 unconditionally, and `CTRunGetStatus` exists to report
`kCTRunStatusRightToLeft`. So each row is re-ordered after Porta has already
ordered it visually.

The model predicts the reported screen exactly, which is what makes it a
diagnosis rather than another plausible mechanism:

- The paragraph direction resolves LTR — the first strong character in these rows
  is Latin (`English`, `.porta.json`).
- The Hebrew label, the neutral run of spaces, the `│`, and the Hebrew nav entry
  form ONE contiguous level-1 run (N1 gives the neutrals between two R runs the
  R level). L2 reverses that run as a block: the two segments swap and each comes
  out in logical order.
- Everything outside it — `< dark >`, `והפעלה זו` — is at a different level and
  does not move. Reported and observed.
- A row whose nav entry is LATIN has only the label as an R run, so it reverses
  IN PLACE: same position, no swap. Which is why `chroma`/`git` rows were always
  the ones that looked right.

Earlier rounds ruled terminal bidi out twice, both times wrongly: a `strings`
grep for "bidi" (imported symbols are not string literals — use `nm -u`), and a
naive whole-line-reversal model, which predicts the whole row moving and is NOT
what UAX#9 does. Ghostty's own engine genuinely has no bidi; the reordering
enters through cmux's CoreText shaping, not through Ghostty.

### Why Porta cannot simply defeat it

The remedy for a TUI is to wrap emitted text in `U+202D … U+202C` (LRO/PDF) so
every character is strong L and nothing reorders. Porta cannot, as things stand:
`AnsiUtils::visibleWidth()` counts those format characters as ONE COLUMN EACH
(measured: LRO, PDF, LRM, LRI and PDI all report 1). A full-width row plus a pair
would measure `width + 2`, and `ScreenWriter::differentialRender()` throws on a
row wider than the terminal. This is also why `Bidi::SPENT` replaces the marks
Porta inserts with spaces before they ever reach the tty.

Spending an existing space on a mark instead of a space would break the run
merge at the pane boundary and keep Porta's accounting intact — but only on
terminals that give a format character zero cells; on one that gives it a cell,
the row is right, and on one that gives it none, the row is a column short.
Untestable from here, because the failure is in rendering, which no capture
shows. Not built on a guess.

**Open**, and deliberately so: the defect is in the terminal's shaping, Porta's
frame and bytes are both correct, and the workaround is a trade nobody has
measured.

## PORTA_TERMINAL_BIDI: hand the reorder back to a terminal that does it anyway (2026-09-12)

The diagnosis from the previous entry stands: cmux shapes rows through CoreText,
which applies UAX#9, so a row Porta has already ordered visually is ordered a
SECOND time. Porta's frame and bytes are both correct; the screen is not.

### Why the obvious fix is the wrong one

The first instinct was to keep mirroring and break the run merge with a mark at
the pane edge. That trades one bug for another. The terminal reverses each
right-to-left run IN PLACE, so a pre-mirrored run comes back as LOGICAL order:
the panes would stop swapping and the Hebrew would read backwards instead.

What such a terminal needs is the LOGICAL row — it does the mirroring itself,
and does it correctly. What it cannot get right unaided is where to STOP. A row
is `[label][spaces][│][spaces][nav]`, and N1 gives the neutrals between two
right-to-left runs the right-to-left level, so label, separator and nav resolve
to ONE run and L2 reverses the block: the panes swap. A strong left-to-right
character at each pane edge ends the run there, and each pane then mirrors
within its own span.

### The shape

`PORTA_TERMINAL_BIDI=1` sets `BidiTui::setTerminalBidi(true)`, and the frame
path calls `PaneReorder::markBoundaries()` instead of `reorderRow()`: the row is
left logical and each inner pane edge SPENDS one padding space on a `U+200E`.

Spending rather than inserting is the whole trick. `AnsiUtils::visibleWidth()`
counts a directional mark as one column — the same as the space it replaces — so
the row's width is unchanged and the over-wide guard in `ScreenWriter` cannot
fire. That guard is why wrapping rows in `LRO … PDF` was never available: a pair
adds two columns to a row already exactly as wide as its pane. A pane whose last
column is not padding is left alone rather than grown.

Verified in a REAL pty (`script`, `stty cols 226 rows 67`, settings opened via a
keymap binding, `PORTA_FRAME_DUMP` capturing the composition): the frame comes
out logical (`כללי — 5 הגדרות`), the mark lands at column 206 immediately before
the separator at 207, and all 65 written rows still measure exactly 226.

### What is NOT claimed

That it fixes the reported screen. The failure is in RENDERING, which no capture
can show, so this is the first thing in the whole investigation that cannot be
graded from here — only on the reporter's display. It ships as an env hatch and
not as a setting for exactly that reason: if it works it can be promoted, and if
it does not, nothing in the default path moved.

Five mutants survive in `markBoundaries()` and are equivalent rather than
untested: `continue`→`break` on either guard (runs tile the row in order, so the
width guard only ever fires on the last one), `strict: true` on a probe whose
result is stripped, and `$width - $end`→`$width + $end` where `sliceByColumn()`
clamps. The behavioural ones — the last run keeping its padding, an unmarkable
pane not abandoning the walk, the style being closed before the mark, the row
closing its own state — are each pinned by a case.

## The cursor is not homed when the alternate screen opens (2026-09-12)

`CSI ?1049h` switches buffers; it does NOT reset the cursor row. The first frame
is APPENDED from wherever the cursor is — `ScreenWriter::fullRender()` is called
with `clear: false` — while its own bookkeeping starts from row zero. Launch
Porta from a prompt partway down the screen and that many rows go off the top,
and because every later repaint is positioned RELATIVELY against that
bookkeeping, the screen stays that far out of step for the rest of the session.

Measured in a real pty at 226x67, with eight lines printed before launch:

| | scrolls |
| --- | --- |
| without the home | **9** |
| with it | **1** (the teardown's own `CSI 1B` + newline) |

`Ide::run()` now calls `clearScreen()` (`CSI 2J` + `CSI H`) straight after
entering the alternate screen. `IdeFilesystemSessionTest` pins the ordering, and
removing the call fails it.

This is the second defect of the same shape as the kitty splice: the frame
writer never repositions absolutely, so ANY disagreement about where row zero is
lasts the whole session. The first one desynchronised it by a stray byte; this
one starts it desynchronised.

### How the earlier readings misled

Replays of captured sessions kept showing the menu bar scrolled off the top, and
that was read as evidence of corruption. It was not: the teardown emits
`CSI 1B` and a newline after the final frame, so replaying the WHOLE stream
always ends one row down. Stop the replay at the last `CSI ?2026l` before
grading a screen. The startup scroll is real; the one in those replays was the
teardown.

## A floating box is laid out logically and must mirror with the frame (2026-09-12)

Reported: "the main menu bar drop downs are misplaced". That one sentence was
worth more than every capture in this investigation, because it is reproducible
in seconds and it names a second surface with the same cause.

Opening the File menu under a Hebrew locale at 226 columns, the dropdown's
region is `0+38` — the far LEFT — while the mirrored menu bar draws `קובץ` at
column ~218, the far RIGHT. A `DropdownRegion` carries the column the widget
laid it out at, which is LOGICAL, and it was being stamped there into a frame
that has since been mirrored to VISUAL columns. `File` is logically first, so
its dropdown sat at column zero.

`PaneReorder::paintRegion()` now mirrors the box across the row:
`start = width - region.start - region.width`. The dropdown lands at 188..225,
directly under its title, with its own contents mirrored
(`שמור קובץ` against `Ctrl+S`).

That mirror is also what makes the two passes agree. `blankRegions()` blanks the
surface's LOGICAL columns, the pane mirror carries that hole across the pane's
span, and the stamp mirrors across the row: over a full-width pane those are the
same columns, so the box lands exactly in its own hole and no gap is ever
visible. (Over a pane narrower than the row they can differ; that is a known
edge, not a claim.)

Clamping had to come with it: a surface hanging off the end of the row has fewer
cells than it claims, and stamping the claimed width made the row SHORTER than
its pane — the worst failure this renderer has. Caught by the clamping case,
which had been asserting the old unmirrored position and failed honestly when
the behaviour changed. Three tests failed that way, and each was a stale
expectation rather than a regression.

Eight mutants survive in `paintRegion()`: `max(0, …)`/`min(…)` decrements whose
guarded values cannot reach the boundary on any tested path, `strict: true` on
slices whose results are compared stripped, `$width - $end` → `$width + $end`
where `sliceByColumn()` clamps, and the zero-width early return, which is
equivalent because rebuilding a row around an empty middle reproduces it. The
positional claims — the box mirrors, it lands in its own hole, it is stamped
once, it is clamped, a box entirely off-row changes nothing — are each pinned.

## Mirroring the box moved the mouse away from it (2026-09-12)

Reported straight after the dropdown fix: "the drop downs shadows are misplaced
+ click not working". Both fall out of the same change, and the second is the
serious one.

### Click

`PaneReorder::logicalColumn()` is the inverse of the frame mirror, and it knew
only about PANES. Once a floating box moves with the frame, a click inside it
was still mapped through the pane underneath — landing somewhere else entirely,
so no menu item was ever hit. It now checks the surfaces first and in REVERSE
order, because they paint over each other and the last one drawn owns the cell;
inside a box the offset maps back through the box's own reorder, since a
dropdown's items are Hebrew and were mirrored within it.

The lesson is the one this codebase keeps relearning: the thing that escaped was
not the feature but the invariant beside it. Moving a box is half a change — the
other half is that every mapping INTO it has to move as well.

### Shadow

`shadowWidened()` widens the region one column to the RIGHT, where the shadow
falls. Mirrored with the frame, that column lands on the LEFT of the box and
pushes the box a column off the title it hangs from. Under a right-to-left
locale the widening now goes left instead, so the mirror puts both back.

That is only a partial repair and is recorded as such. For a menu at column 0 —
`File`, the common case — there is no column to the left to widen into, so the
rectangle keeps its shadow cell and the mirrored box still ends one column past
its title. Closing that properly means `paintRegion()` mirroring the BOX rather
than the shadow-widened rectangle, which needs the region to carry its shadow
extent; it is a shape change, not a tweak, and it has not been made here.

`MenuOverlayController` gained a `Translator` (defaulted, so its single direct
construction site in the tests is untouched).

## cmux is detected by name, and the reorder handed back to it (2026-09-12)

The evidence that settled the whole investigation came from a screenshot of the
Highlighting section, and it is a detail no earlier one showed: on the SELECTED
row, the blue background sat in the nav column while the glyphs drawn on it were
the form's label. A terminal paints backgrounds from its cell grid and then
shapes the text as a run, so only the text moves. Porta cannot produce that — it
composes background and glyph into the same cells. The reorder is the terminal's.

The rest of that screen is the rule stated plainly:

| rows | the form's label | result |
| --- | --- | --- |
| 0-2 | `שמירת שינויים`, `מדגיש ברירת מחדל` — Hebrew | swapped with the nav entry |
| 3+ | `elixir`, `go`, `ruby`, `rust`, `zig` — Latin | correct |

A Latin label is a left-to-right run, so N1 has nothing to give the separator's
neutrals and L2 has nothing to merge across it. Exactly what UAX#9 prescribes,
and exactly what was on screen.

`TerminalBidi::isApplied()` now answers the question, and `Ide` switches
`BidiTui` into the logical-plus-boundary-marks mode when it says yes.

Detection is BY NAME and narrow on purpose. Ghostty's own engine has no bidi, so
keying off `TERM_PROGRAM=ghostty` would disable Porta's reorder for everyone
using Ghostty directly and leave their Hebrew backwards; cmux is identified by
its own `CMUX_BUNDLE_ID`. `PORTA_TERMINAL_BIDI` overrides in BOTH directions, so
a wrong guess is always recoverable, and an empty value defers to detection
rather than reading as "off" — `getenv()` returns `''` for a variable that is set
but empty, and that must not strand a cmux user.

Verified end to end in a real pty with nothing set: the mode came on by itself,
the frame is logical (`כללי — 5 הגדרות`), and the boundary mark is at column 206
against a separator at 207.

## Each pane is a right-to-left isolate, not a marked boundary (2026-09-12)

Handing the reorder back to cmux made the Hebrew READABLE — reported, and
visible: menu bar, nav and form all in correct order top to bottom. It did not
make the LAYOUT right, and the two reports that followed say precisely why.

Left to itself the terminal resolves each row's direction from the first strong
character it finds, and that decides two different things wrongly:

- With a Latin label (`< .porta.json >`) the row reads left-to-right. Each
  Hebrew run is reversed IN PLACE: the glyphs come out right, but the labels
  stay LEFT-aligned inside their pane. Reported as the form being left-aligned.
- With a Hebrew one the row reads right-to-left and L2 reorders runs across the
  WHOLE row. Reported as "the main app menu bar is broken" — its items came out
  in the wrong order.

A single left-to-right mark at the pane edge cannot fix either: it ends a run,
it does not say which way the pane should be laid out.

An ISOLATE does both. `U+2067 … U+2069` around a pane settles the direction
INSIDE it — labels to the right, menu items from the right — while from the
outside the whole span counts as one neutral object, so the panes never reorder
against each other. An embedding (`RLE`) or a bare mark would have set the
paragraph direction and swapped the panes, which is the bug being avoided.

The two cells are SPENT from the pane's own padding, one at each end, so the row
keeps its exact width and `ScreenWriter`'s over-wide guard cannot fire. A pane
without padding at both ends is skipped rather than grown, and a verbatim
(`LtrPane`) pane is never isolated — forcing a code pane right-to-left would
reverse the source.

Verified in a real pty at 226x67: the menu bar pane wrapped as `RLI@0 PDI@225`,
the settings form as `RLI@0 PDI@206` and the nav as `RLI@208 PDI@225` — the
one-column separator between them correctly skipped, having no padding to spend
— and all 65 written rows still exactly 226 columns.

### Verified by simulating the terminal, not by guessing (2026-09-12)

The isolate change could not be graded by looking at a capture — the failure is
in rendering. So the prediction was computed instead: a small UAX#9 reorder
(isolates, strong types, N1/N2, L2) run over the rows Porta actually emits, from
the real-pty frame dump.

- Menu bar: `קובץ` comes out at the far RIGHT, the items ordered right-to-left.
  That was the report.
- Settings rows: `כללי — 5 הגדרות│  כללי` — the label right-aligned against the
  separator, the nav entry to its right. That is the correct screen.

The first attempt at that simulation got the nav pane wrong, and the bug was in
the simulation: it resolved a neutral at an isolate BOUNDARY to the paragraph
level instead of the isolate's embedding level. N2 says the embedding. Worth
recording because the wrong version looked plausible and would have sent the
next round chasing a pane that was already correct.

It also found something real: the direction marks `SettingsFormWidget` inserts
were reaching the terminal. They exist to steer PORTA's reorder, which does not
run in this mode, and a terminal renders them as nothing while Porta still
counts them as a column each — so every row carrying one came up two columns
short. They are now spent for spaces, exactly as `Bidi::serialize()` does on the
other path. Confirmed against the real pty: zero stray marks, all 65 rows still
226 columns.
