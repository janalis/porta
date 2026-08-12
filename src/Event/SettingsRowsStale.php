<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The settings page's rows no longer reflect reality — a plugin was installed,
 * enabled, disabled or removed, or a binary it offers to install appeared.
 *
 * A fact, so any number of listeners may care. It exists because the settings
 * VIEW is the thing that has to repopulate, and the plugin lifecycle should not
 * have to hold that controller to say so: the view registry hands back a
 * {@see \Porta\Workbench\View\ContributedView}, deliberately without a view's
 * own verbs on it, so a capability only one view has travels as an event
 * instead of as a concrete type on the registry.
 */
final readonly class SettingsRowsStale {}
