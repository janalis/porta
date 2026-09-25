<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

/**
 * Where a plugin's closure threw, for {@see PluginManager}'s report-once
 * barrier: these closures run per render or per file open, so a broken plugin
 * is reported once per channel, not once per frame.
 *
 * An enum rather than string constants so a channel cannot be misspelled into
 * a silent second bucket. Backed, because the barrier keys an array by it.
 */
enum FailureChannel: string
{
    /** A highlighter's claim or its spans. */
    case Highlighter = 'highlighter';

    /** A status-bar segment's provider. */
    case Segment = 'segment';

    /** A viewer's claim on a file. */
    case Viewer = 'viewer';

    /** A menu entry's `when` predicate. */
    case When = 'when';
}
