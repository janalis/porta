Feature: Where a setting is saved, and where a shown value came from
  Porta reads two files, the project's .porta.json over the global
  ~/.porta/config.json. Each Settings page leads with Save changes to, a
  session choice of the file it writes. A row says when its value comes from
  the global file, or when the project overrides a different global value. A
  write the other file overrides is made, and said to change nothing here.

  Background:
    Given a PHP project
    And a Porta home of my own
    And a file "a.php" containing "<?php"

  Scenario: Saving to the global file leaves the project's alone
    Given I start the app with the arguments "a.php"
    When I open the Settings page "Interface"
    And I choose "~/.porta/config.json" for the setting "Save changes to"
    And I select the setting "Sticky scroll"
    And I press "Space"
    Then my global configuration should have "settings.stickyScroll" set to "false"
    And the file ".porta.json" should not exist

  Scenario: Saving to the project file leaves the global one alone
    Given I start the app with the arguments "a.php"
    When I open the Settings page "Interface"
    And I choose ".porta.json" for the setting "Save changes to"
    And I select the setting "Sticky scroll"
    And I press "Space"
    Then the project configuration should have "settings.stickyScroll" set to "false"
    And my global configuration should not set "settings"

  Scenario: A value from the global file is tagged, and one the project overrides too
    Given my global configuration:
      """
      {"settings": {"stickyScroll": false, "indentGuides": false}}
      """
    And the project configuration:
      """
      {"settings": {"indentGuides": true}}
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "Interface"
    And I select the setting "Sticky scroll"
    Then I should see text matching "/Sticky scroll.*from global/"
    When I select the setting "Indent guides"
    Then I should see text matching "/Indent guides.*overrides global/"

  Scenario: A global write the project overrides is made, and said to change nothing here
    Given the project configuration:
      """
      {"settings": {"stickyScroll": true}}
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "Interface"
    And I choose "~/.porta/config.json" for the setting "Save changes to"
    And I select the setting "Sticky scroll"
    And I press "Space"
    Then my global configuration should have "settings.stickyScroll" set to "false"
    And a notification should say ".porta.json overrides it in this project"
    And I should see text matching "/Sticky scroll.*overrides global/"
