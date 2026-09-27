<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Where a diagnostic's MESSAGE is shown (the "Interface > Diagnostic
 * messages" setting): nowhere, in the padding after the line's code, or at
 * the top of the Alt+Enter quick-fix list, PhpStorm-style. The squiggle, the
 * gutter mark, the stripe cell and the Problems panel are not the message and
 * show in every mode. Supersedes the old `inlineDiagnostics` boolean.
 */
enum DiagnosticMessageMode: string
{
    case None = 'none';
    case Inline = 'inline';
    case Menu = 'menu';
}
