# Events

Porta's subsystems talk to each other over one `symfony/event-dispatcher`
bus. Roughly fifty event classes live in `src/Event/`, every one of them
`final readonly`, and listeners are compiled into the dispatcher's map from
attributes — there is no runtime registration in core.

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
  `BufferSwitched` has four.

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

## What the gates check

Two tests, both of which exist because the failure mode here is *silence*
rather than an error:

- `EventListenerWiringTest::testEveryEventCoreDispatchesHasSomewhereToLand` —
  every event class has at least one compiled listener, except the three
  observed only by plugins at runtime. A forgotten `#[AsEventListener]` breaks
  no build and throws nothing; the reaction simply never happens. This is what
  turns that into a failure. It has already caught one.
- `EventListenerWiringTest::testACommandEventHasExactlyOnePerformer` — the
  one-listener rule for `…Requested`.

Plus, for the plugin-facing subset,
`PluginObservableContractTest` pins which events are public API, requires
their payloads to be scalars, and requires each to be named in
[plugins.md](plugins.md).
