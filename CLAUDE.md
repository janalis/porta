# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Porta is a terminal IDE for PHP projects (namespace `Porta\`, PHP 8.4+), built on the
experimental `symfony/tui` component with an LSP client, syntax highlighting and
autocompletion. It runs the whole UI and all async I/O on a single Revolt event loop.

## Commands

```bash
composer dev                 # run the IDE (bin/porta)
composer test                # full suite via paratest (parallel, ~20s)
composer test:serial         # plain phpunit (serial) — use when debugging ordering/races
composer analyze             # mago static analysis — MUST be clean (zero issues/warnings/help)
composer coverage:check      # runs the suite with coverage and enforces 100% line coverage
composer format              # mago format (apply)
composer format:check        # mago format --check
composer lint                # mago lint

# Run a single test / filter
vendor/bin/phpunit --filter testName
vendor/bin/phpunit tests/Unit/I18n/BidiTest.php
```

Run gate commands capturing output rather than piping to a pager, e.g.
`composer analyze > /tmp/x.log 2>&1; echo "exit=$?"`.

### Definition of done (every commit must pass all three)

- `composer test` green (two tests are known-flaky and pass on isolated re-run:
  `GitViewControllerTest::testLineStagingAndUnstagingThroughTheDiffPane`, and an async
  URL-open E2e; re-run with `--filter` to confirm before assuming a regression).
- `composer coverage:check` — **100% line coverage is required**. New code needs tests that
  hit every branch; a new branch with no covering test fails the gate.
- `composer analyze` — mago reports **zero** issues, warnings, or help messages.

## Conventions

- **Final classes + constructor injection everywhere.** Collaborators are threaded as
  constructor arguments — no class reaches for a global. Services that are expressible as
  service+scalar injection are built by a **Symfony DI container** (see the Architecture note on
  `Ide`); everything else (widgets needing per-instance config, objects wired with `$this`
  closures) is still hand-constructed in `Ide`. When you add a dependency to a container-built
  class, autowiring handles it; when you add one to a hand-built class, inject it and update
  every construction site (including tests).
- **No `$this` inside injected `static fn` closures** — mago rejects it.
- The formatter (`mago format`, Symfony-style) **never re-wraps lines** (print-width 200,
  preserve-breaking). Keep your own line breaks; don't fight it.
- mago analyzes `src` only (`tests`/`tools`/`vendor` excluded from reporting), so type
  annotations matter in `src`. `vendor` is included for type info: **`symfony/tui`'s `Renderer`
  and `ScreenWriter` are `final`** and cannot be subclassed — see `Porta\Ui\BidiTui` for the
  pattern used when a vendored seam doesn't exist (subclass `Tui`, override `processRender`).

## Architecture

**`Ide` (`src/Ide.php`) is the coordinator, over a Symfony DI container.** Its constructor builds
a `ContainerBuilder` via `Container/IdeContainerFactory` (runtime `rootDir`/`locale` become
container parameters; compiled fresh per boot because `rootDir` differs per project, so it can't
be dumped/cached), then delegates to a sequence of named `build*` steps (`buildEditorArea`,
`buildSidePanels`, `buildGitView`, `buildSettingsWidgets`, `buildLayoutAndOverlay`) so it reads
top-to-bottom as a composition root. `run()` sets up the terminal (alternate screen + SGR mouse
modes 1000/1003/1006), arms a 1s filesystem-poll timer, and enters `tui->run()`. Teardown (mouse
disable, session save, LSP/git/run shutdown) is in the `finally`.

The container owns construction of every service expressible as **service + scalar injection**
(the leaves like `Translator`/`GitInfo`, plus autowired controllers like `WorkspaceEditor`,
`CompletionController`, `PanelManager`, `LayoutController`). `Ide` resolves each via the typed
`service()` helper and stores it into its existing private property (so reflection-based tests
keep working). What the container **cannot** express stays hand-built in the matching `build*`
step: the widget tree (`setId`/`setStyle`/`add`, the 6 `ContainerWidget`s / 3 `SeparatorWidget`s
which are duplicate-typed and un-autowirable), RTL pane ordering, the `tui->add` Z-order chain,
per-buffer runtime objects (`Buffer`/`Document`/`CodeHighlighter`), and the ~100 `$this`-closures
that wire widgets to `Ide`'s orchestration methods. Manually-built singletons that container
services depend on (renderer, tui, the chrome widgets, `LspSessionPool`, `GitSidebar`) are handed
to the container as **synthetic** services right after `Ide` builds them.

**Rules for the container** (`config/services.php`, deliberately **outside `src/`** so the wiring
stays off the 100%-line-coverage gate): every def is **shared** and **public** (Ide resolves them
by id across the locator boundary); **never `lazy: true`** — it proxy-subclasses, which breaks on
`final` classes; add new service defs there and resolve with `service()`. Add construction that
the container can't own to the matching `build*` step, not as another extracted class.

**Single event loop.** `revolt/event-loop` drives both the `symfony/tui` render/input loop and
all async work (LSP over stdio, git subprocesses, the integrated terminal PTY, run
configurations). Nothing blocks; long operations are timers/callbacks on the shared loop.

**Rendering pipeline (`symfony/tui`).** Widgets (`src/Widget/**`) render to `string[]` lines
measured in **display columns** (`mb_strwidth`, `AnsiUtils::visibleWidth`) so CJK/wide chars and
ANSI-styled text align correctly. Widgets cache their rendered output; `invalidate()` only
bubbles *up*, so a change that must relabel the whole tree (e.g. a live locale switch) has to
clear render caches recursively. Layout is horizontal/vertical `ContainerWidget`s; sizes come
from rendered content, with proportions persisted per project.

**Commands & keybindings are single-sourced.** `Config/ActionCatalog` is the descriptor table
for every rebindable action (label + default keys). `Config/CommandRegistry` holds IDE-level
commands (id → handler, key-bound?, in-palette?). `Ui/MenuModel` builds the menu bar / context
menus from these. Don't hand-maintain parallel keybinding/palette/menu lists — extend the
catalog/registry.

**Editor core.** `Editor/Document` is the text buffer (byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indent via `.editorconfig`). `Editor/MultiCaret`
and `Editor/EditorActions` layer multi-cursor and editing operations; `Widget/CodeEditorWidget`
+ `Widget/Render/EditorRenderer` render the viewport (the caret is spliced in as a zero-width
APC marker that `ScreenWriter` converts to a hardware cursor position).

**LSP.** `Lsp/LspSessionPool` routes traffic to per-language sessions started lazily on the first
`didOpen` of a matching file; sessions are keyed by command (shared processes for JS/TS on one
server). Feature controllers (`Lsp/NavigationController`, `Lsp/RefactorController`,
`Lsp/CompletionController`) extend `Lsp/AbstractLspController`.

**Input routing.** `Input/MouseRouter` maps every SGR mouse event to the pane under the pointer
and owns cross-event drag/click state. Hit-testing uses the renderer's per-widget rects.

**Per-project config: `.porta.json`** (parsed by `Config/IdeConfig`) with keys `keymap`,
`settings`, `languages`, `lsp`. Settings (`Config/SettingsController` + the `Settings/` view)
apply live where possible; some require restart.

**i18n / RTL.** `I18n/Translator` wraps `symfony/translation`; the English source string *is* the
message id (so untranslated lookups fall back to English) with XLIFF catalogs in `translations/`
(`messages.<locale>.xlf`). RTL (Hebrew) mirrors the layout (reversed pane order) and reorders each
composed line via `I18n/Bidi` (a UAX#9 subset) hooked through `Ui/BidiTui`; mouse clicks are
mapped visual→logical via `Bidi::visualToLogicalColumn` before hit-testing.

## Tests

`tests/Unit`, `tests/Integration`, `tests/E2e`. E2e tests boot a real `Ide` against a
`VirtualTerminal` and a `TempProject`, feed synthetic input (including SGR mouse sequences and
`.porta.json` fixtures) on scheduled timers, and assert on rendered output / widget state. When a
constructor gains a dependency, fix the direct construction sites in these tests too.
