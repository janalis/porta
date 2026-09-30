# Decision history, part 2

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

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
