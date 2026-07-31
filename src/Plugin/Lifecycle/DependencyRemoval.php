<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

/**
 * What {@see PluginInstaller::removeDependencies()} did.
 *
 * `NotOurs` is the case the whole enum exists for: it is not a failure and
 * must never read like one. A plugin that ships or commits its own `vendor/`
 * keeps it, and the user is told nothing — deleting it would be destroying
 * tracked files on a keypress.
 */
enum DependencyRemoval
{
    /** Nothing there, or a vendor tree Porta did not create. Silence is correct. */
    case NotOurs;

    /** The tree Porta installed is gone. */
    case Removed;

    /** Composer is running — deleting under it would leave a half-tree that looks complete. */
    case Busy;

    /** It is ours and it would not delete. */
    case Failed;
}
