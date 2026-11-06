---
name: gates
description: Run Porta's gates the cheap way — the inner loop while working, `composer verify` once at the end, output captured, load throttled, a hung paratest recognised. Use before running any composer gate or test suite in this repo.
---

The loop, cheapest first.

```bash
composer fix                                                           # rector, THEN format — rector's output is what needs formatting
composer check > /tmp/porta-quick.log 2>&1; echo "exit=$?"            # ~8s: every quick gate, run to the end; last line names each red one
PORTA_TEST_PROCESSES=24 composer test:unit > /tmp/porta-unit.log 2>&1; echo "exit=$?"   # ~22s
composer verify > /tmp/porta-verify.log 2>&1; echo "exit=$?"          # ~140–200s on a busy box (the old sequential chain took ~300–340s): the definition of done
```

- **Run one file or folder** through the same script (paratest takes ONE path):
  `composer test -- tests/Unit/Editor`; one test: `composer test:unit -- --filter name`.
- **"Is my new class covered?"** costs seconds, not the gate:
  `composer coverage -- --coverage-filter=src/Editor tests/Unit/Editor`, then
  `bin/porta-dev coverage:check coverage/clover.xml 100 --only=/src/Editor/`.
  It overwrites `coverage/clover.xml`; the real gate re-runs it anyway.
- **`test:unit` skips** Integration, E2e and Plugins — anything about wiring, focus,
  rendering or a plugin. Run `composer test:e2e` / `test:plugins` when you touched those.
- **Tests for the diff**: `composer test:affected` (`-- --list` to see the selection,
  `-- --coverage` for each changed source's uncovered lines). It selects by NAME; a
  changed file no test names, or a non-PHP change, runs the whole suite and says
  which. Not a gate (`docs/internals/contributing.md`, "The inner loop").
- **Read the log, not the rtk summary.** rtk can print `PHPUnit: ok` over a crash:
  `grep -E "FAIL|Error|Fatal|exit=" /tmp/porta-*.log`, or run through `rtk proxy`.

## Load

- The box is often shared. Throttle BOTH knobs:
  `PORTA_TEST_PROCESSES=4 PORTA_SHARD_PROCESSES=4 nice -n 10 composer verify`.
  With the box to yourself, raise with `PORTA_TEST_PROCESSES=auto` (or 24).
- `uptime` before blaming a test. A *different* handful failing each run is load; the
  *same* test failing every run is a defect. Re-run a failing class alone.

## Never

- Two suite runners at once on one checkout, or an edit while a suite runs — paratest
  reads test files as it goes.
- A new gate command chained after a failing one: read why it failed first.

## A run that stops moving

paratest at ~99% with no output for minutes is the **wedge**, not a slow test:
`ps -o etime=,pcpu= <pid>` shows 0.0% CPU and `pgrep -P <pid>` no children.
Collect the evidence in `docs/internals/testing.md` ("The paratest wedge") BEFORE
killing it, then kill that runner and re-run.

## When a gate is red

| Gate | Where the rule is |
| --- | --- |
| lint / analyze | fix, or `// @mago-expect lint:<rule> -- why` |
| arch | add the new edge / remove the vanished one in `src/Console/ArchitectureCheckCommand.php`, same commit |
| duplication | share the code, or say in the code why the copy is honest |
| custos | fix, or `// @noinspection <Rule>Inspection -- why`; a rule that fights mago or rector is switched off in `custos.json` (`decisions.md`) |
| rector:check | `composer fix` (rector then format), then lint again |

`composer check` is `verify:quick` without the stop at the first red; `verify` itself
still stops after a red static gate, then runs both coverage passes at once and names
every red one.

## The lint rules agents trip most

Measured over a month of sessions — each of these came back in more than five. Write
the passing shape the first time:

| Rule | Passing shape |
| --- | --- |
| `literal-named-argument` | name every literal after the first: `\str_pad($s, length: 3, pad_string: ' ')`, `\explode("\n", $t, limit: 2)` |
| `prefer-static-closure` | `static fn` / `static function` whenever `$this` is not used |
| `no-boolean-flag-parameter` | two methods or an enum; if the bool IS the one fact the call carries, `// @mago-expect lint:no-boolean-flag-parameter -- why` above the method (76 in `src`) |
| `mixed-assignment` | narrow at the source (`\is_array($x) ? … : []`); a decoded JSON value may carry `@mago-expect analysis:mixed-assignment -- narrowed below` |
| `possibly-invalid-argument` / `less-specific-nested-argument-type` | narrow the value (`\is_string`, a `@var list<string>` on a decoded array) before the call, not a cast |
| `unfulfilled-expect` | the suppression outlived its finding — delete the `@mago-expect` line |
| `halstead` | the method is too dense: extract a named private helper, do not suppress |

A suppression is `// @mago-expect <lint|analysis>:<rule> -- why`, on the line above.
| coverage | `docs/internals/contributing.md`, "Definition of done"; plugin code needs its OWN tests |
