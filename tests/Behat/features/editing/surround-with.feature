Feature: Surround with
  Ctrl+Alt+T offers bracket pairs, quotes and if / foreach / try snippets
  and wraps the selection in the one picked; with expand selection it wraps
  the syntax around the caret in two keys.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $x = foo(bar);
      """
    And I start the app with the arguments "a.php"

  Scenario: Wrap the word under the caret in brackets
    Given the caret is at line 2, column 11
    When I use the action "expand_selection"
    And I use the action "surround_with"
    Then I should see "Surround With"
    And I should see "[ ] Brackets"
    When I press "Down"
    And I press "Enter"
    Then the editor should contain "$x = foo([bar]);"
    And I should see "foo([bar])"

  Scenario: Wrap a statement in an if block
    Given I select from line 2, column 1 to line 2, column 15
    When I use the action "surround_with"
    And I type "if"
    And I press "Enter"
    Then the editor should contain "if ($x = foo(bar);) {"
