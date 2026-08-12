<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\Settings\SettingsNavWidget;

/**
 * Select a Settings section by id instead of counting arrow keys.
 *
 * The nav is index-addressed, so every test that walked it with a fixed number
 * of `\x1b[B` presses broke whenever a section was inserted — inserting
 * "Highlighting" alone moved ~20 call sites, and the plugin-listing suites had
 * already been shifted twice before that.
 *
 * This walks with real input rather than calling `setActive()`, so the widget's
 * select callback fires and the controller actually swaps the content pane —
 * `setActive()` only moves the highlight. Headers are skipped by the widget's
 * own navigation, so they never need counting either.
 */
trait NavigatesSettings
{
    /** Satisfied by the host TestCase (PHPUnit's Assert::fail). */
    abstract public static function fail(string $message = ''): never;

    private function selectSettingsSection(SettingsNavWidget $nav, string $id): void
    {
        // Rewind to the top; the caller may have left the selection anywhere.
        for ($i = 0; $i < self::MAX_NAV_ROWS; ++$i) {
            $nav->handleInput("\x1b[A");
        }

        for ($i = 0; $i < self::MAX_NAV_ROWS; ++$i) {
            if ($id === $nav->selectedId()) {
                return;
            }
            $nav->handleInput("\x1b[B");
        }

        self::fail(\sprintf('Settings section "%s" is not reachable in the nav', $id));
    }

    /** Generous bound: sections + plugin sub-items + every configurable language. */
    private const int MAX_NAV_ROWS = 64;
}
