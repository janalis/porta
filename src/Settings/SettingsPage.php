<?php

declare(strict_types=1);

namespace Porta\Settings;

/**
 * One reachable settings page: the nav id that selects it, and the name it goes
 * by on screen.
 *
 * The label is DISPLAY-READY, translated or not according to what it names.
 * Section names are translated; a plugin's or a language's is not, because
 * those are identifiers the user installed or the catalog fixed. Deciding that
 * per section keeps the rule where the knowledge is, instead of tagging each
 * page with a "translate me" flag for one consumer to interpret.
 */
final readonly class SettingsPage
{
    public function __construct(
        /** The nav id: 'general', 'plugin:git', 'lang:php'. */
        public string $id,
        public string $label,
    ) {}
}
