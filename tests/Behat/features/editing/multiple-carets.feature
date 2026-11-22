Feature: Multiple carets and column selection
  Carets can be added above and below, at the next occurrence of the word or
  at every occurrence; typing, deleting and pasting apply at each of them as a
  single undo step, and Esc collapses back to one. Alt+C turns on column mode,
  where Shift and the arrows build a rectangle.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      one
      two
      three
      """
    And I start the app with the arguments "a.php"

  Scenario: Add carets below, and type at all of them
    Given the caret is at line 1, column 1
    When I use the action "add_caret_below"
    And I use the action "add_caret_below"
    Then there should be 3 carets
    When I type "#"
    Then the editor text should be:
      """
      #one
      #two
      #three
      """
    When I use the action "undo"
    Then the editor text should be:
      """
      one
      two
      three
      """
    When I press "Escape"
    Then there should be 1 caret

  Scenario: Add carets above
    Given the caret is at line 3, column 1
    When I use the action "add_caret_above"
    And I use the action "add_caret_above"
    And I type "-"
    Then the editor text should be:
      """
      -one
      -two
      -three
      """

  Scenario: Add the next occurrence of the word, then every occurrence
    Given a file "b.php" containing:
      """
      $item = 1;
      $item = 2;
      $item = 3;
      """
    And I open "b.php"
    And the caret is at line 1, column 3
    When I use the action "add_next_occurrence"
    Then there should be 2 carets
    And the caret should be on line 2
    When I use the action "select_all_occurrences"
    Then there should be 3 carets
    When I use the action "delete_word_backward"
    And I type "$row"
    Then the editor text should be:
      """
      $row = 1;
      $row = 2;
      $row = 3;
      """

  Scenario: Column mode selects a rectangle
    Given the caret is at line 1, column 1
    When I use the action "toggle_column_mode"
    Then I should see "COLUMN"
    When I use the action "select_down"
    And I use the action "select_down"
    And I use the action "select_right"
    And I use the action "select_right"
    And I type "X"
    Then the editor text should be:
      """
      Xe
      Xo
      Xree
      """
    When I use the action "toggle_column_mode"
    Then I should not see "COLUMN"
