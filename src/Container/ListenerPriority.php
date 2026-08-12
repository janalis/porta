<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * The rungs event listeners run on, and what each one means.
 *
 * Four numbers, three of which were bare literals in attributes with the
 * ordering contract explained only in prose across four separate docblocks —
 * so the ladder had to be reconstructed to be read. It is one place now.
 *
 * It lives here rather than in `Porta\Event` for two reasons: both event gate
 * tests glob `src/Event/*.php` and would demand a listener for a class that is
 * not an event, and this namespace already holds the wiring vocabulary
 * ({@see AsEager}, {@see AsWidget}, {@see AsIdeService}, {@see LayoutSlot}) —
 * including the OTHER priority scale, which is the point of the warning below.
 *
 * **Not to be confused with `#[AsEager]`/`#[AsTaggedItem]` priorities.** Those
 * run 100–700 and order CONSTRUCTION or REGISTRATION — which eager service is
 * built first, which command provider registers first. These order REACTION to
 * a dispatched event, run 0 and below, and are compiled into the dispatcher's
 * listener map. Overlapping magnitudes, unrelated meanings.
 */
enum ListenerPriority
{
    /**
     * The default: react to the event, then let everything below react to the
     * state you left. Every core listener sits here unless it has a reason not
     * to; the exceptions are exactly the attribute sites on the rungs below.
     */
    public const int CORE = 0;

    /**
     * Third-party listeners, registered at runtime by `PluginApi::on()`.
     *
     * Below every {@see CORE} listener so a plugin observes the state core has
     * already reacted to, rather than racing it. It is NOT "last" — see
     * {@see CACHE_DROP}, which is deliberately later.
     */
    public const int PLUGIN = -100;

    /**
     * The chrome sweep (`Workbench/ChromeRefreshListener`), and the buffer-switch
     * tracker that derives the deduped stream.
     *
     * Shares {@see PLUGIN}'s rung, and that is deliberate rather than a
     * collision to resolve: neither depends on the other, so their relative
     * order is registration order and nothing reads it. Both only have to be
     * after the priority-0 reactions they clear caches for.
     */
    public const int CHROME_SWEEP = -100;

    /**
     * Dropping caches that a lower rung's listeners feed
     * (`Highlight/HighlightThemeListener`).
     *
     * After {@see PLUGIN} on purpose: a highlighting backend that bakes the
     * theme's escape codes rebuilds itself first, and only then are the caches
     * holding its old output dropped. Reversed, the next paint uses the stale
     * engine.
     */
    public const int CACHE_DROP = -200;
}
