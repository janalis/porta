Feature: Brackets and auto-pairs
  Typing an opener inserts its closer, a closer types over the one already
  there, and backspacing an empty pair removes both. The autoPairs setting,
  or Toggle Auto-Pairs in the View menu and Find Action, turns that off.
  Ctrl+Shift+M jumps between a bracket and its match.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $x = foo(bar(1), 2);
      """

  Scenario: An opener brings its closer, and a closer types over it
    Given I start the app with the arguments "a.php"
    And the caret is at line 3, column 1
    When I type "f("
    Then the editor should contain "f()"
    When I type "1)"
    Then the editor should contain "f(1)"
    And the editor should not contain "f(1))"

  Scenario: Backspace in an empty pair removes both halves
    Given I start the app with the arguments "a.php"
    And the caret is at line 3, column 1
    When I type "["
    And I use the action "delete_char_backward"
    Then the editor text should be:
      """
      <?php
      $x = foo(bar(1), 2);
      """

  @setting:autoPairs
  Scenario: With autoPairs off an opener is just a character
    Given the project configuration:
      """
      {"settings": {"autoPairs": false}}
      """
    And I start the app with the arguments "a.php"
    And the caret is at line 3, column 1
    When I type "f("
    Then the editor should contain "f("
    And the editor should not contain "f()"

  Scenario: Toggle Auto-Pairs turns the pairing off for the session and saves it
    Given I start the app with the arguments "a.php"
    When I use the action "toggle_auto_pairs"
    And the caret is at line 3, column 1
    And I type "g("
    Then the editor should not contain "g()"
    And the project setting "autoPairs" should be false

  Scenario: Jump to the matching bracket and back
    Given I start the app with the arguments "a.php"
    And the caret is at line 2, column 9
    When I use the action "goto_matching_bracket"
    Then the caret should be at line 2, column 19
    When I use the action "goto_matching_bracket"
    Then the caret should be at line 2, column 9
