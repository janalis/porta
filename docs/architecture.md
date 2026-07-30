# Architecture

A contributor-oriented tour. The codebase is PHP 8.4+, namespace `Porta\`,
built on the experimental [symfony/tui](https://github.com/symfony/tui)
component, with everything — UI, subprocesses, LSP, DAP, timers — on a
single [Revolt](https://revolt.run) event loop.

## Composition root

`Ide` (`src/Ide.php`) is the coordinator, sitting over a Symfony DI
container. Its constructor builds a `ContainerBuilder` via
`Container/IdeContainerFactory` (the runtime `rootDir` and locale become
container parameters, so the container is compiled fresh per boot), then
delegates to named `build*` steps — `buildEditorArea`, `buildSidePanels`,
`buildGitView`, `buildSettingsWidgets`, `buildLayoutAndOverlay` — so it
reads top-to-bottom as a composition root.

The container (`config/services.php`, deliberately outside `src/` so the
wiring stays off the coverage gate) autoregisters every service and
singleton widget from `src/`. What the container cannot express stays
hand-built in the matching `build*` step: the composite `ContainerWidget`
tree, `setProvider(...)` closures, RTL pane ordering, per-buffer runtime
objects, and the ~100 `$this`-closures that wire widgets to `Ide`'s
orchestration methods. Three objects are handed to the container as
synthetic services after `Ide` constructs them: `Renderer`, `Tui`, and
`LspSessionPool`.

House rules: **final classes and constructor injection everywhere**; no
service reaches for a global. Never mark a container definition
`lazy: true` (proxying breaks `final` classes).

## Event loop

`revolt/event-loop` drives both the symfony/tui render/input loop and all
async work: LSP over stdio, git subprocesses, the integrated terminal PTY,
run configurations, DAP debugging, plugin subprocesses. Nothing blocks;
long operations are timers and callbacks on the shared loop. A 1-second
filesystem-poll timer reloads clean buffers changed on disk.

## Rendering

Widgets (`src/Widget/**`) render to `string[]` lines measured in **display
columns** (`mb_strwidth`, `AnsiUtils::visibleWidth`) so CJK/wide characters
and ANSI-styled text align correctly. Widgets cache their rendered output;
`invalidate()` bubbles *up*, so a change that must relabel the whole tree
(e.g. a live locale switch) clears render caches recursively. Layout is
horizontal/vertical `ContainerWidget`s; pane sizes derive from rendered
content, with proportions persisted per project in `.porta/layout.json`.
The editor caret is spliced into the output as a zero-width APC marker that
the screen writer converts to a hardware cursor position.

## Commands and keybindings

Single-sourced. `Config/ActionCatalog` is the descriptor table for every
rebindable action (label + default keys). `Config/CommandRegistry` holds
IDE-level commands (id → handler, key-bound?, in-palette?). `Ui/MenuModel`
builds the menu bar and context menus from these. Don't hand-maintain
parallel keybinding/palette/menu lists — extend the catalog/registry.

The `Keybindings` map itself is immutable, built once in `Ide`'s
constructor from catalog defaults + plugin defaults + the user's
`.porta.json` keymap (user wins).

## Editor core

`Editor/Document` is the text buffer: byte-offset cursor kept on UTF-8
boundaries, undo/redo, change tracking, per-file indentation resolved from
`.editorconfig`. `Editor/MultiCaret` and `Editor/EditorActions` layer
multi-cursor and editing operations on top; `Widget/CodeEditorWidget` +
`Widget/Render/EditorRenderer` render the viewport.

## LSP

`Lsp/LspSessionPool` routes traffic to per-language sessions started lazily
on the first `didOpen` of a matching file; sessions are keyed by command,
so JS and TS share one server process. Feature controllers
(`Lsp/NavigationController`, `Lsp/RefactorController`,
`Lsp/CompletionController`) extend `Lsp/AbstractLspController`.

## Debugger (DAP)

`Debug/DapClient` speaks the Debug Adapter Protocol over a child process's
stdio (message framing shared with LSP via `Support/JsonRpcFramer`);
`Debug/DapSession` is one launch's order-agnostic state machine (0-based
lines internally, 1-based on the wire). `Debug/DebugController` owns the
breakpoint store, the editor decorations (gutter `●`, stopped-line band),
the bottom `DebugPanelWidget` (frames + lazily-expanded variables) and the
debuggee-output terminal buffer. Adapters are config-only — see the
[debugger guide](debugger.md).

## Input routing

`Input/MouseRouter` maps every SGR mouse event (modes 1000/1003/1006:
clicks, drags, hover) to the pane under the pointer and owns cross-event
drag/click state. Hit-testing uses the renderer's per-widget rects.

## i18n / RTL

`I18n/Translator` wraps symfony/translation; the English source string *is*
the message id, so untranslated lookups fall back to English. XLIFF
catalogs live in `translations/messages.<locale>.xlf`. RTL (Hebrew) mirrors
the layout — reversed pane order — and `Ui/BidiTui` reorders each composed
row **per pane** with a UAX#9 subset (`I18n/Bidi`), so the reorder never
crosses a pane boundary; code panes (editor, diff, terminal, results) stay
verbatim, and mouse clicks map visual→logical per pane before hit-testing.

## Plugins

`Plugin/PluginLoader` discovers and activates plugin files at boot (and
after a live install); `Plugin/PluginManager` holds the committed state and
wraps every handler/hook in a catch-all; `Plugin/PluginApi` is the
buffered, per-plugin registration surface; `Plugin/PluginRuntime` wires the
live IDE seams late in construction; `Plugin/PluginInstaller` copies/clones
plugin directories for the Settings → Plugins section. A plugin may also own
CLASSES and declare DEPENDENCIES: `Plugin/Runtime/PluginManifest` reads an
optional `plugin.json` before any plugin PHP is required,
`Plugin/Runtime/PluginAutoloader` maps its namespace to its directory (composer
maps only `Porta\ => src/`), and `Plugin/Runtime/PluginOrder` puts the set into
load order, skipping a plugin whose requirement is absent. The user-facing
guide is [plugins.md](plugins.md).

## Tests and quality gates

```
tests/Unit          # per-class tests
tests/Integration   # LSP client against mock/fault-injection servers
tests/E2e           # boot the real Ide on a VirtualTerminal, scripted input
plugins/*/tests     # a plugin's own tests, beside its own code
```

E2e tests feed synthetic input (including SGR mouse sequences and
`.porta.json` fixtures) on scheduled timers and assert on rendered output
and widget state.

Every change must pass all of the gates:

```bash
composer test              # full suite (paratest, parallel)
composer coverage:check    # 100% line coverage of src/ AND of every plugin's src/
composer analyze           # mago static analysis — zero issues
composer lint              # mago linter — zero findings
composer arch              # the namespace/acyclicity ratchet, src/ + plugin src/
composer format            # mago formatter
```

`coverage:check` runs two passes: the parallel one over `src`, then the serial
`coverage:plugins` over `plugins/`. The split is forced by an upstream detail —
paratest workers serialize coverage with paths relative to their own longest
common directory and the merge keeps only the first worker's, so a worker that
covered a file outside `src/` loses all of its data.
