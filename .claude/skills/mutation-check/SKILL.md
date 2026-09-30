---
name: mutation-check
description: Prove a new test or invariant actually guards something in Porta — run `composer mutate` over the uncommitted diff (or mutate by hand) and read the survivors. Use after writing a fix, a guard or a test, before calling it done.
---

CLAUDE.md's rule: **an assertion is not a guard until a mutation kills it.** Coverage
says the line ran; this says something would notice it gone.

## `src/` — automated

```bash
composer mutate > /tmp/porta-mutate.log 2>&1; echo "exit=$?"
grep -A12 "Escaped mutants" /tmp/porta-mutate.log     # or read coverage/infection.log
```

- It mutates only **uncommitted** `src/` lines (`--git-diff-base=HEAD`). Commit first
  and it finds nothing. For a committed range, run `vendor/bin/infection` with
  another `--git-diff-base`, copying the rest of the `mutate` script's flags.
- It judges against the **Unit suite only**. An escape in code only Integration, E2e
  or Plugins covers is a harness artefact: find that covering test before writing one.
- It builds Unit coverage first (paratest, about a minute). Don't run it while another
  suite is running.
- Non-gating. Read survivors like a review. Usually harmless: a literal nothing
  observes (a mkdir mode), a cast for the analyser, comparator weights (1:0 → 2:0), a
  term on both sides of a decision. Worth acting on: a guard whose arms aren't each
  covered, and request payloads nothing asserts. `infection.json5` has the long form.
- A timeout is not an escape.
- Each run leaves ~300MB in `coverage/infection`, and hand runs leave `coverage/infection_*`:
  `composer mutate:clean` removes them all.

## By hand, when the tool can't reach it (plugins, E2e-only code, one guard)

1. Break the guarded line in the working tree: invert the condition, drop the call,
   return early.
2. Run the ONE test that is supposed to catch it. It must go red **for the right
   reason**: read the failure message, not just the exit code.
3. Restore the line (`git diff` must show only your intended change) and re-run
   green.

Report the mutation and the failing assertion in your summary. A guard never seen
failing is marked unconfirmed.

## Plugin `src/` with Infection

`infection.json5` is `src`-only by design. The recipe that reaches `plugins/<name>`:
the config file in the project root, and pcov over the whole repo:

```bash
php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=#/(vendor|tests)/#' \
  vendor/bin/phpunit plugins/<name>/tests --coverage-filter plugins \
  --coverage-xml=coverage/<dir>/coverage-xml --log-junit=coverage/<dir>/junit.xml
```

Infection 0.35.4 aborts on `plugins/git/src/Widget` (`Expected Class_, got Trait_`);
mutate those files by hand. Background: `docs/internals/decisions/history-04.md`,
"Plugin code has never been mutation-reviewed".
