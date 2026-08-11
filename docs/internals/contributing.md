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

**`composer lint`** — **exit 0, which is not zero findings.** The metric rules
report at two severities and only the error-level ones (cyclomatic,
`too-many-*`, `excessive-parameter-list`, `kan-defect`) flip the exit code. A
standing tail of warning and help findings is expected; what the gate forbids is
a *new* one, so compare against the previous run rather than reading the count.
`[linter.rules]` disables nothing — every rule runs, calibrated to this
codebase's maxima. `excludes` drops paths: the six mock LSP/DAP servers, which
are procedural scripts by design. The threshold rationale, and which class pins
each number, is in `mago.toml` itself.

**`composer arch`** — the architecture ratchet
(`src/Console/ArchitectureCheckCommand`): the exact namespace import graph,
class-level acyclicity with a pinned set of accepted cycles, and the E2e
suite-size bound. **It fails on drift in either direction** — a new edge must be
added to the embedded allowlist in the same commit, and a vanished edge must be
removed from it. A stale allowlist is not a gate.

**`composer format:check`** — clean.

The gates are ordinary `src/` code: analysed by mago and 100%-covered like
everything else. That is why each takes its inputs as seams — the arch
command's scan roots come from an argument and its pins are
constructor-injected, so it can be driven over fixture trees. It scans `src/`
and each plugin's declared class directory, which means it scans itself: keep
it importing nothing from `Porta\`, or it grows the very edges it grades. That
is also why it re-reads each plugin's `composer.json` by hand instead of using
`PluginManifest`.

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
