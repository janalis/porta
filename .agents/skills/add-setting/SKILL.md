---
name: add-setting
description: Add a `.porta.json` setting to Porta core — IdeConfig's default and closed choices, the Settings row that edits and applies it live, docs/configuration.md, the settings-pages golden and the seven catalogs, with the test that fails for each one forgotten. Use when adding or changing a core setting key (not a plugin's own config).
---

Worked example: `git show 35efeb87` (`inlayHints`: a choice setting applied live, plus a
cycle command). `IdeConfig` changed in 18 commits since August, and each one touched the
same places. `6732ebf2` touched 55 files, most of them the feature itself.

1. **`src/Config/IdeConfig.php` `DEFAULT_SETTINGS`**: `'myKey' => <default>`. The default's type is
   the setting's type: bool, int or string.
   - **A closed choice** also goes in `CHOICE_SETTINGS` as a literal list. Mirror the enum that owns
     it (e.g. `src/Editor/InlayHintMode.php`); the config parser cannot reach the enum's namespace.
     - Guard: pin the list to `Enum::cases()` in `tests/Unit/Config/IdeConfigTest.php`, as the
       existing choices do. Nothing fails if you skip the pin, so add it.
   - **A renamed key** goes in `SUPERSEDED_BY` / `LEGACY_SETTINGS`, not a silent drop. See
     `testRelativeLineNumbersMigratesToTheLineNumbersMode`.
2. **`src/Settings/SettingsController.php`**: a row in the settings table.
   - The row takes `label`, `kind` (`SettingsRowKind`), `group` (`interface`/`general`), `fieldset`,
     `restart`, `apply`, and `options` for a choice.
   - `restart: false` needs a live `apply`. Put the live change in `src/Settings/LiveSettingsApplier.php`
     (`applyInlayHints`) rather than inline, when it needs a service the controller does not hold.
   - A new applier dependency: **fix the direct construction sites in tests**. `SettingsViewControllerTest`
     and `ThemeRefreshSweepTest` build it by hand; `TestCompletionController::idle()` shows the shape.
   - Test the row in `tests/Unit/Settings/SettingsControllerTest.php`: the value applies, and an
     invalid value falls back (`['inlayHints' => 'sideways'] + IdeConfig::DEFAULT_SETTINGS`).
3. **`docs/configuration.md`**: a `| \`myKey\` | \`default\` | what it does, every choice named |` row,
   plus the example JSON if the key is common.
   - Guards: `ConfigurationDocTest`. Every key needs a row, and a row naming a removed key fails
     unless it is in `MAY_LINGER` with its reason.
4. **The settings-pages golden**: the page's header counts its settings ("Interface — 15 settings").
   Rerun with `UPDATE_SETTINGS_PAGES_GOLDEN=1 vendor/bin/phpunit tests/E2e/SettingsPagesGoldenTest.php`,
   then read the golden's diff. It should show only your row and the count.
   - A new fieldset or group can also move `SettingsRenderGoldenTest` (`UPDATE_SETTINGS_GOLDEN=1`).
   - E2e tests that walk the settings form by row index shift when a row is inserted
     (`SettingsFormBehaviourTest`, `IdeSettingsThemeTest`, `SettingsSearchTest`).
5. **Translations**: the row's `label` and every user-visible option label are message ids.
   Add each with `php bin/porta-dev i18n:add "Inlay hints" --target="fr:…" …` (all six targets).
   - Guard: `CatalogCoverageTest` scans `'label' => '…'`. `CatalogCompletenessTest` checks every locale.
6. **A menu or palette verb that cycles or toggles it** (`cycle_inlay_hints`): that part is the
   `add-command` skill. The handler is `$this->settings->cycleChoice('myKey')` / `toggle('myKey')`
   in `src/Command/ViewCommands.php`. `IdeHelpersTest` asserts the cycle changes the value.
7. **The feature's own docs page** (`docs/editor.md` for editor display settings) says what the
   setting changes, in the user's words. Update README.md only if it already lists the neighbours.

Then run the `gates` skill.
