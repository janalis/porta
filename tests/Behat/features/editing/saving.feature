Feature: Saving files
  Ctrl+S saves the shown file, Ctrl+Alt+S every file with unsaved changes. A
  tab with unsaved changes carries a dot until it is saved. With the
  formatOnSave setting on, the language server formats the file before each
  save.

  Background:
    Given a PHP project
    And a file "A.php" containing "<?php // a"
    And a file "B.php" containing "<?php // b"

  Scenario: Save the shown file
    Given I start the app with the arguments "A.php"
    And the caret is at line 1, column 11
    When I type " edited"
    Then I should see "A.php ●"
    When I use the action "save"
    Then the file "A.php" should contain "<?php // a edited"
    And I should not see "A.php ●"

  Scenario: Save every file with unsaved changes
    Given I start the app with the arguments "A.php"
    And the caret is at line 1, column 11
    And I type " one"
    And I open "B.php"
    And the caret is at line 1, column 11
    And I type " two"
    When I use the action "save_all"
    Then the file "A.php" should contain "<?php // a one"
    And the file "B.php" should contain "<?php // b two"

  @setting:formatOnSave
  Scenario: The language server formats the file before it is saved
    Given the mock language server is configured
    And the project configuration:
      """
      {"settings": {"formatOnSave": true}}
      """
    And I start the app with the arguments "A.php"
    And I should eventually see "LSP:mock-lsp-server"
    And I type "x"
    When I use the action "save"
    Then the file "A.php" should contain "formatted();"
    And the editor should contain "formatted();"

  Scenario: Toggle Format on Save from Find Action
    Given the mock language server is configured
    And I start the app with the arguments "A.php"
    And I should eventually see "LSP:mock-lsp-server"
    When I use the action "toggle_format_on_save"
    Then the project setting "formatOnSave" should be true
    When I type "x"
    And I use the action "save"
    Then the file "A.php" should contain "formatted();"
