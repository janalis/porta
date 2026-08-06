<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A contributor of the status bar's LEFT prefix — the ambient-state slot the
 * debugger's `⏸ path:line` occupies, before the git branch and the file name.
 *
 * Pulled once per render, so an implementation must be cheap and must not
 * block. Returning null means "nothing to say right now", which is the normal
 * state for most contributors most of the time.
 *
 * Order is contractual and set by `#[AsTaggedItem(priority:)]`: core's own
 * segments come first, plugins' last, so a plugin can never push the debugger's
 * state off the left edge of a narrow terminal.
 */
#[AutoconfigureTag('porta.status_segment')]
interface StatusSegment
{
    /** This contributor's cell, or null when it has nothing to show. */
    public function statusCell(): ?StatusCell;
}
