Feature: Code completion and parameter info
  The language server proposes what may come at the caret: Ctrl+Space asks for
  it, Enter takes the selected proposal with whatever import it needs, and
  Escape walks away. Inside a call, the signature shows what goes where.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Plain.php" containing:
      """
      <?php

      echo ;
      """
    And I start the app with the arguments "src/Plain.php"
    And the language server is ready

  Scenario: Ctrl+Space lists what the server proposes
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    Then the completion popup should be open
    And I should see "strlen"
    And I should see "str_replace"
    And I should see "sprintf"

  Scenario: Accepting a proposal inserts it, with the import the server resolves
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    And the completion popup should be open
    And I use the action "accept_completion"
    Then the completion popup should be closed
    And the editor should contain "strlen("
    And the editor should contain "use function strlen;"

  Scenario: A snippet proposal lands with its first tab stop selected
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    And the completion popup should be open
    And I press "Down" 3 times
    And I use the action "accept_completion"
    Then line 2 of the editor should be "sprintf(format, args)"
    And the selected text should be "format"

  Scenario: Escape closes the popup and leaves the code alone
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    And the completion popup should be open
    And I use the action "select_cancel"
    Then the completion popup should be closed
    And the editor should not contain "strlen"

  Scenario: Typing an opening parenthesis shows the call's signature
    When I put the caret at line 3, column 6
    And I type "greet("
    Then I should see "greet(string $name): string"

  Scenario: Parameter info brings the signature back after Escape
    When I put the caret at line 3, column 6
    And I type "greet("
    And I should see "greet(string $name): string"
    And I press "Escape"
    And I should not see "greet(string $name): string"
    And I use the action "parameter_info"
    Then I should see "greet(string $name): string"
