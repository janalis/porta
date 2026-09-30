# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Porta is a terminal IDE for PHP projects (namespace `Porta\`, PHP 8.5+), built on the
experimental `symfony/tui` component with an LSP client, syntax highlighting and
autocompletion. It runs the whole UI and all async I/O on a single Revolt event loop.

This file is what you need in every session. Everything else lives in `docs/internals/`,
routed below — read the page before working in that area, not after.

## Commands

```bash
composer verify   composer verify:quick  composer dev   composer test[:unit|:e2e|:plugins]  composer arch  composer container
composer analyze  composer lint   composer format  composer format:check  composer rector  composer rector:check
composer coverage:check   composer coverage:src   composer coverage:plugins   composer duplication   composer mutate   composer flake:scan
```

Always invoke the gates through the composer scripts, output captured, not paged:
`composer analyze > /tmp/x.log 2>&1; echo "exit=$?"`. The `gates` skill has the inner loop.

The parallel gates default to HALF the cores (the plugin shards to ¾), because a dev box is
usually shared (CI sets `PORTA_TEST_PROCESSES: auto`). Throttle BOTH: `PORTA_TEST_PROCESSES=4
PORTA_SHARD_PROCESSES=4 nice -n 10`, or raise with `auto`; `contributing.md` has the numbers.

## Definition of done — `composer verify` green

Eight, in run order, stopping at the first failure; `verify:quick` is the first six.

- `composer format:check` clean; `composer rector:check` **no pending change** — skip, with why.
- `composer lint` and `composer analyze` — **zero findings each** at any severity: the
  scripts pass `--minimum-fail-level=note`, where mago's own default fails on errors only.
  Fix, or suppress with a reasoned `// @mago-expect lint:<rule> -- why`.
- `composer arch` — fails on drift in EITHER direction. A new edge is added to the
  allowlist in the same commit; a vanished edge is removed from it.
- `composer duplication` — **zero clones** tree-wide, no allowlist: share it, or say in
  the code why the copy is the honest shape.
- `composer coverage:src` then `coverage:plugins` (= `coverage:check`) — **100% line coverage**
  over `src/` AND every plugin's own `src/`, by its OWN tests. `composer test` is not a gate.

## Hard rules

- **Final classes, constructor injection everywhere.** No class reaches for a global.
- **No `$this` inside injected `static fn` closures** — mago rejects it.
- The formatter **never re-wraps lines**. Keep your own line breaks.
- **Never `lazy: true`** on a container definition. Every definition is shared.
- **One-consumer interfaces are forbidden**, as is widening a role for one caller.
- **FQCN is determined by path**, so a move is a pure rename. Grep the old namespace: zero.
- **Events carry scalars, enums and VOs — never widget instances.** Never dispatch from a
  render path. The dispatcher is an optional TRAILING constructor argument on a widget.
- A command event (`…Requested`) has **exactly one** listener; a fact may have any number.
- **`Notifier::notify()` already requests a render.** Never follow a flash with your own.
- **No test-only code in `src`.** Private-state reflection lives only in the blessed
  `tests/Support` seams (`Services`, `Probe`, `EditorState`, `StatusReads`, `Toasts`).
- **An invariant assertion is not a guard until a mutation kills it.** Coverage says a line
  ran, not that anything would notice its absence. Mutation-check the fix too.
- **Never hand-roll a test rig `tests/Support` already provides.**
- **One suite at a time, and never edit while one runs** — paratest reads as it goes.
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
| run a gate, start a long round, or end a run | `docs/internals/contributing.md` |
| add or move a class, pick a namespace | `docs/internals/code-map.md` |
| add a service, an attribute, a factory | `docs/internals/container.md` |
| touch rendering, input, dock, overlays, git, LSP | `docs/internals/subsystems.md` |
| change how a control, toast, tab or list LOOKS | `docs/internals/ui-style.md` |
| change plugin loading, ordering, the API surface | `docs/internals/plugin-system.md` |
| write a test, or chase a flake | `docs/internals/testing.md` |
| propose a refactor or a new seam | `docs/internals/decisions.md` |
| add or listen to an event | `docs/events.md` |
| write a plugin against the public API | `docs/plugins.md` |

## Settled — do not re-propose without new evidence

Each was measured and turned down. `docs/internals/decisions.md` says what would re-open it;
its dated history (look entries up, never read it whole) has the numbers. Re-proposing costs a round.

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
- The config scope's merge rules — above all a **union for `enabledPlugins`**, which
  looks obviously right and is not: an explicit list REPLACES, even an empty one.
