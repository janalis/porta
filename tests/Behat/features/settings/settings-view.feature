Feature: The Settings view
  Ctrl+, opens Settings over the editor: a sidebar of pages — General,
  Interface, Highlighting, Keybindings, Command line, then a page per plugin
  and per language — each headed by its trail. Ctrl+F finds a setting on any
  page and lands on its row. A change applies at once, and r puts a row back
  to its default.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"

  Scenario: The sidebar opens each page, headed by its trail
    Given I start the app with the arguments "a.php"
    When I open the Settings page "Interface"
    Then I should see "Settings › Interface"
    And I should see "Theme"
    When I open the Settings page "Command line"
    Then I should see "Settings › Command line"
    When I click on the text "Settings ›"
    Then I should see "Settings › General"

  Scenario: Ctrl+F finds a setting on another page, and lands on its row
    Given I start the app with the arguments "a.php"
    When I open the Settings page "General"
    And I press "Ctrl+F"
    And I type "sticky"
    Then I should see "Sticky scroll"
    When I press "Enter"
    Then I should see "Settings › Interface"
    And the selected setting should be "Sticky scroll"

  Scenario: A change applies at once, and r puts it back to its default
    Given I start the app with the arguments "a.php"
    When I open the Settings page "General"
    And I select the setting "Auto-pairs"
    And I press "Space"
    Then the project configuration should have "settings.autoPairs" set to "false"
    When I press "r"
    Then the project configuration should not set "settings.autoPairs"
    And the setting "Auto-pairs" should be "on"
    When I press "Space"
    And I press "Escape"
    And I focus "#editor"
    And I press "End"
    And I type " f("
    Then the editor text should be:
      """
      <?php f(
      """
