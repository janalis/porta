Feature: Moving around the code
  Alt+Down and Alt+Up step between declarations. A jump to a definition can be
  undone with Navigate Back, and Recent Locations lists the places jumped away
  from. Last Edit Location takes you back to what you were writing, across
  files.

  Background:
    Given a PHP project
    And a file "Svc.php" containing:
      """
      <?php

      class Svc
      {
          public function one(): void
          {
          }

          public function two(): void
          {
          }
      }
      """

  Scenario: Step between declarations
    Given I start the app with the arguments "Svc.php"
    And the caret is at line 4, column 1
    When I use the action "next_member"
    Then the caret should be on line 5
    When I use the action "next_member"
    Then the caret should be on line 9
    When I use the action "prev_member"
    Then the caret should be on line 5

  Scenario: Navigate back from a jump to a definition
    Given a file "Target.php" containing:
      """
      <?php

      // target
      class Target {}
      """
    And the mock language server is configured
    And I start the app with the arguments "Svc.php"
    And I should eventually see "LSP:mock-lsp-server"
    And the caret is at line 5, column 21
    When I press "Ctrl+B"
    Then the editor should show "Target.php"
    When I use the action "navigate_back"
    Then the editor should show "Svc.php"
    And the caret should be at line 5, column 21

  Scenario: Recent locations lists the place a jump left
    Given a file "Target.php" containing:
      """
      <?php

      // target
      class Target {}
      """
    And the mock language server is configured
    And I start the app with the arguments "Svc.php"
    And I should eventually see "LSP:mock-lsp-server"
    And the caret is at line 5, column 21
    And I press "Ctrl+B"
    And the editor should show "Target.php"
    When I use the action "ide_recent_locations"
    Then I should see "Svc.php:5  public function one(): void"
    When I press "Enter"
    Then the editor should show "Svc.php"
    And the caret should be on line 5

  Scenario: Last edit location returns to the file and line last edited
    Given a file "B.php" containing "<?php // b"
    And I start the app with the arguments "Svc.php"
    And the caret is at line 10, column 1
    And I type "        // edited"
    And I open "B.php"
    When I use the action "ide_last_edit"
    Then the editor should show "Svc.php"
    And the caret should be at line 10, column 1
