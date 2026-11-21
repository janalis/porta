Feature: Find and replace in the file
  Ctrl+F opens the find bar prefilled with the word under the caret and
  searches as you type; F3 and Shift+F3 step through the matches. Ctrl+R adds
  the replace field: Alt+R replaces the current match and Alt+A every one.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $total = 1;
      $other = $total + 2;
      echo $total;
      """
    And I start the app with the arguments "a.php"

  Scenario: Find the word under the caret and step through its matches
    Given the caret is at line 2, column 3
    When I use the action "find"
    Then I should see "[2/3]"
    When I press "F3"
    Then I should see "[3/3]"
    And the caret should be on line 4
    When I press "Shift+F3"
    Then I should see "[2/3]"
    And the caret should be on line 3

  Scenario: Type a query and step through its matches
    Given the caret is at line 1, column 1
    When I use the action "find"
    And I type "echo"
    Then I should see "1/1"
    When I press "Escape"
    Then the caret should be on line 4

  Scenario: Replace every match
    Given the caret is at line 2, column 3
    When I use the action "replace"
    And I press "Tab"
    And I type "$sum"
    And I press "Alt+a"
    Then the editor text should be:
      """
      <?php
      $sum = 1;
      $other = $sum + 2;
      echo $sum;
      """
