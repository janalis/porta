# The container

Porta is wired by a Symfony DI container, used as a component rather than
through a Kernel. `Ide` is a thin composition root over it.

## `Ide`

`src/Ide.php` builds the container via `Container/IdeContainerFactory`,
hand-builds only the bootstrap infrastructure (Renderer, `Ui/BidiTui`,
NonBlockingTerminal, StyleSheet) and otherwise **resolves** the graph into
private properties — only the ones its own runtime logic reads.

**`src` carries no test-only resolution API.** The only resolver is the private,
locator-only `service()`, so a class that forgets `#[AsIdeService]` fails loudly
instead of silently resolving through the raw container. Tests reach a running
IDE's services through `tests/Support/Services::resolve($ide, X::class)`.

The `build*` steps — `buildEditorArea`, `buildGitView`, `buildSettingsWidgets`,
`buildLayoutAndOverlay` — are resolution and arrangement only. The constructor
tail then runs, in order:

1. `Command/CommandRegistryPopulator::populate()` — the tagged providers.
2. `Bootstrap/WidgetHooks::wire()` — the widgets' runtime hooks.
3. `Bootstrap/EagerServices::boot()` — the tagged eager services.
4. The menu bake, and the `Input/InputDispatcher` listener.
5. `Bootstrap/StartupWiring::wire()` — hands the plugin handlers their live
   `PluginRuntime` seam and flashes config and plugin load warnings. A
   `WidgetHooks` sibling, kept separate so neither constructor grows unwieldy.

So **commands register and menus bake at the end of the constructor.**

`run()` sets up the terminal (alternate screen, SGR mouse modes 1000/1003/1006),
calls `LspBootstrapper::start()`, arms a 1s filesystem-poll timer and enters
`tui->run()`. Teardown — mouse disable, session save, LSP/git/run shutdown — is
in the `finally`.

## It is dumped and cached across boots

Per-boot values travel through `$_ENV` and resolve at runtime via `%env()%`
placeholders, so **the compiled container is project-independent** and one dump
serves every project the IDE opens.

Never `putenv` — `PtyProcess` leaks `getenv()` into child shells.

Scalars (`rootDir`, `locale`, `ansi`, `theme`, `lspCommand`, `lspDisabled`,
`docsDir`) go through directly; the nullable one uses
`env('default::PORTA_LSP_COMMAND')`, where `''` means null. Per-project **config
arrays** are JSON-encoded by `IdeContainerFactory` and consumed **at the
consumer's parameter**:

```php
#[Autowire('%env(json:PORTA_LANGUAGES)%')]
```

Never a central bind — the parameter names its own channel, which keeps the
split explicit and stays dump-safe because the argument is only a placeholder.

`IdeContainerFactory` dumps once into the user cache dir (`$PORTA_CACHE_DIR`,
else XDG or `~/.cache/porta`), guarded by a debug-mode `ConfigCache` whose
tracked resources — `services.php`, the `src/` glob, per-class reflection
including method attributes — rebuild it automatically on any wiring-relevant
change.

`PORTA_CONTAINER_CACHE=off` forces an in-memory build. A builder used directly
must `compile(resolveEnvPlaceholders: true)`; the dump path keeps placeholders
unresolved. **Deleting the cache dir is always safe** — do that if a
stale-container symptom ever survives an edit.

## Rules for `config/services.php`

It lives outside `src/` so the wiring stays off the 100% line-coverage gate.

Services are autoregistered by `$services->load('Porta\\', '../src/*')`,
excluding `Ide.php`, `Container/`, and the measured non-service tiers:
`Console/`, `Event/`, `Git/*Parser.php`, `Highlight/Json/`, `Widget/Render/`,
`Widget/Support/`. A class in one of those that becomes a real service must be
un-excluded — Symfony's error names the exclusion, so that edit is the review
point.

Non-service classes in mixed directories still register private and are pruned
at compile because nothing public references them. This is fragile in one
direction: if a **public** service ever gains a dependency on a pruned class,
compile fails with an autowiring error. Explicit at boot, caught by the first
E2e test.

`_defaults` stays **private**. The single public boundary is the `porta.ide`
`ServiceLocator` plus the synthetics.

**Every definition is shared. Never `lazy: true`** — pure overhead for shared
app-lifetime services. (The old "proxies break `final`" reason is obsolete: PHP
8.4 native lazy objects do not subclass.)

## The attributes

| Attribute | Effect |
| --- | --- |
| `#[AsIdeService]` | membership of the `porta.ide` locator |
| `#[AsWidget(id, flex)]` | a singleton widget; autoconfigured into `setId`/`setStyle` |
| `#[AsEager(priority)]` | construct at the ctor tail for a self-registering side effect |
| `#[AsChromeRoot]` | a root the live-setting appliers sweep |
| `#[LayoutSlot('name')]` | select one of several same-typed widgets |

All five are Symfony-native: three are `AutoconfigureTag` subclasses, one is an
`Autowire` subclass, and `AsWidget` uses the stock
`registerAttributeForAutoconfiguration()` API because it builds a nested `Style`
definition, which a tag cannot express.

**`#[AsEager]`** exists for services that register themselves with something or hook
`BidiTui::onBeforeFrame` and have no inbound reference — without a forced
resolve they would never exist. Its priority fixes contractual construction
order (the find → picker → toast presenters). Note the priority is deliberately
*not* a promoted property: the autoconfigure pass `(array)`-casts the attribute,
and a stray public property fails config parsing.

**`#[AsChromeRoot]`** members are injected into `Workbench/ChromeInvalidator` as
an `#[AutowireIterator]` — order-independent, since it only clears render
caches — so there is no `register()` list in `Ide`. Eight chrome widgets carry
the attribute; the ninth root (`$main`, a getter service) is tagged at its
definition, the same exception `Keybindings` and `event_dispatcher` need.
`ChromeRootMembershipTest` guards the tagged set.

**`#[LayoutSlot('editor_body')]`** is a thin `#[Autowire]` subclass resolving to
`porta.widget.<slot>`, so the stock AutowirePass handles it natively with no
compiler pass. It moves disambiguation to the parameter that knows the answer,
which is why there are no central binds and two same-type same-name
`SeparatorWidget $separator` routers simply carry different slots.

## Aliases, factories and tags

**Interface aliases are auto-derived by the loader itself.** `PhpFileLoader::load()`
ends in `registerAliasesForSinglyImplementedInterfaces()`, which aliases every
`Porta\` interface with exactly one discovered implementation and skips any that
gains a second. A multi-implementation interface stays an explicit `alias()`, as
do cross-vendor ones (`ClockInterface`, `TerminalTheme`,
`EventDispatcherInterface`).

`Ui/FrameHooks` is aliased explicitly on purpose: `BidiTui`'s definition is
overridden to synthetic *after* the loader's load-time interface counting, so a
natively derived alias there would be a load-order accident — and a nullable
dependency fails silently.

**Static self-factories declare themselves on the class** with
`#[Autoconfigure(constructor: '…')]` rather than a central `->factory()`
definition. A collaborator-instance factory or a cross-class one cannot, and
stays an explicit definition.

**Tagged iterators carry contractual order.** Command providers register in
`#[AsTaggedItem]` priority order, and registration order *is* the palette
display order, with core before plugins so core wins key collisions. Settings
sections are sharper still: the general section claims *every* id as the
fallback, so promoting it above another section makes that section's page
unreachable **silently** — the form still renders. `SettingsSectionOrderTest`
pins the order.

## Synthetics

Four objects are handed to the container right after `Ide` creates them:
`Renderer`, `Tui`, `BidiTui` (external infrastructure) and `IdeConfig` (parsed
before the container exists, since its settings steer the env parameters).

They are **private**. `PhpDumper` emits every synthetic id regardless of
visibility and `Container::set()` accepts them, so the test seam still reaches
them. The tripwire: a private synthetic that loses its last internal consumer is
compile-removed, and the boot-time `set()` throws — loud, and caught by the
first E2e test.

## Role interfaces over the editor widget

`CodeEditorWidget` has 81 public methods, so consumers depend on the narrow role
they use: `ActiveFileProvider`, `ActiveDocumentProvider`, `EditorTextAccess`,
`EditorInvalidator`, `ModalHost`, `EditorDecorationSink`, `LineAnnotationSink`,
`EditorViewOptions`, `EditorIndentTarget`, `EditorKeymapProvider`,
`EditorCommandTarget`. The loader's single-implementation aliasing covers each
with zero container config.

A consumer whose methods span two roles takes **two parameters of the same
widget** — each resolves through its own alias, and tests hand in two narrow
fakes.

**One-consumer interfaces are forbidden**, as is widening an existing role for
one caller. Consumers that stay concrete do so on purpose, each noted at the
site: `Lsp/AbstractLspController` (its subclasses reach the inherited `$editor`
for eleven methods), `Editor/EditorFileActions` and `Run/RunController` (they
also hand the widget to `setFocus()`), `Input/EditorLayoutPanes` (the mouse
routers own a wide slice by design), and the one-method callers whose method
lives in no role.

`BufferService` was re-measured the same way: no consumer uses only the
`FileOpener` slice, so it keeps concrete hints beyond the extracted
`DiagnosticsSink`.

## Inspecting it

`composer container` is the kernel-less `debug:container` equivalent. Flags go
after `--`: `--loaded`, `--tag=<name>`, `--aliases`.
