<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * Who called a tool: a handler registered with
 * {@see PluginApi::registerTool()} receives it as its second argument.
 *
 * `$id` tells two callers apart for as long as the IDE runs — two agents may
 * share a name. `$name` is what the caller calls itself (an MCP client's
 * `clientInfo`), for showing the user who is at work.
 */
final readonly class ToolCaller
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
