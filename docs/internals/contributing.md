# Working on Porta

The commands, the gates every commit must pass, and the house conventions.

## Commands

```bash
composer dev            # run the IDE (bin/porta)
composer test           # full suite, paratest, ~40s (every core — see throttling below)
composer test:serial    # plain phpunit — ordering, races, leak reports
composer analyze        # mago static analysis
composer coverage:check # the suite with coverage, 100% enforced
composer lint           # mago lint
composer arch           # the architecture ratchet
composer duplication    # phpcpd — zero copy-paste clones, tree-wide
composer format         # mago format (apply)
composer format:check   # mago format --check
composer container      # kernel-less debug:container
```

### Throttling on a shared machine

`composer test`, `composer coverage:check` and `composer mutate` **default to
half the cores**, not all of them. A development box is usually also running CI
runners, containers or another agent, and one worker per core oversubscribes it
badly — the E2e suites then fail for load rather than for cause, which is the
flake class `testing.md` describes.

| var | default | used by |
| --- | --- | --- |
| `PORTA_TEST_PROCESSES` | half the cores | `composer test`, `composer coverage:check` |
| `PORTA_MUTATE_THREADS` | half the cores | `composer mutate` |

**CI sets `PORTA_TEST_PROCESSES: auto` explicitly** (`.github/workflows/ci.yml`),
because a runner is ours alone. Take every core on a machine nobody else is
using, and say so where it is true rather than making it the default everywhere.

```bash
PORTA_TEST_PROCESSES=4 nice -n 10 composer test   # busier than usual
PORTA_TEST_PROCESSES=auto composer test           # the box is yours
```

Measured on a 16-core machine: ~20s at `auto`, ~2m45s at 4. `nice` costs nothing
when the box is idle and yields the CPU when it is not, so it is worth adding
even at full parallelism.

`composer container` takes flags after `--`: `--loaded`, `--tag=<name>`,
`--aliases`.

Three of those — `arch`, `coverage:check`, `container` — are Symfony commands
in `src/Console/`, run through the maintainer console `bin/porta-dev`. It is a
separate binary because `bin/porta` is a single-command application, which is
what lets `porta src/Foo.php:12` read its argument as a path. `porta-dev` is
deliberately absent from composer's `bin`: it grades Porta's own tree, so it
has no meaning in a user's project. **Always invoke the gates through the
composer scripts.**

Run a single test with `vendor/bin/phpunit --filter testName` or by path.

**Capture gate output rather than paging it**, so the exit code survives:

```bash
composer analyze > /tmp/x.log 2>&1; echo "exit=$?"
```

## Definition of done

Every commit passes all seven. CI (`.github/workflows/ci.yml`) runs them.

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
runs two passes: the parallel one over `src`, then the serial
`composer coverage:plugins` over `plugins/`. Plugin code must therefore be
covered by that plugin's OWN tests — see [testing.md](testing.md) for why the
plugin half cannot ride the parallel run.

**`composer analyze`** — mago reports zero issues, warnings or help messages.
Suppressible in place with `// @mago-expect analysis:<rule> -- reason`, on the
same terms as the linter below, and used where the cause is outside this
codebase: `PerfCollector`'s load-average cast is unprovable because mago's
`sys_getloadavg()` stub returns an untyped array, and every explicit narrowing
adds a branch the coverage gate then refuses.

**Both mago gates exit 0 while reporting issues.** The exit code is not the
gate — read the tail of the output. That is not theoretical: the lint tail
reached 93 findings and analyze 3 warnings before anyone noticed, because
everything in this repo checks `$?`.

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
missing-dir case instead; hold the project in a variable). **It fails on
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

**`composer format:check`** — clean.

The gates are ordinary `src/` code: analysed by mago and 100%-covered like
everything else. That is why each takes its inputs as seams — the arch
command's scan roots come from an argument and its pins are
constructor-injected, so it can be driven over fixture trees. It scans `src/`
and each plugin's declared class directory, which means it scans itself: keep
it importing nothing from `Porta\`, or it grows the very edges it grades. That
is also why it re-reads each plugin's `composer.json` by hand instead of using
`PluginManifest`.

## Startup performance notes

Most of a Porta boot is PHP **source compilation**, not work: with
`opcache.enable_cli=0` (the default) every launch recompiles the autoload
maps, the ~212KB container dump and several hundred class files. Enabling
opcache for the CLI cuts that ~4.7× (measured 122ms → 26ms compiling all of
`src/`):

```ini
opcache.enable_cli = 1
opcache.file_cache = /tmp/php-opcache   ; persists compiled code across runs
```

This is an environment setting — `bin/porta` cannot set it for itself. Two
measurement caveats when quoting startup numbers: a **dev checkout** eagerly
requires ~97 dev-only `autoload_files` (~30ms cold — phpunit assertions, the
safe shims) that a `--no-dev` install never pays; and the git-checkout
container-freshness gate (`ConfigCache` source tracking) adds ~10ms that
shipped installs skip. The per-boot phase numbers land in `.porta/perf.log`
as the one-shot `boot` line (see subsystems.md), so measure from there, not
from guesses.

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
