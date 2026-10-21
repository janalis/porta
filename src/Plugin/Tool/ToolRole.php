<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * What a missing tool costs its plugin.
 *
 * Required: the plugin cannot do its job without it — a formatter with no
 * formatter, a language server with no server — so its absence is said where
 * the plugin is switched on. Suggested: one feature goes quiet and the rest
 * works, the way graph-viewer still renders Mermaid natively with no `mmdc`,
 * so its absence is said only on the plugin's own page.
 */
enum ToolRole: string
{
    case Required = 'required';
    case Suggested = 'suggested';
}
