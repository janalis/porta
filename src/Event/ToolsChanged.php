<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The set of tools plugins registered with
 * {@see \Porta\Plugin\Api\PluginApi::registerTool()} changed while the IDE was
 * running: a plugin that contributes one was enabled or disabled live.
 *
 * A fact, for whatever serves those tools to a client — the bundled mcp
 * plugin tells its connected agents to list them again. It never fires at
 * boot: every plugin has committed before anything could have asked.
 *
 * Payload-free on purpose. Plugins are not told about each other's lifecycles
 * ({@see PluginContributionsActivated} says why), and a listener owes this one
 * nothing but a re-read of `tools()`, which names every plugin anyway.
 */
final readonly class ToolsChanged implements PluginObservable {}
