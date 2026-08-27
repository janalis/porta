<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A plugin was deactivated live (disabled or removed) and everything it
 * contributed must leave the running IDE.
 *
 * The retraction half of {@see PluginContributionsActivated}, and an event for
 * the same reason: the view surface cannot be a constructor dependency of the
 * lifecycle without a container cycle through the overlay stack. Deliberately
 * NOT {@see PluginObservable}.
 */
final readonly class PluginContributionsRetracted
{
    public function __construct(
        public string $plugin,
    ) {}
}
