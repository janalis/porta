<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

/**
 * Whether a list tool's work is still in flight when it publishes — the tab
 * shows "searching…" while it is.
 *
 * An enum rather than a `bool $running`, the {@see \Porta\Plugin\Api\BinarySearch}
 * precedent: mago rejects boolean flag parameters, and at a call site
 * `ToolRun::Running` says what `true` does not.
 */
enum ToolRun
{
    /** More rows may still arrive; the tab shows the searching marker. */
    case Running;
    /** These are all the rows there are. */
    case Finished;
}
