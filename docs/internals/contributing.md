# Working on Porta

The commands, the gates every commit must pass, and the house conventions.

## Commands

```bash
composer dev            # run the IDE (bin/porta)
composer test           # full suite, paratest, ~40s
composer test:serial    # plain phpunit — ordering, races, leak reports
composer analyze        # mago static analysis
composer coverage:check # the suite with coverage, 100% enforced
composer lint           # mago lint
composer arch           # the architecture ratchet
composer format         # mago format (apply)
composer format:check   # mago format --check
composer container      # kernel-less debug:container
```

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

Every commit passes all six. CI (`.github/workflows/ci.yml`) runs them.

**`composer test`** — green. Two tests drive real git and opener subprocesses
and were hardened against timing: the git plugin's
`GitViewControllerTest::testLineStagingAndUnstagingThroughTheDiffPane`
and the async URL-open E2e in `IdeDocsTest`. If one fails under heavy machine
load, re-run it with `--filter` before assuming a regression.

**`composer coverage:check`** — **100% line coverage**, over `src/` *and* over
every plugin's own `src/`. A new branch with no covering test fails the gate. It
runs two passes: the parallel one over `src`, then the serial
`composer coverage:plugins` over `plugins/`. Plugin code must therefore be
covered by that plugin's OWN tests — see [testing.md](testing.md) for why the
plugin half cannot ride the parallel run.

**`composer analyze`** — mago reports zero issues, warnings or help messages.

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
