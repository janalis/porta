<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\AgentActivityKind;

/**
 * An agent — a program the user connected, such as one speaking MCP to the
 * bundled mcp plugin — is at work on a file: reading it, or changing it, at a
 * position. Core SHOWS it: a caret in the agent's colour with its name, a mark
 * on the file's tab and tree row, for `$fadeAfter` seconds of quiet, or until
 * {@see AgentLeft}.
 *
 * A FACT, emitted by whatever speaks to the agent; any number of listeners.
 * `$agent` tells two agents apart — two may share a `$name`. The position is
 * 0-based line and byte column, as {@see \Porta\Plugin\Api\OpenBuffers} counts
 * them; one past the end is clamped. A NEGATIVE line shows the agent on the
 * file — its tab, its tree row — with no caret drawn in the text.
 *
 * With an anchor (a non-negative `$anchorLine`), the text from it to the
 * caret is the agent's selection, tinted in its colour — a collaborator
 * selecting, say.
 */
final readonly class AgentActivity implements PluginObservable
{
    public function __construct(
        public string $agent,
        public string $name,
        public string $path,
        public AgentActivityKind $kind,
        public int $line,
        public int $column,
        public float $fadeAfter,
        public int $anchorLine = -1,
        public int $anchorColumn = 0,
    ) {}
}
