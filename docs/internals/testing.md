# Testing

The suites, the rigs you must not re-invent, and the doctrine that keeps the
suite deterministic.

## The suites

`tests/Unit`, `tests/Integration`, `tests/E2e`, plus **`plugins/*/tests`** (the
`Plugins` suite). A plugin's tests live with its own code, use
`Porta\Tests\Support` freely, and are what hold that plugin at 100% — its
coverage pass sees nothing under `tests/`.

E2e suites are **thematic**: one per subject (LSP features, context menus, git
flows). Keep a new E2e test in its subject's suite. The `too-many-methods` and
`kan-defect` ratchets gate `src`, and a regrown monolith would re-pin them.
`IdeTest::testFullScenario` stays one continuous session on purpose; `mago.toml`
carries the calibration note.

E2e tests boot a real `Ide` against a `VirtualTerminal` and a `TempProject`
through `tests/Support/RunsIde`. Steps are `ms => fn($tui, $terminal, $ide)` and
run **sequentially chained** — each schedules the next by key delta *after* it
completes, so a step may `await()` a subprocess or async effect without a later
step firing underneath it. Without an explicit `$duration` the loop stops ~80ms
after the last step; pass one only when effects land after the final step, and
prefer awaiting them inside the step.

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
| `RunsIde::awaitGit` | the git in-flight barrier |
| `FindModalReads` | find-modal scraping |
| `RendersWidget` | `plain()` / `rows()`, ANSI-stripped |
| `StatusReads` | `flashText()` reads the model, `statusRow()` the rendered row |
| `Toasts` | the same model-vs-render choice for toasts |
| `GitBinary` | `skipWithoutGit()` / `whichGit()` |
| `TempProject` | `git()`, `initGit()`, `commitAll()` |
| `TestOverlayPane`, `RendersRoot` | the presenter rigs |
| `TestTui::create(cols, rows)` | the three-arg Tui rig |
| `lsp-stub.php` | `sendMessage` / `readFrames` for stub servers |

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

## The flake doctrine

`await()` budgets are denominated in **loop turns**, not wall time, so an await
almost never fails from slowness — a timeout means the condition never became
true. The flaky shapes are all on the fixed-clock side, and each has a cure.

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

**Both reports are invisible under `composer test`**: paratest swallows worker
STDERR, so an unconditional write from an extension never reaches the log.
`composer test:serial` is the only way to read them — raise
`COMPOSER_PROCESS_TIMEOUT`, because the serial suite outruns the 300s default.
Any claim that the suite is leak-clean has to come from a serial run.

**What remains is expected pending state.** A full serial run reports about
seven lines, and each is a timer that is correct at the moment the test stops
the loop: `Support/DebouncedRequest`'s 0.15s coalescing timer (a viewer pane
that has just asked for a re-render) and `Highlight/CodeHighlighter`'s 2s answer
deadline (the backend died without delivering, and that timer is precisely how
it gets dropped to plain). To diagnose one, reflect the driver's `callbacks` map
for the leaked identifier's Revolt callback class and its closure's file:line.

## Mutation, not coverage

**An invariant assertion is not a guard until a mutation kills it.** Coverage
says a line ran, not that anything would notice its absence. See
[decisions.md](decisions.md) for the measured cases, including the five untested
seams a full mutation pass over the event listeners found — and the two
survivors that were redundancy rather than gaps.

When a test's whole value is an invariant, break the production line it guards
and confirm it goes red. If it does not, either strengthen it or say in the
commit message which mutation it survives. Mutation-check the *fix* too: a
replacement test can be satisfied by a different code path than the one you
meant.

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
