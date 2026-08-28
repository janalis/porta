<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Open the "Find Setting" picker.
 *
 * A command event: exactly one listener, {@see
 * \Porta\Settings\SettingsSearchLauncher}. It carries nothing because there is
 * nothing to carry — the picker opens on an empty query.
 *
 * An event rather than a callback because the form already reaches its
 * controller this way for {@see SettingsSectionRequested}, and because the
 * alternative is a constructor edge from a widget to the search stack for the
 * sake of one keypress.
 */
final readonly class SettingsSearchRequested {}
