<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

/**
 * What {@see PluginInstaller::installDependencies()} did, answered
 * synchronously — the caller needs it before the subprocess can possibly
 * finish, to know whether to wait or to move on.
 *
 * Only `Started` is followed by the exit callback. The other three are the end
 * of the story, and each is a different sentence to the user, which is why this
 * is an enum and not a bool: "nothing to do" and "I could not" must not read
 * the same.
 */
enum DependencyInstall
{
    /** The manifest declares nothing an install would produce, or it is already installed. */
    case NotNeeded;

    /** Composer is running; the exit callback will fire exactly once. */
    case Started;

    /** The single process slot is taken — a clone, or another plugin's install. */
    case Busy;

    /** No composer binary on this machine. */
    case NoComposer;
}
