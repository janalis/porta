# Events

Porta's subsystems talk to each other over one `symfony/event-dispatcher`
bus. Its event classes live in `src/Event/` (`ls` it for the set), every one of them
`final readonly`, and listeners are compiled into the dispatcher's map from
attributes — there is no runtime registration in core.

A **plugin** cannot use either half of that: it cannot carry
`#[AsEventListener]`, and its own events do not belong in `src/Event/`. It
listens with `PluginApi::on()` (restricted to the `PluginObservable` subset) and
raises its own on a dispatcher it constructs itself. The bundled git plugin does
both — its widget→controller events left `src/Event/` with it (and the diff
pane's with the diff plugin), and the three facts anything may observe stayed.

If you are writing a *plugin*, the page you want is
[Plugins → Listening to core events](plugins.md); this one is about the
mechanism itself.

## How it is wired

Kernel-less, in `config/services.php`:

- `#[AsEventListener]` on a method is autoconfigured into a
  `kernel.event_listener` tag, and Symfony's `RegisterListenersPass` compiles
  those tags into the dispatcher's listener map at container-build time.
- The service id must be exactly `event_dispatcher` — that pass hard-codes it.
- `src/Event/` is **excluded from service discovery**. Events are values, never
  services.

Emitters take the PSR `EventDispatcherInterface` (it has `dispatch()` and
nothing else). Only two consumers take Symfony's component interface, because
they need more: `PluginManager` adds and removes plugin listeners at runtime,
and `Ide` resolves it to dispatch the two lifecycle events.

## Facts and commands

The bus carries two shapes, and the names distinguish them:

> **A fact is true at the moment it is dispatched. A command becomes true only
> if a listener acts.**

```php
new BufferSaved($buffer)          // the write already happened
new DockTabCloseRequested($tab)   // the tab is still open
```

Commands carry the **`…Requested`** suffix. Facts do not.

The rule decides the awkward cases without argument. `DockToolClosed` is
dispatched *after* `tools->close()`, so it is a fact. `DockTabMoveRequested`
fires from a drag-drop the dock has not performed, so it is not.
`TreeFileActivated` is a fact even though a file gets opened — the user *did*
activate it, and what happens next is the listener's business, not the
event's.

Two consequences worth knowing:

- **A command has exactly one listener**, enforced by a test. Two things
  performing one command is a design error — they would race.
- **A fact may have any number**, which is the whole reason for a bus.
  `BufferSwitched` has five.

`FileRenamed` and `BufferRepointRequested` are the pair worth studying, because
one rename dispatches both. The re-point is a **command**: exactly one listener
closes the stale buffer and re-opens the new path, and it exists only to break a
container cycle. The rename is a **fact**: every store keyed by path — bookmarks,
breakpoints — has to follow, none of them owes the other anything, and a third
such store just adds a listener.

`FileRenamed` also shows why a fact's contract belongs in its docblock:
**both its paths are canonical**, resolved by the dispatcher, because by the
time a listener runs the old path is gone from disk and `realpath()` on it
returns false. Only the dispatcher is still in a position to answer.

`FileDeleted` is the same fact for the other half of the question. Both carry
one more clause: **a directory takes its whole tree**, while the stores are
keyed by the files inside it. So a listener asks `ProjectPaths::isUnder()`
(deleted) or `ProjectPaths::moved()` (renamed) rather than comparing paths —
both of which answer the same as `===` for a plain file, so a listener never
has to know which it was handed.

## Priority

Listeners run in descending priority. The ladder is
`Porta\Container\ListenerPriority`, and there are only four rungs:

| Rung | Value | Who and why |
| --- | --- | --- |
| `CORE` | `0` | The default. React, then leave the state for everything below. |
| `PLUGIN` | `-100` | Third-party listeners, so a plugin sees what core already did. |
| `CHROME_SWEEP` | `-100` | Cache sweeps that only need to follow the priority-0 reactions. Shares `PLUGIN`'s rung deliberately: neither depends on the other. |
| `CACHE_DROP` | `-200` | Below plugins on purpose — a highlighting backend that bakes theme colours rebuilds *first*, then the caches holding its old output are dropped. |

Do not confuse these with `#[AsEager]` and `#[AsTaggedItem]` priorities, which
run 100–700 and order *construction* or *registration* rather than reaction.
Overlapping magnitudes, unrelated meanings.

## Adding an event

1. A `final readonly` class in `src/Event/`, named by the rule above.
2. Dispatch it. A service takes the PSR interface as a constructor argument; a
   **widget** takes it as an **optional trailing** argument and uses
   `$this->events?->dispatch(...)`, so widgets stay constructible bare in
   tests. Trailing matters — putting it anywhere else silently re-binds every
   positional caller.
3. Listen to it with `#[AsEventListener]` on a public method taking the event.

Three rules constrain what an event may carry and where it may be dispatched:

- **Payloads are scalars, enums or value objects — never widget instances.**
  That is what keeps the class graph acyclic while both `Widget → Event` and
  `Event → Widget` edges exist.
- **Never dispatch from a render path** (`beforeRender`, `render`,
  `processRender`). Listener count is unbounded; a frame's cost should not be.
- **A listener that mutates the thing that dispatched must land on a setter
  that does not re-fire**, or you have a loop. Where that matters, the event
  says so — see `DockToolBound`, dispatched at the tail of the bind for exactly
  this reason.

There is a fourth use, rarer and worth naming because it looks like
over-engineering until you hit it: **an event breaks a container cycle the
call graph cannot.** `PluginContributionsActivated` / `…Retracted` exist for
that — the plugin lifecycle has to drive the view registrar, but taking it as a
constructor argument closes the loop `PluginLifecycle → ViewRegistrar →
ViewHost → PaneLayout →` overlay stack `→ WelcomeSteps → PluginEnablement`.
The dispatcher resolves listeners lazily, so the edge disappears while the call
stays synchronous and ordered. Reach for this only when the cycle is real:
these two are facts with a single listener each, which would otherwise read as
a command wearing the wrong suffix.

## What the gates check

Two tests, both of which exist because the failure mode here is *silence*
rather than an error:

- `EventListenerWiringTest::testEveryEventCoreDispatchesHasSomewhereToLand` —
  every event class has at least one compiled listener, except the eight
  observed only at runtime — by plugins, and by the bundled git plugin for the
  three git facts it now both raises and answers. A forgotten `#[AsEventListener]` breaks
  no build and throws nothing; the reaction simply never happens. This is what
  turns that into a failure. It has already caught one.
- `EventListenerWiringTest::testACommandEventHasExactlyOnePerformer` — the
  one-listener rule for `…Requested`.

Plus, for the plugin-facing subset,
`PluginObservableContractTest` pins which events are public API, requires
their payloads to be scalars, and requires each to be named in
[plugins.md](plugins.md).

## What the gates do NOT check

Neither gate proves a listener *does* anything. They prove one exists and that
a command has exactly one — a listener whose body you delete still satisfies
both.

That is not hypothetical. In August 2026 every listener was mutation-tested by
replacing its body with `return;` and running the suite; **five of forty**
survived, each an untested seam where both halves had tests and the join
between them did not. The recurring shapes, worth recognising in review:

- **A `?Foo $x = null` dependency the test never passes.** `$this->x?->act()`
  short-circuits, so the line is *covered* on every run while the call never
  happens. Check the compiled container's resolved arguments before concluding
  it is a live bug — in that case production wired it and only the test did not.
- **A test asserting a negative.** "Stale results get dropped" stays green when
  the loader never runs at all.
- **A test that re-asserts the same value.** Navigating a list and checking the
  same patch is still shown passes whether or not the reload happened.
- **A subsystem tested only by booting in the target state.** The RTL suite
  starts mirrored, so the listener handling a *live* locale switch was reached
  by nothing.

So: when you add a listener, delete its body and confirm a test goes red.
Mutation-check the fix too — a replacement test can be satisfied by a different
code path than the one you meant (one here was, because switching to the
History tab auto-loads the newest commit by another route). For a multi-line
listener, mutate each line alone.

A survivor is not automatically a defect. `FindQueryUpdated`'s listener is one
`requestRender()`, and three other mechanisms already schedule that frame, so
no mutation of it is distinguishable. That is redundancy, not a gap — say so in
the commit rather than inventing a test that cannot fail.
