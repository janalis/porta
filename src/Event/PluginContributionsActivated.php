<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A plugin was activated live (enabled, installed, or reloaded as a shadow
 * fallback) and its commitments are in the manager, ready to be built.
 *
 * A fact, so any number of listeners may care. It exists because the surfaces
 * that must react sit BEHIND the layout: the view registrar drags in ViewHost
 * and PaneLayout, whose overlay stack reaches WelcomeSteps and so back into
 * the plugin lifecycle — a constructor edge there is a container cycle. The
 * dispatcher resolves listeners lazily, which is exactly the decoupling the
 * cycle needs. Deliberately NOT {@see PluginObservable}: plugins do not get
 * told about each other's lifecycles.
 */
final readonly class PluginContributionsActivated
{
    public function __construct(
        public string $plugin,
    ) {}
}
