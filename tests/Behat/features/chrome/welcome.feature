Feature: The Welcome screen
  The first time Porta opens a project it shows the Welcome screen: a short
  checklist that configures the project one step at a time. Esc hands the
  main area back to the editor, a second launch goes straight to the editor,
  and Help → Welcome brings the checklist back.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      return 1;
      """

  Scenario: A fresh project opens on the Welcome screen
    Given it is the first run in this project
    When I start the app with the arguments "a.php"
    Then I should eventually see "Welcome to Porta"
    And I should see "Syntax highlighting"
    And I should see "PHP language server"
    And I should see "Code style fixer"
    And I should see "Static analysis"
    And I should see "Read the guide"

  Scenario: Working a step configures the project
    Given the bundled plugins are available
    And it is the first run in this project
    When I start the app with the arguments "a.php"
    And I should eventually see "1 of 4 done"
    And I should see "✓   Syntax highlighting"
    And I should see "○   PHP language server"
    And I press "Enter"
    Then I should see "2 of 4 done"
    And I should see "✓   PHP language server"
    And the file ".porta.json" should contain "phpantom"

  Scenario: Escape returns to the editor, and the next launch does not show it
    Given it is the first run in this project
    When I start the app with the arguments "a.php"
    And I should eventually see "Welcome to Porta"
    And I press "Escape"
    Then I should not see "Welcome to Porta"
    And I should see "return 1;"
    When I restart the app
    Then I should see "return 1;"
    And I should not see "Welcome to Porta"

  @command:ide_welcome
  Scenario: Help → Welcome brings the checklist back
    When I start the app with the arguments "a.php"
    Then I should see "return 1;"
    And I should not see "Welcome to Porta"
    When I press "F10"
    And I press "h"
    And I click on the text "Welcome"
    Then I should eventually see "Welcome to Porta"
