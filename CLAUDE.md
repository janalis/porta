# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Porta is a terminal IDE for PHP projects (namespace `Porta\`, PHP 8.4+), built on the
experimental `symfony/tui` component with an LSP client, syntax highlighting and
autocompletion. It runs the whole UI and all async I/O on a single Revolt event loop.

This file is what you need in every session. Everything else lives in `docs/internals/`,
routed below — read the page before working in that area, not after.

## Commands

```bash
composer dev      composer test      composer test:serial
composer analyze  composer lint      composer arch
composer format   composer format:check
composer coverage:check              composer container
```

Always invoke the gates through the composer scripts. Capture their output rather than
paging it: `composer analyze > /tmp/x.log 2>&1; echo "exit=$?"`.

## Definition of done — every commit passes all six

- `composer test` — green.
- `composer coverage:check` — **100% line coverage**, over `src/` AND every plugin's own
  `src/`. Plugin code must be covered by that plugin's OWN tests.
- `composer analyze` — zero issues, warnings or help messages. Suppress in place with
  `// @mago-expect analysis:<rule> -- why` ONLY when the cause is outside this codebase
  (a wrong stub) or narrowing would add a branch coverage cannot reach.
- `composer lint` — **zero findings**. Fix a new finding, or suppress it in place with a
  reasoned `// @mago-expect lint:<rule> -- why`. **Both gates exit 0 while reporting
  issues**, so the exit code is not the gate — read the tail of the output. The tail
  reached 93 findings once before anyone noticed, and the run that noticed is the one
  that caught `mago lint --fix` deleting a pipe-drain loop and deadlocking the suite.
  Never run `--fix` unattended; verify with the FULL suite, since every file it touched
  passed on its own.
- `composer arch` — fails on drift in EITHER direction. A new edge is added to the
  allowlist in the same commit; a vanished edge is removed from it.
- `composer format:check` — clean.

## Hard rules

- **Final classes, constructor injection everywhere.** No class reaches for a global.
- **No `$this` inside injected `static fn` closures** — mago rejects it.
- The formatter **never re-wraps lines**. Keep your own line breaks.
- **Never `lazy: true`** on a container definition. Every definition is shared.
- **One-consumer interfaces are forbidden**, as is widening a role for one caller.
- **FQCN is determined by path**, so a move is a pure rename. Grep the old namespace to
  prove zero.
- **Events carry scalars, enums and VOs — never widget instances.** Never dispatch from a
  render path. The dispatcher is an optional TRAILING constructor argument on a widget.
- A command event (`…Requested`) has **exactly one** listener; a fact may have any number.
- **`Notifier::notify()` already requests a render.** Never follow a flash with your own.
- **No test-only code in `src`.** Private-state reflection lives only in the blessed
  `tests/Support` seams (`Services`, `Probe`, `EditorState`, `StatusReads`, `Toasts`).
- **An invariant assertion is not a guard until a mutation kills it.** Coverage says a line
  ran, not that anything would notice its absence. Mutation-check the fix too.
- **Never hand-roll a test rig `tests/Support` already provides.**
- **No fixed-gap asserts in E2e**: await the effect in the SAME step. A bare `tick()` is
  not a frame.
- `await()`'s condition must re-evaluate — `static function () use (&$x)`, never an arrow
  fn capturing by value.
- When a constructor gains a dependency, **fix the direct construction sites in tests too**.
- **A plugin class can never be a container service.** Everything it needs is hand-passed.
- Prefer **anonymous classes in plugin files** — cross-plugin redeclaration is an
  uncatchable fatal.

## Where to read more

| About to… | Read |
| --- | --- |
| run a gate, or wonder what one demands | `docs/internals/contributing.md` |
| add or move a class, pick a namespace | `docs/internals/code-map.md` |
| add a service, an attribute, a factory | `docs/internals/container.md` |
| touch rendering, input, dock, overlays, git, LSP | `docs/internals/subsystems.md` |
| change plugin loading, ordering, the API surface | `docs/internals/plugin-system.md` |
| write a test, or chase a flake | `docs/internals/testing.md` |
| propose a refactor or a new seam | `docs/internals/decisions.md` |
| add or listen to an event | `docs/events.md` |
| write a plugin against the public API | `docs/plugins.md` |

## Settled — do not re-propose without new evidence

Each was measured and turned down. `docs/internals/decisions.md` has the numbers and says
what would re-open it. Re-proposing one costs a round.

- Adopting FrameworkBundle or a Kernel; `inline_factories`; `tagged_locator()` for the
  locator; `classmap-authoritative` in `composer.json`.
- The extraction audit — every candidate measured, the topic closed. Git is the
  one that re-opened and landed: `src/Git` and `src/Widget/Git` are
  `plugins/git/` now, and core keeps no git-named symbol.
- Namespace cycles — closed at class granularity.
- `Layout/ViewRegion` as a RENAME of the compiled slots (runtime minting replaced
  it); clickable status segments (reopened — the branch cell is the consumer).
- `registerWelcomeStep()`; unifying the five plain-array plugin buffers.
- Escalating `[loop-leak]` to a failure — refused on evidence.
