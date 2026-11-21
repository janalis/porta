Feature: A custom keymap in .porta.json
  The `keymap` block maps an action id to a key or a list of keys, replacing
  that action's whole list. Plugin commands bind by their namespaced id. An id
  nothing answers to is reported at startup. The Keymap setting
  (`keymapPlatform`) picks whose conventions the default keymap follows: on
  macOS the main shortcuts also answer to Cmd, which a terminal sends as
  Super.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      one
      """

  Scenario: An action bound in the keymap answers to its new key, and no longer to the old one
    Given the project configuration:
      """
      {"keymap": {"duplicate_line": ["f4"]}}
      """
    And I start the app with the arguments "a.php"
    When I press "Down"
    And I press "F4"
    Then the editor text should be:
      """
      <?php
      one
      one
      """
    When I press "Ctrl+D"
    Then the editor text should be:
      """
      <?php
      one
      one
      """

  Scenario: An unknown action id is reported at startup
    Given the project configuration:
      """
      {"keymap": {"no_such_action": ["f4"]}}
      """
    When I start the app with the arguments "a.php"
    Then I should see "Unknown keymap action"
    And I should see "no_such_action"

  Scenario: A plugin command binds by its namespaced id
    Given the bundled plugin "phpstan" is enabled
    And an executable "vendor/bin/phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'Analysed by the new key', 'line' => 2]]]], 'errors' => []]);
      exit(1);
      """
    And the project configuration:
      """
      {"keymap": {"phpstan:analyse": ["ctrl+alt+j"]}, "settings": {"diagnosticMessages": "inline"}}
      """
    And I start the app with the arguments "a.php"
    When I press "Ctrl+Alt+J"
    Then I should see "Analysed by the new key"

  @setting:keymapPlatform
  Scenario: With the macOS keymap, Cmd+S saves
    Given the project configuration:
      """
      {"settings": {"keymapPlatform": "macos"}}
      """
    And I start the app with the arguments "a.php"
    When I type "// edited "
    And I press "Super+S"
    Then the file "a.php" should contain "// edited"

  @setting:keymapPlatform
  Scenario: The Linux keymap leaves Cmd alone, until the Keymap setting switches to macOS
    Given the project configuration:
      """
      {"settings": {"keymapPlatform": "linux"}}
      """
    And I start the app with the arguments "a.php"
    When I type "// edited "
    And I press "Super+S"
    Then the file "a.php" should not contain "// edited"
    When I open the Settings page "General"
    And I choose "macos" for the setting "Keymap"
    And I press "Escape"
    And I press "Super+S"
    Then the file "a.php" should contain "// edited"
    And the project configuration should have "settings.keymapPlatform" set to "macos"
