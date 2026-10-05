<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Which edge of the workspace an {@see EdgeRuleWidget} runs along: the top,
 * under the menu bar, or the bottom, over the status bar — the bar whose
 * half it holds.
 */
enum WorkspaceEdge
{
    case Top;
    case Bottom;
}
