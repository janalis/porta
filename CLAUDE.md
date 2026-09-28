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
composer coverage:check   composer coverage:src   composer coverage:plugins   composer duplication
```

Always invoke the gates through the composer scripts. Capture their output rather than
paging it: `composer analyze > /tmp/x.log 2>&1; echo "exit=$?"`.

The parallel gates default to HALF the cores, because a dev box usually shares them with
runners or another agent (CI sets `PORTA_TEST_PROCESSES: auto`). Turn it down with
`PORTA_TEST_PROCESSES=4 nice -n 10`, or up with `auto`; `contributing.md` has the numbers.

## Definition of done — `composer verify` green

All eight, cheapest first, stopping at the first failure; `verify:quick` skips the tail.

- `composer coverage:check` — **100% line coverage** over `src/` AND every plugin's own
  `src/`, by that plugin's OWN tests. It runs the suite, so `composer test` is not a gate.
- `composer analyze` and `composer lint` — **zero findings each** at any severity: the
  scripts pass `--minimum-fail-level=note`, where mago's own default fails on errors only.
  Fix, or suppress with a reasoned `// @mago-expect lint:<rule> -- why`.
- `composer arch` — fails on drift in EITHER direction. A new edge is added to the
  allowlist in the same commit; a vanished edge is removed from it.
- `composer duplication` — **zero clones** tree-wide, no allowlist: share it, or say in
  the code why the copy is the honest shape.
- `composer format:check` clean; `composer rector:check` **no pending change** — skip, with why.

## Working style

- **Keep going** through any step that doesn't need me; put status notes in the same
  message as the next action. Stop and ask only when you can't continue without me, or
  before anything destructive: deleting data, force-pushing, rewriting pushed history,
  or touching anything outside this repository.
- **The finish line** is `composer verify` green with every finding carrying its
  evidence. Stop early only for a gate failing for a reason you can't explain.
- **A round of more than a handful of items** keeps its checklist in `TASKS.md`
  (gitignored): tick each item when done, add what you find on the way.
- **Fan read-only sweeps out to `porta-explorer`**, one area per agent, and check each
  report's `file:line` evidence before accepting it — a claim argued in prose is not a
  verified one.
- **End every run with three headings: Blocked on me, Changed, Found.** Mark anything
  you couldn't confirm, and say where you looked.

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
| run a gate, or wonder what one demands | `docs/internals/contributing.md` |
| add or move a class, pick a namespace | `docs/internals/code-map.md` |
| add a service, an attribute, a factory | `docs/internals/container.md` |
| touch rendering, input, dock, overlays, git, LSP | `docs/internals/subsystems.md` |
| change plugin loading, ordering, the API surface | `docs/internals/plugin-system.md` |
| write a test, or chase a flake | `docs/internals/testing.md` |
| propose a refactor or a seam — check what is SETTLED | `docs/internals/decisions.md` |
| add or listen to an event | `docs/events.md` |
| write a plugin against the public API | `docs/plugins.md` |
