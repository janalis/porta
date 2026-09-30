# Testing

The suites, the rigs you must not re-invent, and the doctrine that keeps the
suite deterministic.

## The suites

`tests/Unit`, `tests/Integration`, `tests/E2e`, plus **`plugins/*/tests`** (the
`Plugins` suite). A plugin's tests live with its own code, use
`Porta\Tests\Support` freely, and are what hold that plugin at 100% — its
coverage pass sees nothing under `tests/`.

E2e suites are **thematic**: one per subject (LSP features, context menus, dock
tools). Keep a new E2e test in its subject's suite — and in the right TREE: an
E2e that drives a plugin's code belongs under `plugins/<name>/tests/`, because
the coverage gate grades `plugins/` from that plugin's own tests and a core E2e
covering plugin code reads as covered in one pass and absent in the other. The
git flows and git context menus moved there for exactly that reason. The `too-many-methods` and
`kan-defect` ratchets gate `src`, and a regrown monolith would re-pin them.
`IdeTest::testFullScenario` stays one continuous session on purpose; `mago.toml`
carries the calibration note.

E2e tests boot a real `Ide` against a `VirtualTerminal` and a `TempProject`
through `tests/Support/RunsIde`. Steps are `key => fn($tui, $terminal, $ide)` and
run **sequentially chained** — each schedules the next *after* it completes, so
a step may `await()` a subprocess or async effect without a later step firing
underneath it. **The keys order the steps; they are not waits.** The gap between
steps is capped at `PORTA_E2E_STEP_GAP` ms, default **0**, and every step starts
behind one forced frame (a user's input always lands after a paint). The loop
stops right after the last step, behind a final forced frame — stopping is
itself a chained step, so it cannot fire underneath a step that is awaiting.
There is no duration to pass: effects that land after the final step are awaited
IN it, because a clock is not a barrier. `runSteps()` is the same scheduler
driven against an `Ide` the test booted itself, which is what
`IdeTest`'s one continuous session uses.

A step that needs something to have HAPPENED awaits it (`awaitPainted`,
`awaitGit`, `awaitSearch`, `awaitLspReady`). A step that needs TIME to pass says
so with `pump()`: `ClickCounter`'s 0.4s double-click window, `ZoomGesture`'s
0.1s burst window and `LspSession`'s 0.3s document-sync debounce are the known
ones; the last two are pumped in a test today. `PORTA_E2E_STEP_GAP=100000` replays the keys as real gaps — a test
that passes only that way is leaning on a gap.

The gaps were ~57% of E2e time (the keys summed to 194s over 456 scenarios) and
were removed on 2026-09-15. Dropping them exposed 53 tests with a hidden
fixed-gap dependency, all deterministic: 21 were a bare `tick()` before a rect or
screen read (inside the 60fps cap it paints nothing), cured in the harness by the
per-step frame; the rest were awaits that were missing (search results, git
lanes, LSP answers, a debug session), a count of repaints read as a count of
warnings, and the two clock waits above. Measured, alternated, `composer
test` at 8 processes: **109 / 116s with the gaps, 86 / 89s without**; the E2e
suite alone 34s → 13–17s. Graded after: 3 serial passes of E2e and Plugins at
load 8–85, and 4 serial passes of each of the 16 changed files under 16 burners
(load 48–167) — 0 red.

When a constructor gains a dependency, fix the direct construction sites here
too.

## Never hand-roll a rig `tests/Support` already provides

Roughly 700 duplicated lines once collapsed onto these.

| Rig | What it gives you |
| --- | --- |
| `PreservesPath`, `ClearsPortaLsp` | the env rig, as attribute hooks |
| `PumpsLoop` | `await()` / `pump()` |
| `ScreenReads` | screen scrape, the force-`frame()` idiom |
| `RunsIde::awaitPainted` | act, then wait for it to be ON SCREEN |
| `RunsIde::awaitGit` | the git in-flight barrier, reached through `ViewRegistry::find('git')` — git is a plugin, so its handle is not in the container |
| `FindModalReads` | find-modal scraping |
| `RendersWidget` | `plain()` / `rows()`, ANSI-stripped |
| `StatusReads` | `flashText()` reads the model, `statusRow()` the rendered row |
| `Toasts` | the same model-vs-render choice for toasts (messages as plain text: `Markup::plain()`) |
| `GitBinary` | `skipWithoutGit()` / `whichGit()` |
| `TempProject` | `git()`, `initGit()`, `commitAll()` — hold it in a variable: an inline `new TempProject(...)->dir` is freed (dir deleted) before the enclosing call runs, and `composer arch` fails the line |
| `TestOverlayPane`, `RendersRoot` | the presenter rigs |
| `TestTui::create(cols, rows)` | the three-arg Tui rig |
| `lsp-stub.php` | `sendMessage` / `readFrames` for stub servers |
| `DrainsPeer` | `drainPeer()`: read a socket pair's far end from a loop watcher — polling it inside `await()` moves one 8 KB macOS socket buffer per turn |
| `CallsTools` | `callTool($api, $id, $args)`: run a `registerTool()` handler in its own fiber, as the mcp server does, pumping the loop until it answers; a throw is rethrown |

`await()`'s budget is real seconds, measured with `hrtime()`. It used to add
0.02 per turn and call that elapsed time, so "timed out after 10.0s" could be a
30s wait — and a barrier that burns its FULL budget is the signature of a LOST
effect rather than a race, which only reads if the number is the number
enforced. Measuring tightens every deadline, so a coverage run gets an explicit
×3 (the `pcov.enabled` sniff bootstrap.php uses); graded green over three
serial passes of E2e and Plugins under 16 CPU burners, load to 207. `pump()` is
measured for a sharper reason: it stands in for a real clock, so `pump(0.4)` has
to be 0.4s. The 0.02s turn itself stays — a backoff was measured and bought
nothing ([decision history](decisions/history-01.md), "The verify loop, measured", has the four alternated runs).

`awaitPainted` exists because 91 E2e sites did action → `tick()` → assert the
message is on screen. A bare tick is not a frame, so whether the status bar had
painted was a timing accident — invisible under `composer test`, fatal under the
slower coverage run. Forcing one frame is measurably not enough: a loop callback
can sit between the action and the paint, so the barrier forces a frame per
turn. The assertion stays at the call site, so a real regression still fails as
an assertion rather than a bare timeout.

**`await()` throws on timeout, and its condition must re-evaluate.** An arrow fn
(`static fn () => null !== $x`) captures `$x` by value at creation and can never
observe an async callback's by-reference write. Sixty-four such conditions were
once silently burning their full timeout as sleeps. Write
`static function () use (&$x): bool { … }`, or read `$this->…` state. When there
is genuinely nothing to await, say so with `pump()`.

**A paint barrier that gives up prints the screen.** `awaitPainted()` and
`awaitPaintedRaw()` append the grid as it stood, one `| ` row per line, so
the failure says whether a modal was still open or a different flash painted.
Read it before re-running the test to look.

**Golden files regenerate through one variable each**, and the mismatch
message names it. Regenerate only when the change is the one you meant, then
read the golden's diff as part of the review:

| Golden | Regenerate with |
| --- | --- |
| `EditorRenderGoldenTest` | `UPDATE_EDITOR_GOLDEN=1` |
| `EditorKeymapTest` | `UPDATE_EDITOR_KEYMAP_GOLDEN=1` |
| `SettingsRenderGoldenTest` | `UPDATE_SETTINGS_GOLDEN=1` |
| `SettingsPagesGoldenTest` (E2e) | `UPDATE_SETTINGS_PAGES_GOLDEN=1` |
| `LspClientTest` (capabilities) | `UPDATE_LSP_CAPABILITIES_GOLDEN=1` |

e.g. `UPDATE_EDITOR_GOLDEN=1 vendor/bin/phpunit tests/Unit/Widget/Render/EditorRenderGoldenTest.php`.

**Expected styled bytes are written as markup**, `Styles::dark()->format('<added>+</>')`.
It produces the same bytes as the hand-written `->of(Role::Added)->apply('+')`
for a flat span, and it reads as what the row shows. There is no rig for it
because none is needed. Raw `\x1b[…m` literals stay where the code under test
is byte-level (decorator open/close pairs, open-only diff runs). The anchor
that pins markup to real bytes is `tests/Unit/Ui/Markup/StylesFormatTest.php`.

## The flake doctrine

`await()` budgets are real seconds (above), generous for anything that resolves
in-process: a turn takes milliseconds even under load, so a condition that burns
its whole budget almost always never became true — a lost effect, not a slow
one. Most flaky shapes are on the fixed-clock side, and each has a cure.

**The exception is an await on an EXTERNAL PROCESS**, and it is not rare. The
subprocess competes for the same CPU the loop is spending, so a 10s budget can
run out before it is scheduled to completion. These
fail from load and nothing else: the installer, analyzer-plugin and
opener-shim tests all did so repeatedly at load 10–40, and every one passed in
isolation. Diagnose in this order before touching the code — `uptime` first;
re-run the failing class alone; and note that `coverage:check` passing while
`composer test` fails is the tell, since it runs the same tests. A *different*
handful failing on each run is load; the *same* test failing every run is not.

**`composer mutate` automates the mutation sweep for the code you just
changed.** It builds Unit-suite coverage (in parallel, `pcov.directory=src`:
242s → 68s and 426s → 56s in two serial/parallel pairs on a loaded box, the same
23,924 executed lines, identical Infection verdicts), then mutates the working tree's
uncommitted `src/` lines (diff vs HEAD — run `vendor/bin/infection` directly
with another `--git-diff-base` for a committed range) and reports the
survivors inline. Non-gating by design: read survivors like a review. Three
shapes are inevitably harmless — a literal nothing observes (a mkdir mode), a
cast that exists for the analyzer, an equivalent flags value — and the decision history
already documents the redundancy-not-gap verdict for two hand-found ones.
Judged against the **Unit suite only**, deliberately: the full suite is what
the manual-sweep doctrine warns about (kills that cannot be about the mutated
code), and the loop/subprocess budgets would burn minutes per mutant. The
corollary: a line only E2e observes reports as uncovered here, and plugin
`src/` is out of scope — the MANUAL sweep doctrine still owns both.

**A survivor is a candidate, not a verdict.** Hand-verify before writing a
test: apply the mutation with an edit, run the class's suite, restore. Two
confirmed sources of FALSE escapes, plus the inherent ones: colored phpunit
output blinded Infection's verdict parser (`--colors=never` rides in the
composer script now — a proven-killable mutant "escaped" until it did), and
verdicts flip run-to-run under wide parallelism (the same mutant died 58/58
in a solo `--filter` run and "escaped" inside a whole-directory sweep) — so a
FOCUSED single-file run is the authoritative grade. Inherently unkillable:
a subprocess-driven test never kills an in-process mutant (the child loads
pristine source from disk), and the three harmless shapes above recur
constantly. The MSI is therefore a floor, and never a gate.

**Method order is randomized** (`executionOrder="random"` in phpunit.xml.dist —
formalizing what paratest's method distribution never guaranteed anyway). A
failure that only reproduces in-suite may therefore be order coupling, not
load: PHPUnit prints `Random Seed: N` at the top of the run, and
`vendor/bin/phpunit tests/E2e/TheClass.php --random-order-seed=N` replays that
exact order. `composer test:serial` pins `--order-by=default` on purpose — it
is the only way to read the `[loop-leak]`/`[env-leak]` reports, and those need
a reproducible order. Never resolve a binary off the live `PATH` in a helper
(`GitBinary::whichGit()` walks `PORTA_TEST_PRISTINE_PATH` for exactly this
reason): under a random order, "runs before any PATH mutation" is not a fact
anyone can promise.

Before accepting that verdict, though, check the wait itself: a hand-rolled loop
with its own smaller budget, or a poll that starts after a flash which fires when
the subprocess is *spawned*, is a real defect wearing load's clothes. Both were
found and fixed here. Give every subprocess wait a `what:` label — a bare
`timed out after 10.0s waiting for condition` tells the next reader nothing.

**No fixed-gap asserts.** Never `handleInput` → next `ms =>` step → assert.
Await the effect in the *same* step: `awaitGit($ide)` after every mutating git
key, then await the porcelain, log or on-disk predicate. Never let a blocking
`$project->git()` read race the async op.

**A bare `tick()` is not a frame** — the ticker is adaptive. Use `frame($tui)`
before scraping, and remember modals lay out from the *previous* frame's rects,
so a modal needs two frames to paint.

**Toasts prove nothing.** Lifetimes are 2.5–6s, so `Toasts::last()` can return
the previous action's toast. Assert the on-disk effect or the model.

**Gate picker and overlay navigation** on `EditorState::pickerOpen()` or
`promptOpen()`. Their lists are built inside git/LSP callbacks, so an early
arrow falls through to the editor. When accumulated output would satisfy a
substring early, read the current grid with `screenGrid()`.

**Awaits must be terminal-state, not success-only.** Await
`done || failure-message`, then assert success — so a real failure fails fast
with its own message instead of a bare timeout.

**`pump()` only for genuine negatives**, and even then be careful: an async
answer that has not arrived yet is indistinguishable from one that was never
requested. Prefer setting a sentinel and asserting nothing replaced it.

**Grade a barrier under LOAD, never on an idle run.** An idle machine cannot
tell a real barrier from one that merely happens to be satisfied. The instrument
is a serial `--filter` loop under CPU burners — harsher than `flake:scan`, which
shares the machine between paratest workers and so gives each test *more*
headroom, not less. `flake:scan` prints **the first reason each failing test
gave** beneath its name; take that reason to the reproduction rather than
starting from the name, because a rate on its own says where to look and nothing
about what happened:

```bash
cleanup() { pkill -x yes >/dev/null 2>&1; }; trap cleanup EXIT INT TERM
for i in $(seq 1 16); do yes > /dev/null & done
r=0; for i in $(seq 1 30); do vendor/bin/phpunit --filter theTest >/dev/null 2>&1 || r=$((r+1)); done
cleanup; echo "$r/30"     # verify with: pgrep -x yes
```

Kill the burners in the same command (`trap EXIT`) or they outlive the shell and
poison every later measurement. This found what idle runs had missed: two LSP
scenarios failing 6 in 30 that `composer test` had been passing, one of them
introduced by a compression whose commit message said "verified to gate" — idle.

As of 2026-08-31 every E2e and plugin-E2e file has been graded this way (35
files, 4–10 serial passes each, 16 burners): **0 red runs**. The Unit and
Integration suites were graded the same way (6 passes each), and the six async
unit classes most exposed to timing — `AsyncProcessTest`, `TerminalSessionsTest`,
`LspSessionTest`, `LspSessionPoolTest`, `DapSessionTest`, `AsyncHighlightTest` —
at 15 passes each. All 0 red. Re-measure before and after any change that claims
to reduce flakiness, at the same load.

**Order coupling is a separate axis, and it is measured separately.** Load runs
say nothing about it, and paratest hides it: 16 workers see ~21 E2e tests each,
so leaked state never accumulates far. A SERIAL suite run puts all 338 in one
process, which is the harsh case:

```bash
vendor/bin/phpunit --testsuite E2e --order-by=random --random-order-seed=101
```

(`--random-order-seed` is ignored without `--order-by=random`; PHPUnit says so
rather than failing.) Sampled 2026-09-01 at three seeds for E2e and two for
Plugins: 0 red. A failure that reproduces only in-suite is order coupling, not
load — replay it with the seed PHPUnit printed.

**The shape to hunt is DROPPED input, not a missing barrier.** An audit of first
steps found 164 scenarios driving input behind nothing but a boot gap, and
sampling the subprocess-heavy ones under load found nothing wrong with any of
them. The LSP ones were different in kind: a request fired at a session that has
not initialised is dropped in silence, so no response and no flash ever arrive
and the next barrier burns its whole budget. `awaitLspReady()` in
`IdeLspFeaturesTest` is the fix for that class. A gap in front of synchronous UI
is not the same hazard, and barriering it wholesale buys nothing measurable.

**A reproduction plus a plausible story is not a diagnosis.** A 12-of-12 failure
here was written up as index-lock contention between a polling
`git status --porcelain` and an in-flight `git add`. It fit, and it was wrong —
both git paths already pass `--no-optional-locks` (below), which this page had
said all along. Re-tested, both barriers were 0/30 and the failure never
reproduced. Verify a mechanism, or write down that the cause is unknown; a wrong
one in the record gets cited and stops the next person looking.

**`IdeLspFeaturesTest` under load: one cause found, two still open.** The file
failed roughly **1 run in 6-10** with the box at load ~200. It is worth reading
how the one that was solved got solved, because two plausible stories were
wrong first.

*Not a slow machine.* Raising the barrier from 10s to 30s changed nothing: same
test, same rate, now burning the full 30s (a 34s run). A mock-LSP round trip
does not take thirty seconds, so the budget was never the problem and the raise
was reverted — it only made each failure three times slower to report.

*Not new either.* 8 runs a side under 24 burners: 1/8 on that round's HEAD at
load 202, 1/8 on the commit it branched from at load 232.

*And not a dropped request.* That was the third guess, and the instrumented run
refuted it. Dumping state at the failure gave:

    FAIL lspAtType=LSP:starting…  lspAtFail=LSP:incremental-lsp-server
         diag='sync:full:<?php fooZ'

The answer arrived, promptly, and said `sync:full` where the test awaited
`sync:incremental`. Whether a ranged delta may be sent at all is the server's
`textDocumentSync` capability, which arrives with the initialize RESULT — so an
edit typed before then is synced as full text, correctly. The test asserted the
incremental path without holding its precondition. `awaitLspReady()` (now taking
the server's name, since this fixture is not `mock-lsp-server`) is the barrier,
and the await is for ANY `sync:` diagnostic followed by an assertSame, so "the
server said full" fails in milliseconds naming both strings instead of timing
out against the wrong problem. **1/10 → 0/14 under 24 burners at load 163.**

The per-language routing scenario is fixed too, and by deleting a claim rather
than a clock. Its two `pump(0.4)`s were annotated "the barrier that would
replace this has to be per-SESSION, and no test-visible signal for it exists
today". One does: **`LspSessionPool::isInitialized()`** resolves the session
from the ACTIVE file, so it follows a mid-run language switch — which is exactly
why the status bar cannot serve here, both languages in that config pointing at
the same binary. It is also the precondition production checks, since `ready()`
returns null before initialize and the feature request is then never sent.
`awaitLspSession($ide)` in `RunsIde` is the barrier; 0.8s of clock gone, 0/14
under 24 burners at load 155.

That barrier resolves its session from the active file, so the test now ASSERTS
app.js is current before using it: if `OpenFocus::Keep` ever stopped making the
opened buffer current, the barrier would answer for the php session, pass
instantly and quietly stop being a barrier.

The third, `testQuickFixUsesDiagnosticRangeAndHandlesNoOpAction`, was a bare
`tick()` again — but in a shape the rect sweep does not catch, and worth
recognising on its own:

```php
$this->await(fn () => pickerOpen($editor), what: '…'); // the STATE is up
$tui->tick();                                          // meant to paint it
$tui->handleInput("\x1b");                             // …and now it is gone
// after the run: assertStringContainsString('Quick Fixes', $terminal->getOutput())
```

The assertion reads a title off the ACCUMULATED output, so it needs that title
to have been painted during the window between those two lines. Inside the 60fps
cap the tick paints nothing, Esc closes the picker, and the terminator's forced
frame renders the closed state — the title never reaching the buffer at all.
`awaitPainted()` is the barrier. **A bare tick before a `getWidgetRect` is the
well-known version of this; a bare tick used to paint a TRANSIENT state that the
next line destroys is the same bug and looks nothing like it.** A mechanical
sweep cannot find these — 69 bare ticks are followed by an input and nearly all
are benign — so it takes reading what the assertion needs to have been on screen.

The file is 0/14 under 24 burners at load 155-160 with all three fixed.

**A transient state that a SUBPROCESS ends cannot be awaited into existence.**
symfony-lsp's status cell shows `downloading 41%` from the chunk that names it
until the fake curl exits, and a loop busy enough can read that chunk and the
exit in back-to-back turns with no frame between: the percentage is never
painted, and `awaitPainted()` burns its whole budget — the lost-effect
signature, twice in coverage runs, never in 37 local runs under burners and
pcov. No barrier fixes that, because the state it waits for may never exist.
The stub holds the download open until the test has seen the meter
(`FAKE_DOWNLOAD_GATE`, released with a `touch()`), so the state lasts exactly
as long as the test needs it to.

**Both git argv paths pass `--no-optional-locks`** (`GitProcessRunner` and
`TempProject::git()`). Porcelain readers must never take the opportunistic index
lock, which collides with a concurrent add or commit's mandatory one. Keep the
flag on any new git invocation site.

## Leak reports

The event loop is process-global per paratest worker.
`tests/Support/LoopIsolationExtension` isolates after every test and reports
leaked identifiers as `[loop-leak]`. It isolates by one of two routes: a fresh
driver where possible, which also resets the suspension WeakMap; otherwise —
because `setDriver()` refuses while the driver still considers itself running,
which a Tui that parked a suspension leaves it doing — by cancelling each leaked
identifier by hand. That fallback took a full serial run from 2448 leak lines to
53.

`tests/Support/EnvIsolationExtension` does the same for the environment, in both
channels (`putenv`/`getenv` and `$_ENV` — Porta reads the second), reporting
`[env-leak]`. Environment leaks are nastier because the symptom lands in another
file: a test that leaves `PATH=/nonexistent` behind makes the next test's
`#!/usr/bin/env php` stub fail, and a leaked `PORTA_HOME` silently uncolours a
later suite. Both read as machine load and pass in isolation.

The extension is a net, not a licence. Keep using `PreservesPath` and
`ClearsPortaLsp` at the test that needs them — the net only acts after the test
that leaked has already finished. Never leave a watcher armed on a stream a
local `proc_open` handle will close; wrap the span in try/finally, which is why
`PumpsLoop` cancels its own delay timers in a finally.

**The STDERR reports are invisible under `composer test`**: paratest swallows
worker STDERR, so an unconditional write from an extension never reaches the
log. Both therefore go through `tests/Support/LeakLog`, which also appends to
`.phpunit.cache/leaks.log` (override with `PORTA_LEAK_LOG`) — that file survives
the parallel run, and `bin/porta-dev flake:scan` reports each run's count as the
delta over it. `composer test:serial` remains the way to read them inline, in a
reproducible order; it needs no timeout raising, because `composer.json` sets
`process-timeout: 0`.

**What remains is expected pending state.** Each line is a timer that was
correct at the moment its test stopped the loop — `Support/DebouncedRequest`'s
0.15s coalescing timer (a viewer pane that has just asked for a re-render),
`Highlight/CodeHighlighter`'s 2s answer deadline (the backend died without
delivering, and that timer is precisely how it gets dropped to plain). To
diagnose one, reflect the driver's `callbacks` map for the leaked identifier's
Revolt callback class and its closure's file:line.

**Re-derived 2026-09-21**, because three numbers were on record and no two
agreed (the fallback commit's "2448 to 53", an "about seven" that this page
carried for months, and 87 from a green run on 2026-08-30). A full serial run, green, reports **483 lines — 481
`[loop-leak]`, 2 `[env-leak]`**. Do not read that against the older figures: it
is a different suite and, more to the point, the number MOVED with the step-gap
removal, and understanding why is the whole value of the count.

Graded E2e-only, serially, both sides — 403 identical tests, both green:

| armed at stop | main | after the gaps came off |
| --- | --- | --- |
| `delay 0.150s HighlightController.php:79` | 39 | **346** |
| `delay 0.150s CodeEditorWidget.php:611` | 10 | **54** |
| `repeat 0.050s ProcessReaper.php:34` | 40 | 43 |
| everything else | 7 | 8 |
| **total** | **76** | **358** |

Both movers are 0.15s debounces armed by the edit or open the test just made.
A scenario used to sit through 300-500ms of scheduled gap before it ended, which
was long enough for them to fire; now it awaits its effect and stops, and they
are still pending. `LoopIsolationExtension` cancels them, so nothing reaches the
next test — **the count went up because the suite got tighter, not leakier**.
The shape that would matter is a NEW file:line, or a rise in
`ProcessReaper`/`StreamReadableCallback` (a subprocess still being reaped, which
is a test walking out on work rather than a debounce it never asked to wait
for). Those two did not move.

## The paratest wedge

Distinct from the fail-fast flakes above: paratest reaches ~99% and then **hangs
indefinitely**, with no failure and no output. Diagnosed on 2026-08-30 at
`--processes=16`, and the shape is precise enough to recognise again.

The signature, in the order it is cheapest to check:

- `ps -o etime=,pcpu= <paratest-pid>` — **minutes elapsed at 0.0% CPU**, and
  `pgrep -P <pid>` reports **zero children**. Every worker is gone; the runner
  is not.
- `sample <paratest-pid>` parks in **`zif_usleep`**. It is the poll loop, not a
  blocked read — paratest is spinning a sleep waiting for a completion that can
  no longer arrive, and there is no timeout on that wait.
- `lsof -p <paratest-pid>` names the worker it is still holding open. Compare
  `$TMPDIR/worker_NN_*_junit` sizes across the run: the healthy ones were
  146–216 KB each, and **the held worker's was 0 bytes**, with an mtime ~57 s
  *older* than the rest — it stopped first and the others finished without it.
- That worker's `_status` file was **all zeros** and its `_progress` all dots:
  it ran its ~300 tests, passed every one, and then vanished without writing its
  result.

**This is NOT the poisoned-worker signature.** That one shows a streak of `1`s in
`_status` before the stall — a test failed, the loop corrupted, and the cascade
followed. Here the worker was clean to the last test. A clean worker that exits
without writing its JUnit fragment leaves the runner waiting forever, so the two
have different causes and only the second is about test code at all.

Not reproducible synthetically: a prior attempt found pure CPU starvation
insufficient (the full suite passed at load 137 from 24 `yes` burners). This
occurrence was at 16 workers on 16 cores with `--log-junit` in play; whether
either is necessary is untested.

Diagnose before killing — the evidence above is gone the moment the process is.

## Mutation, not coverage

**An invariant assertion is not a guard until a mutation kills it.** Coverage
says a line ran, not that anything would notice its absence. See
the [decision history](decisions/README.md) (its mutation entries) for the measured cases, including the five untested
seams a full mutation pass over the event listeners found — and the two
survivors that were redundancy rather than gaps.

When a test's whole value is an invariant, break the production line it guards
and confirm it goes red. If it does not, either strengthen it or say in the
commit message which mutation it survives. Mutation-check the *fix* too: a
replacement test can be satisfied by a different code path than the one you
meant.

**Scope the sweep, and get a green baseline first.** Judged against the full
suite on a loaded machine, five of six `ContextMenuRouter` mutations looked
killed — by Chroma, LSP and installer tests that cannot be about a context menu.
Filter to the suites that could plausibly fail, confirm they are green
unmutated, and only then call a mutation dead.

Three shapes account for most survivors found so far:

- **Two branches emitting the same words.** `stageCurrent()` flashes "Git not
  available" both for a null path and for git being unavailable; the suite
  tested the first, so deleting the second changed no output. Set up a state
  only the guard under test can refuse.
- **An assertion reading whole-screen text that something else painted.**
  `"Debug: prog.php"` is written twice — by the dock strip's label closure and
  by the session tab inside the panel — so reducing the label to a bare "Debug"
  kept the E2e green. Read the model (call the closure, inspect the entry), or
  assert a distinguishing suffix, as `"Unsaved changes in b.php"` does.
- **The half of a seam nobody observes.** The emitting side usually has a test
  and the listening side does not. `PluginApi::emit()`'s activate-time gate and
  `GitRefresher::onWorkingTreeChanged` both deleted clean.

**Put a positive control in a negative test.** "The setting off publishes
nothing" passes just as well when the fixture is broken and there was nothing to
publish. Assert in the same test that the *same* fixture does produce the effect
with the flag on.

## Green gates do not prove it runs

**Every gate can pass on a feature that never executes in the app.** Two rounds
of UI work shipped four such bugs, each caught only by asking "does this actually
run?" — never by the suite:

- `Lsp\HighlightController` had no `#[AsEager]`. Nothing injects it and its
  constructor installs the per-frame caret watch, so it was never built and
  semantic identifier highlighting had never run once. Every test constructed the
  controller directly. See `AsEagerMembershipTest`, which now gates
  the general rule.
- The file tree looked its marks up in the wrong path space (buffers are keyed by
  `realpath()`, nodes are built from the raw root), so on a symlinked project root
  — `/var` on macOS included — no mark ever appeared. Nothing failed; the
  decoration was simply absent.
- `textDocument/codeAction` was sent with a zero-width caret range, so
  `refactor.extract` could never be offered. The unit tests asserted the response
  handling and never the params.
- `WorkspaceEditor` dropped a server's `CreateFile`, half-applying a refactoring
  and still reporting success. The mock server only ever answered with an inline
  `edit`, so the path had no coverage to be missing.

Three habits catch this class, and all three are cheap:

**Ask what constructs it.** A service nothing injects is never built. If the
answer is "nothing", it needs `#[AsEager]` or an `#[AsEventListener]`.

**Assert the REQUEST, not just the response.** A parameter that is silently
dropped looks exactly like success from the response side. When the bug would be
"we asked the wrong question", the assertion belongs on the params.

**Drive the real thing once.** Both rounds ended by booting the IDE on a
`VirtualTerminal` and reading the screen, and by probing the actual language
server for its capabilities before writing against them. `Probe::get($bidiTui,
'onBeforeFrame')` names its hooks' owners; a throwaway E2e that dumps
`$terminal->getOutput()` shows what a user would see. Delete the driver
afterwards — a test that needs a real `phpantom` installed does not belong in a
deterministic suite — but run it before believing the gates.

### A fixture that grants what the code needs cannot test that it asked

Three defects in one round, one shape. The mock LSP server **answered**
`callHierarchy/incomingCalls` while advertising no `callHierarchyProvider`, so
a feature shipped ungated and flashed *"No callers found"* at every real user —
neither bundled PHP server provides call hierarchy. The same mock **ignored**
`context.only`, so a client's kind filter was proven by nothing. And a
`WorkspaceEditor` test built its URI with `Uri::fromPath()`, which realpaths as
it encodes — so the canonicalisation under test never ran and its mutation
survived.

A fixture is written to make the feature REACHABLE, so it grants whatever the
feature needs — and capability negotiation, request filtering and awkward input
are exactly what a generous fixture erases.

**`MockServerContractTest` now pins it both ways**: every gated method the mock
answers must be advertised, and every gated capability it advertises must have
a handler. Adding a branch without advertising it fails there. It caught 14
unadvertised handlers the day it was written — all latent, because Porta only
gated on four capabilities at the time.

**For your own fixtures:** hand-build the awkward input rather than letting a
helper normalise it on the way in, and mutation-check ACROSS the fixture — if
deleting the production line leaves the test green, the fixture is probably
doing that line's work.

## No test-only code in `src`

Private-state reflection lives only in the blessed `tests/Support` seams. Zero
`ReflectionProperty` or `ReflectionMethod` uses remain outside them.

- `Services::resolve($ide, X::class)` — a running IDE's services, locator-first
  with a container fallback for the synthetics.
- `Probe` — `get`/`set` private properties with a parent-class walk, `invoke`
  for private methods, `getStatic`/`invokeStatic` for consts and statics.
- Its typed wrappers: `EditorState`, `StatusReads`, `Toasts`,
  `RunsIde::awaitGit`.

Metadata reflection (`getAttributes`, `newInstanceWithoutConstructor` in the
container-contract tests) reads public structure rather than private state and
stays ad-hoc.

Before adding a getter to `src`, ask who calls it at runtime. An interface
implementation the vendor calls (`FillsPaneTrait::isVerticallyExpanded`) or a
timer or signal entry point (`Ide::pollFilesystem`,
`FreezeWatchdog::heartbeat`) is production code. A method only assertions read
is not — probe it instead.
