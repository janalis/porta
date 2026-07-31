<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Marks an event a PLUGIN may listen to — the frozen, documented subset of this
 * namespace. Everything else here is internal and moves without notice.
 *
 * A marker rather than a namespace rule or a central allowlist, because the
 * fact belongs on the class: the file you are editing when you are about to
 * break somebody is the file that says it is public.
 *
 * Three rules, all enforced by `tests/Unit/Event/PluginObservableContractTest`:
 *
 * - **Scalar payloads only** (or a backed enum). That is what keeps
 *   `Porta\Editor\Buffer` out — a mutable public-property bag whose exposure
 *   would hand third parties write access to editor state and a route around
 *   {@see \Porta\Editor\DocumentReplacer}'s undo/LSP contract. `final readonly`
 *   on an event says nothing about what it points at.
 * - **Documented**: the class name appears in `docs/plugins.md`.
 * - **Pinned**: the marked set is a literal list in that test, so adding one is
 *   a deliberate diff someone reviews.
 *
 * A fourth rule cannot be tested and lives here instead: only events that fire
 * at HUMAN frequency may be marked. A per-keystroke event would put a plugin
 * closure and a try/catch on the render path.
 */
interface PluginObservable {}
