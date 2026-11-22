Feature: Problems
  A language server's diagnostics are underlined in the code and counted in
  the status bar. The keyboard walks them, Error Description reads one out,
  the Problems panel lists them all, and the message can trail the code or
  head the Alt+Enter list.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Problem.php" containing:
      """
      <?php
      echo 1;

      echo 2;
      """

  Scenario: The status bar says a problem is there
    When I start the app with the arguments "src/Problem.php"
    Then I should see "✗ 1"

  Scenario: Next problem goes to the file's problem, wrapping at the end
    Given I start the app with the arguments "src/Problem.php"
    And the language server is ready
    And I should see "✗ 1"
    When I put the caret at line 4, column 3
    And I use the action "next_problem"
    Then the caret should be on line 1
    And a toast should say "Wrapped to"
    And a toast should say "Mock diagnostic"

  Scenario: Previous problem goes back to the problem above
    Given I start the app with the arguments "src/Problem.php"
    And the language server is ready
    And I should see "✗ 1"
    When I put the caret at line 4, column 3
    And I use the action "prev_problem"
    Then the caret should be on line 1
    And a toast should say "Mock diagnostic"

  Scenario: Error description reads out the problem at the caret
    Given I start the app with the arguments "src/Problem.php"
    And the language server is ready
    And I should see "✗ 1"
    When I put the caret at line 1, column 1
    And I use the action "show_error_description"
    Then I should see "│ ✗ Mock diagnostic"

  Scenario: Error description away from any problem says so
    Given I start the app with the arguments "src/Problem.php"
    And the language server is ready
    And I should see "✗ 1"
    When I put the caret at line 2, column 3
    And I use the action "show_error_description"
    Then a toast should say "No problem at the caret"

  Scenario: The Problems panel lists the problems of every open file
    Given a file "src/Other.php" containing:
      """
      <?php
      $y = 2;
      """
    And I start the app with the arguments "src/Problem.php"
    And the language server is ready
    When I use the action "goto_file"
    And I type "Other"
    And I should see "Other.php" in the dialog "Go to File"
    And I press "Enter"
    And the editor should show "src/Other.php"
    And I use the action "ide_problems"
    Then I should see "src/Problem.php"
    And I should see "src/Other.php"
    And I should see "Mock diagnostic" 2 times

  @setting:diagnosticMessages
  Scenario: By default the message does not trail the code
    When I start the app with the arguments "src/Problem.php"
    And I should see "✗ 1"
    Then the line showing "<?php" should not contain "Mock diagnostic"

  @setting:diagnosticMessages
  Scenario: Inline diagnostic messages trail the code
    Given the project configuration:
      """
      {"settings": {"diagnosticMessages": "inline"}}
      """
    When I start the app with the arguments "src/Problem.php"
    Then the line showing "<?php" should contain "Mock diagnostic"

  @setting:diagnosticMessages
  Scenario: In menu mode the problem heads the Alt+Enter list
    Given the project configuration:
      """
      {"settings": {"diagnosticMessages": "menu"}}
      """
    And I start the app with the arguments "src/Problem.php"
    And the language server is ready
    And I should see "✗ 1"
    When I put the caret at line 1, column 1
    And I use the action "code_action"
    Then I should see "✗ Mock diagnostic" above "Import class"
    And the line showing "<?php" should not contain "Mock diagnostic"

  Scenario: Cycle Diagnostic Messages moves the message after the code, live
    Given I start the app with the arguments "src/Problem.php"
    And I should see "✗ 1"
    And the line showing "<?php" should not contain "Mock diagnostic"
    When I use the action "cycle_diagnostic_messages"
    Then the line showing "<?php" should contain "Mock diagnostic"
    When I use the action "cycle_diagnostic_messages"
    Then the line showing "<?php" should not contain "Mock diagnostic"
