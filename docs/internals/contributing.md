# Working on Porta

The commands, the gates every commit must pass, and the house conventions.

## Commands

```bash
composer dev            # run the IDE (bin/porta)
composer test           # full suite, paratest, ~90s at the default half of the cores (see throttling below)
                        # per test: --default-time-limit=10, so a wedge fails BY NAME
composer test:unit      # one suite through the same paratest line — also test:e2e, test:plugins, test:integration
composer test:serial    # plain phpunit — ordering, races, leak reports
composer analyze        # mago static analysis
composer coverage:check # the suite with coverage, 100% enforced
composer lint           # mago lint
composer rector         # rector (apply) — follow with composer format
composer fix            # rector, then format — the two fixers in the order that leaves both clean
composer rector:check   # rector dry run — fails on any change it would still make
composer arch           # the architecture ratchet
composer duplication    # phpcpd — zero copy-paste clones, tree-wide
composer custos         # custos — zero inspection findings, info included (custos.json)
composer verify         # all nine: the seven static gates stopping at the first failure, then both coverage passes at once
composer verify:quick   # the seven fast ones, stopping at the first failure
composer check          # the same seven run to the end, then one line naming every red gate — the mid-work check
composer flake:scan     # run the suite N times and name what failed at least once
composer lsp            # the language servers on Porta itself: phpantom over src/tests/plugins, then lsp:probe (below)
composer mutate         # Infection over the uncommitted src/ diff, judged by Unit (testing.md)
composer mutate:clean   # delete every coverage/infection* report (~300MB a run)
php bin/porta-dev i18n:add "Text" --target=fr:… …   # one message into all seven catalogs, next free id
composer format         # mago format (apply)
composer format:check   # mago format --check
composer container      # kernel-less debug:container
```

### `composer lsp`: what the language servers say about Porta

Not a gate of `verify`: it needs `phpantom_lsp` on the PATH and the symfony-lsp
binary the plugin installs, which CI does not have. Two halves, both run to the
end by `bin/keep-going`:

- `lsp:analyze` — `phpantom_lsp analyze` over `src`, `tests` and `plugins`
  (a pathless run reads only the root autoload, which has no `plugins`). This
  is the deterministic per-file answer for PHP; `.phpantom.toml` holds the
  suppressed false positives, each with the upstream issue it waits on.
  The whole tree takes about ten seconds. phpantom 0.10.0's analysis grows
  exponentially with a run of early-return guards whose bodies call through a
  property (`if (…) { $this->x->y(); return; }`, five in a row is enough): the
  input routers never finished until each guard's body became one call to a
  named method. Its `main` has no such limit; keep the shape until a release
  ships it.
- `lsp:probe` — `bin/porta-dev lsp:probe`: every server the IDE would start
  for the project, started the way it would, shown a sample of the files each
  serves. It reports what only LSP mode shows: the client's log (stderr, error
  replies, timeouts, deaths), error/warning log and show messages, and the
  last diagnostics per file. It found symfony-lsp rejecting this client's
  `shutdown`. `--per-language=0 --listen=60` shows it everything.

### Throttling on a shared machine

`composer test`, `composer coverage:src` and `composer mutate` **default to
half the cores**, not all of them — `coverage:check`'s plugins half is the one
exception, at three quarters (see the shard note below). A development box is
usually also running CI runners, containers or another agent, and one worker
per core oversubscribes it badly — the E2e suites then fail for load rather than for cause, which is the
flake class `testing.md` describes.

| var | default | used by |
| --- | --- | --- |
| `PORTA_TEST_PROCESSES` | half the cores | `composer test` (and its per-suite variants), `composer coverage:src`, `composer mutate`'s coverage step |
| `PORTA_SHARD_PROCESSES` | **three quarters** of the cores | `composer coverage:plugins` — so `coverage:check`'s second half |
| `PORTA_MUTATE_THREADS` | half the cores | `composer mutate`'s Infection step |

Throttling a shared box means setting the first TWO: `coverage:check` runs both
passes AT ONCE, and `PORTA_TEST_PROCESSES=4` alone still fans out three-quarter-core
plugin shards behind it.

CI builds pushes to `main` and `develop`, plus pull requests from any branch.
Manual runs on other branches skip every job; the weekly dependency audit
runs on the default branch.

**CI runs on GitHub-hosted `ubuntu-latest` runners** (`.github/workflows/ci.yml`),
free for a public repository. A runner is the job's alone, so the tests take
every core (`PORTA_TEST_PROCESSES: auto`) and the plugin shards four
(`PORTA_SHARD_PROCESSES: 4`, `coverage:plugins` takes a number).

Each job gets its own runner; setup-php installs PHP 8.5 and the declared
extensions, including the explicit no-intl test variant.

```bash
PORTA_TEST_PROCESSES=4 nice -n 10 composer test   # busier than usual
PORTA_TEST_PROCESSES=auto composer test           # the box is yours
```

Measured on the 16-core machine, one run at a time, alternated. The rows
predate the 2026-09-15 step-gap removal, which re-measured only the default
row: `composer test` at 8 went 109 / 116s → 86 / 89s (see testing.md).

| processes | full suite | `composer coverage` |
| --- | --- | --- |
| 4 | 170s | |
| 8 (the default: half of 16) | ~90s | 137s |
| 12 | 62s | |
| `auto` (16, dedicated machine) | 52s | 76s |
| 24 | 39.5s | **64–72s** |
| 32 | **34s** | E2e errors — see below |
| 48 | wedged at 99% | |

So the throttle costs **five times the wall clock**, and it is worth reaching
for only when the box really is shared — check `uptime` rather than assuming.
`nice` costs nothing when the box is idle and yields the CPU when it is not, so
it is worth adding even at full parallelism.

### More processes than cores, because the suite waits

`auto` is not the ceiling, and the reason is in the CPU numbers: at 16 processes
the full suite burns 182s of CPU over 51.7s of wall clock — **3.5 of 16 cores**.
Under coverage at 8 it is 3.3. The suite is **wait-bound, not CPU-bound**: the
Revolt loop runs in real time, ~70 `sleep`/`usleep` sites sit in ~10 Unit
classes, and E2e paid ~0.4s of scheduled step gaps per test (removed
2026-09-15 — see testing.md). Four fifths of the box is
idle while a gate runs, which is why oversubscribing it works — at 32 processes
utilisation reaches 6.7 cores and the suite is 34s.

Where it stops, both measured here:

- **48 processes wedged** at `6588 / 6610 (99%)` with no worker processes left
  alive and three dangling pipes on the parent — the wedge `testing.md`
  describes, reached by asking for more workers than the box will start
  cleanly.
- **32 under `composer coverage` failed two E2e tests** (a flash asserted as
  `Opened https://…` never arrived) while completing fine without coverage. pcov
  makes every worker slower, so the same worker count buys more contention; the
  coverage pass has a lower ceiling than the plain suite. 24 is green under both.

`--testsuite Unit` gains almost nothing past 16 (23s at 16, 20.0s at 24, 21.5s
at 32): the oversubscription win lives in E2e and Integration, which are the
suites that wait.

**Killing a wedged run means killing the tree.** paratest's workers do not die
with their parent, and orphans wedge the NEXT run at exactly the same point —
observed twice in a row, and cured by `pkill -9 -f vendor/bin/paratest` before
re-running. Check `ps` for stragglers before believing a second wedge.

`--functional` splits by test METHOD rather than by file. It is what makes the
suite's ordering assumptions safe to hold (see `phpunit.xml.dist` on
`executionOrder="random"`), and at 12 processes it costs 10s against per-class
chunking — a trade the suite makes on purpose, not an oversight. Note where the
last two rows land: four more cores buy back exactly what `--functional` costs,
so on an idle box the safe split is free.

`composer container` takes flags after `--`: `--loaded`, `--tag=<name>`,
`--aliases`.

Five of those — `arch`, `container`, `coverage:check`, `coverage:plugins` and
`flake:scan` — are Symfony commands in `src/Console/`, run through the
maintainer console `bin/porta-dev`. It is a
separate binary because `bin/porta` is a single-command application, which is
what lets `porta src/Foo.php:12` read its argument as a path. `porta-dev` is
deliberately absent from composer's `bin`: it grades Porta's own tree, so it
has no meaning in a user's project. **Always invoke the gates through the
composer scripts.**

Run one file or one test through the SAME script, because composer forwards
whatever follows `--` to paratest — which keeps the process default, the
configuration and the random order that `vendor/bin/phpunit` by hand does not:

```bash
composer test -- tests/Unit/Editor/BufferTest.php   # one path (paratest takes ONE)
composer test:unit -- --filter theTestName          # one test, in its own suite
```

**"Is my new class covered?" does not cost the whole gate.** `composer coverage`
takes the same trailing arguments, so a scoped run is seconds instead of
minutes — and `coverage:check` already grades a scoped report through `--only=`:

```bash
composer coverage -- --coverage-filter=src/Editor tests/Unit/Editor   # 9s, not 137s
php bin/porta-dev coverage:check coverage/clover.xml 100 --only=/src/Editor/
```

It is not a substitute for the gate: the 100% rule is about every test in the
tree covering every line, and a filtered run can only tell you about the lines
you filtered to. It also overwrites `coverage/clover.xml`, so re-run the real
pass before reading that file as the whole picture.

**A test may not take ten seconds.** `composer test` passes
`--enforce-time-limit --default-time-limit=10`, and `failOnRisky` turns a breach
into a failed run that NAMES the test and its line. Two reasons, and the second
is the bigger one. Slow tests were found by reading a serial run by hand — the
three fixed in the 2026-09-15 round burned 6.3s, 7.5s and 3.0s each, and nothing
would have reported them. And a worker that wedges took the whole suite with it,
where paratest reaches ~99% and hangs: a per-test limit turns that into one red
test. The margin is deliberate. Measured after the 2026-09-21 round, the slowest
case in the tree is 2.73s, five exceed 2s and none exceeds 3s, so ten is a 3.7x
headroom — wide enough to survive a loaded box, narrow enough that the next
six-second test is a failure rather than a discovery.

It is on the SCRIPT, not in `phpunit.xml.dist`, on purpose: pcov is 2-3x
slower, so the same limit in the config would fire on honest tests during
`composer coverage:check` — the actual gate — and a guard that flakes gets
turned off.

**Capture gate output rather than paging it**, so the exit code survives:

```bash
composer analyze > /tmp/x.log 2>&1; echo "exit=$?"
```

## Definition of done

`composer verify` runs all nine, cheapest first, and the seven static gates stop
at the first failure — `coverage:check` last, because it runs the whole suite and so subsumes
`composer test`. Running `composer test` *and* `coverage:check` ran that suite
twice; over one session that repetition was 31% of 233 minutes of test
execution. CI (`.github/workflows/ci.yml`) still calls the gates individually
across its five jobs, deliberately: `testing.md`'s flake doctrine reads the
divergence between the `tests` and `coverage` jobs as its machine-load tell.

**One CI leg cannot be run here, and it guards more than `src/` suggests.** The
matrix grades a `polyfill (no intl)` variant, and a Homebrew `php-zts` has intl
compiled STATICALLY — `php -n -r 'var_dump(extension_loaded("intl"));'` is still
true, so no ini trick reproduces it locally; it needs a PHP built without intl,
i.e. a container.

Grep `src/` for the surface and it looks trivial: no first-party code in
`src/`, `plugins/` or `tests/` calls a `grapheme_*` function (the last call,
in `HelpViewerWidget::byteAtColumn()`, now walks `AnsiUtils::walkCells()`).
That reading is wrong.
**`symfony/tui` calls those functions 19 times across 9 files**, including
`Render/CellBuffer`, `Widget/Editor/EditorDocument`, `EditorRenderer`,
`Widget/Util/Line` and `WordNavigator` — the render and editing core every
scenario drives. So the leg grades whether the whole TUI still splits graphemes
correctly on `symfony/polyfill-intl-grapheme`, and it is worth keeping even
though nothing in this repository names the functions.

**`composer test`** — green. Two tests drive real git and opener subprocesses
and were hardened against timing: the git plugin's
`GitViewControllerTest::testLineStagingAndUnstagingThroughTheDiffPane`
and the async URL-open E2e in `IdeDocsTest`.

The git one was **not a timing problem at all**, and four rounds of treating it
as one is why it took until 2026-08-30 to find out. Reproduced at 2 failures in
70 runs under 20 CPU burners, it never failed *slowly*: it spent the full 20s
budget waiting for an unstage that had never been requested. Dumping the pane at
the moment of failure showed it empty — `No changes` — with `shownDocument`
null, so the space that was supposed to unstage a hunk hit
`stageSelection`'s null guard and returned in silence.

The cause was a product race, not a test one. `git status` calls overlap in
ordinary use (a staging completion refreshes while the quiet poll's status is
still out) and their answers came back in **arrival** order, which under load is
not issue order. Applying the older answer republished the pre-staging entry
list; the reload it drove then picked its side from that stale entry, asked for
the *worktree* diff of a now-fully-staged file, and got nothing.
`GitViewController` carries a generation counter for status and for diff loads
now, and a superseded answer is dropped. Rate after: **0 in 140** under the same
load.

Two things worth keeping from it. The escalation the history predicted — *"a
fourth flake means an isBusy-style barrier"* (`264f5b75`) — would not have
worked: a lane check says "something is running", and ordering two answers that
are both already back needs "is this still the newest one", which is a different
fact. And the settle loop that was supposed to make this safe waited for the
diff to *hold still for 8 turns*, which an empty pane does perfectly. **Stability
is not correctness**; a barrier that accepts any value that stops changing
accepts the broken one, and a loaded machine is exactly what produces it.

`bin/porta-dev flake:scan` gets you a rate for the suite; for one test, a loop of
`--filter` runs under `yes` burners is faster and is what produced the numbers
above. Grade every flake change against a before and an after at the same load.

**`composer coverage:check`** — **100% line coverage**, over `src/` *and* over
every plugin's own `src/`. A new branch with no covering test fails the gate. It
runs two passes: the parallel one over `src`, then
`composer coverage:plugins` over `plugins/`, one serial phpunit per shard. Plugin code must therefore be
covered by that plugin's OWN tests — see [testing.md](testing.md) for why the
plugin half cannot ride the parallel run.

**`composer analyze`** — mago reports zero issues, warnings or help messages.
Suppressible in place with `// @mago-expect analysis:<rule> -- reason`, on the
same terms as the linter below, and used where the cause is outside this
codebase: `PerfCollector`'s load-average cast is unprovable because mago's
`sys_getloadavg()` stub returns an untyped array, and every explicit narrowing
adds a branch the coverage gate then refuses.

**Both mago scripts pass `--minimum-fail-level=note`, and that is load-bearing.**
mago's own default is `error`, so `mago lint` exits **0** while reporting
warnings — measured across a session, 11 of 11 runs with findings exited 0,
while `mago analyze` exited non-zero every time. So CI's Lint step was green on
warnings, and the gate held only because a human read the output; that is also
how the lint tail once reached 93 findings and analyze 3 warnings before anyone
noticed. With the flag the exit code IS the gate again — but keep capturing the
output anyway, because the code says only that something failed.

**`composer lint`** — **zero findings** (the 2026-08 burn-down retired the
standing tail, so the count IS the contract now — no comparing against a
previous run). A new finding is fixed, or, where the rule is structurally wrong
at that site (a Symfony Console bool `#[Option]`, a listener adapter a
first-class callable would `TypeError`, a plugin whose subject matter is the
TODO tag), suppressed in place with `// @mago-expect lint:<rule> -- reason` —
the reason is mandatory prose the next reader can re-judge. `[linter.rules]`
disables nothing — every rule runs, calibrated to this codebase's maxima.
`excludes` drops paths: the six mock LSP/DAP servers, which are procedural
scripts by design. The threshold rationale, and which class pins each number,
is in `mago.toml` itself.

The linter also holds the tree to **Symfony's coding standard**, beyond what
the formatter can: yoda comparisons, fully qualified native functions and
constants (`\sprintf()`, `\PHP_EOL` — every one, since mago cannot restrict the
rule to php-cs-fixer's `@compiler_optimized` list), and `$this->assert*()` in
tests. Static closures and static methods keep `self::assert*()`; the rule
knows the difference.

**`composer rector:check`** — Rector's dry run reports **no pending change**.
`rector.php` names the sets (PHP up to 8.5, code quality, dead code, type
declarations, PHPUnit code quality) and, next to each skipped rule, why it is
skipped. It runs after mago because it is slower. It DOES import class
names — deliberately, and only in a file it is already changing (`rector.php`
says why): native functions stay `\sprintf()`, which is what mago's
ambiguous-function-call rule demands, and docblocks are left alone. After `composer rector`,
run `composer format` and read the reprinted lines — Rector re-prints what it
touches, and the formatter keeps whatever breaks it chose.

**`mago lint --fix` is not safe to run unattended here.** Its "safe" fixes
include `no-empty-loop`, which deleted the socket drain in `BidiTuiTest` —
a `while ('' !== fread(...)) {}` whose body is empty because the work is the
CONDITION. `stop()`'s teardown escapes flush blocking, so a full buffer with no
reader parks the worker: the suite hung at 99% with zero reported failures.
Every file the fixer had touched passed in isolation, so only a full-suite run
catches that class of damage. Review its diff, then run the whole suite.

**`composer arch`** — the architecture ratchet
(`src/Console/ArchitectureCheckCommand`): the exact namespace import graph,
class-level acyclicity with a pinned set of accepted cycles, the E2e
suite-size bound, and no test line chaining off an inline
`new TempProject(...)` temporary (PHP frees it — deleting the fixture dir —
before the enclosing call runs, so the assertion silently tests the
missing-dir case instead; hold the project in a variable), and no test
outside `tests/Support` reflecting private state (`Probe` is the one reader).
**It fails on
drift in either direction** — a new edge must be added to the embedded
allowlist in the same commit, and a vanished edge must be removed from it. A
stale allowlist is not a gate.

**`composer duplication`** — **zero clones**, from `systemsdk/phpcpd` (the
maintained fork of the abandoned `sebastian/phpcpd`; it needs no package the
lock did not already carry). It reads `src`, `plugins`, `tests`, `bin` and
`config` at the tool's defaults — five identical lines and seventy identical
tokens — with one exclusion, `vendor`, because a plugin's vendored
dependencies and the committed composer-map fixtures are not ours to
deduplicate.

**There is no allowlist, deliberately.** The arch gate has one because a
namespace edge can be correct and still needs recording; a clone cannot be
correct and unrecorded — either it is worth sharing, or the copy is the honest
shape and the code should say why, in which case rewording one side ends the
match. Every clone the tool found on its first run was worth fixing, and three
of them were rigs `tests/Support` already provided, so the rule "never
hand-roll a rig" now has something that looks.

What it does NOT find is worth knowing before reaching for it: it compares
token streams, so the theme tables, the per-grammar `match` tables and the
four analyzer plugin files — the structures people expect it to flag — are
invisible to it, because their literals differ. It finds copied CODE.

**`composer custos`** — **zero findings, info included** (`--fail-on=info`),
from `janalis/custos` (a Php Inspections EA port; the Composer package is a
wrapper that downloads its prebuilt binary on first run). `custos.json` sets the
paths — `src`, `plugins`, `tests`, `bin`, `config` — and PHP 8.5. A false
positive is suppressed where it stands, with `// @noinspection <Rule>Inspection
-- why` on the line above the statement it reports (since 0.1.1, also above a
single call argument or array item), or in a class docblock for a whole trait. A rule that contradicts
mago or rector is switched off in `custos.json` instead, with the reason in
`decisions.md`, since JSON takes no comments. Its precedence check asks for
`a || (b && c)`, which is why mago's formatter keeps redundant logical
parentheses (`mago.toml`).

**`composer format:check`** — clean.

The gates are ordinary `src/` code: analysed by mago and 100%-covered like
everything else. That is why each takes its inputs as seams — the arch
command's scan roots come from an argument and its pins are
constructor-injected, so it can be driven over fixture trees. It scans `src/`
and each plugin's declared class directory, which means it scans itself: keep
it importing nothing from `Porta\`, or it grows the very edges it grades. That
is also why it re-reads each plugin's `composer.json` by hand instead of using
`PluginManifest`.

## What the verify loop actually costs

Measured on the 16-core box at four processes, one command at a time, so the
ratios are trustworthy even where the absolutes are not:

| | seconds |
| --- | --- |
| the six static gates together (custos, the seventh, adds <1) | **4.1** |
| `composer test` | 170 |
| `composer coverage` (the same suite, under pcov) | 241 |
| `composer coverage:plugins` (serial — retired, see the shard note) | 146 |
| `composer coverage:plugins` (sharded, what it costs now) | 20 |
| `composer coverage:check` end to end | **~260** |

Re-measured 2026-10-02 at the defaults (8 processes, 12 shards) on a box at load
14–39, alternated: `coverage:src` 135–139s, `coverage:plugins` 53–54s, so ~190s
run one after the other. The two passes now run **at once**
(`bin/keep-going coverage:parts --concurrent`): they share no state — CI already
runs them as two jobs — and both wait far more than they compute, so the pair
costs ~145s. Each pass's output is printed whole, in chain order, once both have
finished, and the last line names every red pass. pcov also watches only what
each pass grades now (`src`, or `plugins`), worth ~10s and ~8s, and
`coverage:src` keeps the container cache on (below), worth ~45s.

Three things follow, and they are why `composer verify` is shaped the way it is.

**The static gates are free.** All seven cost 5s — 1% of `coverage:check`.
Running them first costs nothing and stops a six-minute run that a missing
newline would have failed anyway. Parallelising them would buy nothing and lose
deterministic output.

**pcov and the forced container recompile cost 71s** — the same 6,609 tests are
170s without coverage and 241s with. Part is instrumentation; part is
`tests/bootstrap.php` turning the container cache off under pcov, so each of the
suite's ~458 Ide boots recompiles instead of loading the shared dump.
**`coverage:src` no longer pays the recompile** (2026-10-02): it sets
`PORTA_CONTAINER_CACHE=on`, has `tests/Support/CoverageSweepExtension` load every
`src` class once per worker inside coverage, and grades with `--require=src`, so
a merge that loses a worker's data fails loudly instead of shrinking the denominator —
139.7s → 92.6 / 85.3s, alternated, same `28767/28767`.

**The plugins pass no longer pays that**, because it never needed it. Both
reasons the flag exists — every worker compiling every `src` file so no file is
missed, and that compile collapsing each worker's PathReducer base so the merge
resolves — are about the parallel `src` run. `composer coverage:plugins` runs serial
processes filtered to `plugins/` (one per shard), so it sets `PORTA_CONTAINER_CACHE=on`
itself and the bootstrap's pcov sniff now only applies to a run that did not
say. Measured, alternated: **151.0 / 151.4s off, 131.2 / 138.3s on**, with
`TOTAL (/plugins/) 100.00% (4284/4284)` and 127 plugin files in every run. The
wall-clock win is 13% and the CPU win is 40% (64s → 38s of user time) — the
gap is `ConfigCache::isFresh()`, which in a git checkout re-hashes ~480 tracked
classes per warm boot. The CPU half is what matters if this pass is ever run
several ways at once.

**Sharding the `src` pass the same way is closed, on evidence.** It measured 45s
against paratest's 99s at the same lanes — and `coverage:check` refuses the
result: 8 files cannot be merged from Clover, because a method declaration line
that is itself executable is written as a `type="method"` entry whose count is a
MAX over the method, with its `stmt` entry dropped. [The decision history](decisions/history-02.md) ("Parallelism: the box was 80% idle") has the
numbers and the two escapes that were tried.

**opcache is not a test-loop lever.** It is worth setting for `bin/porta`
(see below), and the loop does not feel it: `composer test` at eight processes
ran 92.0s and 89.4s without it, 90.4s / 87.8s / 89.2s with — inside the noise.
The arithmetic says why before the stopwatch does. A paratest worker compiles
`src/` once and then runs thousands of tests, so eight workers save eight times
18ms, not eight times a minute. Do not spend a round on it, and keep it off for
`composer coverage`.

**The plugins pass was 146s for 36s of work; it is 20s now.** That gap was the
price of the php-code-coverage merge bug the pass exists to dodge — and the
dodge is per PROCESS, so the pass is a fan-out of independent serial phpunit
runs (`bin/porta-dev coverage:plugins`), one per plugin test FILE, several at a
time, each writing its own report. `PORTA_SHARD_PROCESSES` sets how many at
once, defaulting to **three quarters of the cores** rather than half — a shard
is one wait-bound process, and the file-level split only pays when there are
lanes to hide 68 phpunit boots in (`plugin-system.md` has the table). The run
names its slowest shard, because that is the floor. `coverage:check` grades the directory as
one report, merged per line. See `plugin-system.md` for why that is legal where
parallel paratest is not.

## Working with several agents

The parallelism that pays here is **read-only fan-out**: sweeps, audits, "find
every other site of this", checking a doc against the code. Three of those run
in about the wall clock of one. `.claude/agents/porta-explorer.md` is that
shape, committed — read-only tools, told what this project counts as evidence,
and told to report `file:line`.

Two things never fan out, both measured rather than assumed:

- **One suite runner at a time on a checkout.** Two paratest runs share
  `.phpunit.cache` (65MB of container dumps, result cache, coverage cache) and
  thrash; the two coverage passes overwrite each other's reports; and the box's
  E2e barriers fail for load rather than for cause. The [decision history](decisions/history-02.md) ("The clipboard history was a memory regression") records the
  measurement — a paratest run and a mutation run at once, load average 38,
  three E2e failures that all passed when run alone.
- **No edits while a suite runs.** paratest loads test files in workers as it
  goes, so rewriting one under a running suite is editing the thing being read.
  This applies to a subagent's edits exactly as it applies to yours; it is the
  reason an agent that needs a suite asks for it rather than starting one.

The sharded plugin pass is what parallelism inside a gate looks like when it is
safe: each shard is a whole process with its own report, and the state they
would otherwise fight over is given to them one each.

## Working style for an agent's round

- **Keep going** through any step that doesn't need the owner; put status notes in
  the same message as the next action. Stop and ask only when you can't continue
  without them, or before anything destructive: deleting data, force-pushing,
  rewriting pushed history, or touching anything outside this repository.
- **The finish line** is `composer verify` green with every finding carrying its
  evidence. Stop early only for a gate failing for a reason you can't explain.
- **A round of more than a handful of items** keeps its checklist in `TASKS.md`
  (gitignored): tick each item when done, add what you find on the way.
- **Fan read-only sweeps out to `porta-explorer`** (above), one area per agent, and
  check each report's `file:line` evidence before accepting it — a claim argued in
  prose is not a verified one.
- **Use the committed skills** in `.claude/skills/`: `gates` (the inner loop and a hung
  run), `mutation-check`, `add-command`, `add-setting`, `new-plugin`, `review-branch`. Each is a recipe
  that links here; when one disagrees with this page, fix the skill.
- **End every run with three headings: Blocked on me, Changed, Found.** Mark anything
  you couldn't confirm, and say where you looked.

This section once sat in CLAUDE.md and took it to 116 lines; `ClaudeMdBudgetTest`
caught it. It is routed from there instead.

## The inner loop

`composer verify` is the definition of done, not the thing to run after every
edit. While work is in progress, two commands are the loop:

```bash
PORTA_TEST_PROCESSES=24 composer test:unit   # 22s
composer fix; composer check                 # ~8s: fix what fixers can, then every quick gate at once
```

The Unit suite is **6,225 of the 7,406 tests** and runs in **22–23s at 24
processes, 49s at the default 8** (2026-09-15, on a box at load 8–13) — about
five sixths of the cases for a quarter of the full suite's wall clock. What it does not run is the part that boots an IDE:
Integration, E2e and the plugin suites. So it is the fast answer to "did I
break the thing I just touched", and it is not an answer about wiring, focus,
rendering or a plugin — those live in the suites it skips, and `composer
verify` is what says done.

**There is deliberately no "test what I touched" mapper.** Only **49% of
`src/` classes** (401 of 821, recounted 2026-09-30) have a `tests/Unit/<same path>Test.php`, so a
path-derived selection would silently run nothing for half the tree and report
green. Run a whole suite instead; it is 23 seconds.

## Startup performance notes

Most of a Porta boot is PHP **source compilation**, not work: with
`opcache.enable_cli=0` (the default) every launch recompiles the autoload
maps, the ~212KB container dump and several hundred class files. Enabling
opcache for the CLI cuts that — by less than this page used to say. Compiling
all 707 files of `src/` in a fresh process, five runs each, medians: **55ms
without it, 37ms with**. That is 1.5× and 18ms, not the 4.7× (122ms → 26ms)
recorded here before; the old figure predates PHP 8.5 and does not reproduce.
Where it is visible is a single-shot command — `bin/porta-dev container` goes
0.29s → 0.24s, five runs each, no overlap between the two sets.

```ini
opcache.enable_cli = 1
opcache.file_cache = /tmp/php-opcache   ; persists compiled code across runs
```

**Spotlight indexes the build output.** `mdfind -onlyin . "kMDItemFSName ==
'clover.xml'"` returns `coverage/clover.xml` — a 1.8MB file every coverage run
rewrites, next to a 65MB `.phpunit.cache/`, all of it re-indexed afterwards.
`mds` and `mdworker_shared` were the top CPU consumers on this box between gate
runs. Dropping an empty `.metadata_never_index` into `coverage/` and
`.phpunit.cache/` stops it; both directories are gitignored, so the marker is
per-checkout and costs nothing. This is an observation with a cheap remedy, not
a measured speed-up — what it definitely buys is quieter measurements, since
that indexing lands on whatever runs next.

**Never under pcov.** opcache serving a cached op_array bypasses pcov's
compile-time instrumentation, and the damage is to the DENOMINATOR, which is
the direction nothing notices: one file's unit test run reports 1,813
executable statements with opcache off and 1,792 with it on — same lines
covered, `FileTreeWidget` counted as 185 statements instead of 189 — so the
percentage goes UP. `composer coverage` must run in a shell where
`opcache.enable_cli` is off.

This is an environment setting — `bin/porta` cannot set it for itself. Two
measurement caveats when quoting startup numbers: a **dev checkout** eagerly
requires ~97 dev-only `autoload_files` (~30ms cold — phpunit assertions, the
safe shims) that a `--no-dev` install never pays; and the git-checkout
container-freshness gate (`ConfigCache` source tracking) adds ~10ms that
shipped installs skip. The per-boot phase numbers land in `.porta/perf.log`
as the one-shot `boot` line (see subsystems.md), so measure from there, not
from guesses.

## Releasing

Releases are cut from GitHub Actions in one click. Write user-visible changes
under `## [Unreleased]` in `CHANGELOG.md` as you go
([Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format, semantic
versioning).

Run **Actions → Release → Run workflow** on `main` with a version: `X.Y.Z`,
or `patch` / `minor` / `major` to bump the latest tag. Tick *dry run* to
build everything without publishing (the phar is kept as an artifact). The
workflow:

1. refuses to start unless CI passed on the commit (it does not run the
   gates again);
2. runs `bin/porta-dev release:prepare`, which turns `[Unreleased]` into
   `[X.Y.Z] - <date>`, sets `Ide::VERSION` and extracts the release notes;
   then commits `chore(release): vX.Y.Z`, tags it and pushes both;
3. builds `porta.phar` from the tag, checks that `porta --version` names it,
   and publishes a GitHub release with the phar and `porta.phar.sha256` —
   the two files `porta --self-update` fetches;
4. writes `Formula/porta.rb` in `janalis/homebrew-tap`;
5. pushes each plugin's split, with the tag, to `janalis/porta-<name>`
   (`.github/workflows/split.yml`, which also runs on every push to `main`);
6. pings Packagist for `janalis/porta` and every plugin package, and
   redeploys the documentation site.

A failed publish can be retried with *Re-run failed jobs*; the tag is kept.
`bin/build-phar` stamps `git describe --tags --always --dirty`, so a local
build between tags says which commit it is (`v1.2.0-3-gabc1234`), and
self-update treats it as the tag it follows.

### Documentation site

The site is built with [VitePress](https://vitepress.dev) from the same
Markdown the F1 browser renders: `docs/` (less `docs/internals/`, which is
for this repository only) and each plugin's `docs/`, mapped onto the site by
`.vitepress/config.mts`. It is deployed to GitHub Pages by
`.github/workflows/docs.yml` on every push to `main` that touches them, after
each release, and on demand; pull requests build it without deploying, which
catches dead links.

```bash
make docs-dev       # live preview on http://localhost:5173/porta/
make docs           # production build in .vitepress/dist
```

### Plugin splits

Every bundled plugin is also a read-only repository, `janalis/porta-<name>`,
published on Packagist under that name — the installer drops the `porta-`,
so it lands as `<name>`. What a split needs is in the plugin's own directory:
its `README.md`, `LICENSE`, `.gitattributes` and the workflow that closes a
pull request opened there (`BundledPluginSplitTest` holds every plugin to
it). A new bundled plugin needs the same files, and its repository created
before the next push to `main`.

### One-time setup

- Secrets: `SPLIT_TOKEN` (a fine-grained token with *Contents: read and
  write* on the `janalis/porta-*` repositories), `HOMEBREW_TAP_TOKEN` (the
  same on `janalis/homebrew-tap`), and optionally `PACKAGIST_USERNAME` and
  `PACKAGIST_TOKEN`.
- Create each `janalis/porta-<name>` repository (empty), and submit it and
  `janalis/porta` on packagist.org.
- **Settings → Pages → Build and deployment → Source: GitHub Actions.**
- If `main` is protected, let GitHub Actions bypass the protection so the
  release commit can be pushed.

## Conventions

**Final classes and constructor injection everywhere.** Collaborators are
threaded as constructor arguments; no class reaches for a global. Services and
singleton widgets are autoregistered by the container — see
[container.md](container.md). Only the `$this`-closure-wired objects and the
composite widget-tree assembly stay hand-constructed in `Ide`.

When you add a dependency to a container-built class, autowiring handles it: a
new autowirable class needs no config unless `Ide` resolves it by id. When you
add one to a hand-built class, inject it and **update every construction site,
including the tests**.

**No `$this` inside injected `static fn` closures** — mago rejects it.

**The formatter never re-wraps lines.** `mago format` runs Symfony-style at
print-width 200 with preserve-breaking. Keep your own line breaks; don't fight
it.

**mago analyzes `src` only** (`tests` and `vendor` are excluded from
reporting), so type annotations matter most there. `vendor` is still read for
type information, which is how you learn that **`symfony/tui`'s `Renderer` and
`ScreenWriter` are `final`** and cannot be subclassed. When a vendored seam does
not exist, `Porta\Ui\BidiTui` is the pattern: subclass `Tui` and override
`processRender`.
