Feature: Bookmarks
  F11 toggles a bookmark on the caret's line, shown as ◈ in the gutter; Alt+2
  lists every bookmark in the project with its line's text, and picking one goes
  there. Bookmarks live in .porta/bookmarks.json, so they outlast the tab.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $first = 1;
      $marked = 2;
      $last = 3;
      """
    And I start the app with the arguments "a.php"

  Scenario: Toggle a bookmark on and off
    Given the caret is at line 3, column 1
    When I use the action "toggle_bookmark"
    Then I should see "◈"
    When I use the action "toggle_bookmark"
    Then I should not see "◈"

  Scenario: A bookmark outlasts the session
    Given the caret is at line 3, column 1
    And I use the action "toggle_bookmark"
    When I restart the app
    Then I should see "◈"
    And the file ".porta/bookmarks.json" should contain "a.php"

  Scenario: The bookmark list goes to the marked line
    Given the caret is at line 3, column 1
    And I use the action "toggle_bookmark"
    And I put the caret at line 1, column 1
    When I use the action "ide_bookmarks"
    Then I should see "a.php:3  $marked = 2;"
    When I press "Enter"
    Then the caret should be on line 3
