Feature: The Keybindings page
  Settings › Keybindings lists every action with its binding. `/` filters it,
  Enter arms a capture and the next key becomes the binding — written to
  .porta.json and live at once — and Del puts the shipped default back,
  removing its line from the file.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      one
      """

  Scenario: The list filters down to what is typed
    Given I start the app with the arguments "a.php"
    When I open the Settings page "Keybindings"
    And I focus "#settings-keys"
    And I press "/"
    And I type "duplicate"
    Then I should see "Duplicate Line"
    And I should not see "Move Caret Up"
    And I should see text matching "/Settings › Keybindings +\d+ of \d+/"

  Scenario: A captured key becomes the binding at once, and Del puts the default back
    Given I start the app with the arguments "a.php"
    When I open the Settings page "Keybindings"
    And I focus "#settings-keys"
    And I press "/"
    And I type "duplicate line"
    And I press "Enter"
    And I press "F4"
    Then the project configuration should list "f4" in "keymap.duplicate_line"
    When I press "Escape"
    And I focus "#editor"
    And I press "Down"
    And I press "F4"
    Then the editor text should be:
      """
      <?php
      one
      one
      """
    When I open the Settings page "Keybindings"
    And I focus "#settings-keys"
    And I press "/"
    And I type "duplicate line"
    And I press "Delete"
    Then the project configuration should not set "keymap"
    When I press "Escape"
    And I focus "#editor"
    And I press "Ctrl+D"
    Then the editor text should be:
      """
      <?php
      one
      one
      one
      """
