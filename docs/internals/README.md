# Internals

How Porta is built, for people working on Porta itself. If you are using the
IDE, or writing a plugin against its API, start at
[the documentation index](../README.md) instead.

| Guide | Contents |
| --- | --- |
| [Working on Porta](contributing.md) | Commands, the eight gates, house conventions |
| [Where code lives](code-map.md) | Namespace membership, how to move a class |
| [The container](container.md) | DI, the composition root, the porta attributes |
| [Subsystems](subsystems.md) | Rendering, input, editor, dock, overlays, git, LSP |
| [The plugin system](plugin-system.md) | Discovery, load order, autoloading, the drain |
| [Testing](testing.md) | Suites, rigs, the flake doctrine, mutation |
| [Settled questions](decisions.md) | Measured rejections, and what would re-open them |

Two of these are worth reading before you propose anything large.
[Settled questions](decisions.md) lists what has already been measured and
turned down — re-proposing one of those costs a round. [Testing](testing.md)
carries the rule that catches most bad tests here: coverage says a line ran, not
that anything would notice its absence.
